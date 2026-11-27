<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Proves the bound native order and saved CP request before remote work or success replay. */
final class DurableEurOrderGuard
{
    public function __construct(private DbConnection $db)
    {
    }

    public function prove(int $attemptId, int $storeId, int $orderId, bool $requireCpSuccess = false): DurableEurOrderProof
    {
        if ($attemptId <= 0 || $orderId <= 0 || $storeId < 0) {
            throw self::failure();
        }
        $attemptTable = $this->db->getPrefix() . PersistenceTableNames::FINANCING_ATTEMPT;
        $attemptResult = $this->db->query("SELECT * FROM `{$attemptTable}` WHERE `attempt_id` = " . $attemptId . ' LIMIT 1');
        if (!is_object($attemptResult) || (int) ($attemptResult->num_rows ?? 0) !== 1) {
            throw self::failure();
        }
        $attempt = $attemptResult->row;
        CpOriginGuard::assertAttempt($attempt);
        if ((int) ($attempt['store_id'] ?? -1) !== $storeId || (int) ($attempt['order_id'] ?? 0) !== $orderId) {
            throw self::failure();
        }
        $orderTable = $this->db->getPrefix() . 'order';
        $orderResult = $this->db->query("SELECT `order_id`, `store_id`, `total`, `currency_code`, `currency_id`, `currency_value` FROM `{$orderTable}` WHERE `order_id` = " . $orderId . ' LIMIT 1');
        if (!is_object($orderResult) || (int) ($orderResult->num_rows ?? 0) !== 1) {
            throw self::failure();
        }
        $order = $orderResult->row;
        $code = (string) ($order['currency_code'] ?? '');
        $currencyId = (int) ($order['currency_id'] ?? 0);
        $value = (float) ($order['currency_value'] ?? 0.0);
        $baseTotal = (float) ($order['total'] ?? NAN);
        if ((int) ($order['store_id'] ?? -1) !== $storeId || !(new CurrencyGate())->supports($code)
            || $currencyId <= 0 || !is_finite($value) || $value <= 0.0
            || !is_finite($baseTotal) || $baseTotal <= 0.0) {
            throw self::failure();
        }
        $currencyTable = $this->db->getPrefix() . 'currency';
        $currencyResult = $this->db->query("SELECT `code` FROM `{$currencyTable}` WHERE `currency_id` = " . $currencyId . ' LIMIT 1');
        if (!is_object($currencyResult) || (int) ($currencyResult->num_rows ?? 0) !== 1
            || (string) ($currencyResult->row['code'] ?? '') !== 'EUR') {
            throw self::failure();
        }
        $proof = new DurableEurOrderProof(
            $orderId,
            $storeId,
            $currencyId,
            $value,
            $baseTotal,
            EurFinancingAmount::fromOrder($baseTotal, $code, $currencyId, $value)
        );
        $rawPayload = $attempt['cp_payload'] ?? null;
        $cpId = (int) ($attempt['control_panel_order_id'] ?? 0);
        $cpCreated = (string) ($attempt['state'] ?? '') === FinancingAttemptState::CP_CREATED;
        if ($cpCreated && $cpId <= 0) {
            throw self::failure();
        }
        if ($requireCpSuccess && (!$cpCreated || $cpId <= 0)) {
            throw self::failure();
        }
        if ($cpCreated || $requireCpSuccess || $rawPayload !== null) {
            if (!is_string($rawPayload) || $rawPayload === '') {
                throw self::failure();
            }
            try {
                $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw self::failure();
            }
            if (!is_array($payload)) {
                throw self::failure();
            }
            if (($attempt['consent_context_json'] ?? null) !== null) {
                $record = (new DurableConsentAuthority($this->db))->requireFinalized($attemptId, null, $orderId);
                self::assertCpPayload($payload, $proof, $record['issued']['context']['conditions']['price']);
            } elseif ($cpCreated) {
                $snapshotRaw = $attempt['leasing_presentation_json'] ?? null;
                if (!is_string($snapshotRaw) || $snapshotRaw === '') {
                    throw self::failure();
                }
                try {
                    $snapshot = json_decode($snapshotRaw, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw self::failure();
                }
                if (!is_array($snapshot)
                    || (int) ($snapshot['shop_order_id'] ?? 0) !== $orderId
                    || (isset($snapshot['control_panel_order_id']) && (int) $snapshot['control_panel_order_id'] > 0
                        && (int) $snapshot['control_panel_order_id'] !== $cpId)
                    || !self::matchesLegacyTerms($payload, $snapshot)) {
                    throw self::failure();
                }
                $gross = (float) $snapshot['financed_amount'] + (float) $snapshot['first_installment'];
                self::assertCpPayload($payload, $proof, $gross);
            } else {
                throw self::failure();
            }
        }

        return $proof;
    }

    /** @param array<string, mixed> $payload */
    public static function assertCpPayload(array $payload, DurableEurOrderProof $proof, ?float $authorizedGross = null): void
    {
        $price = $payload['price'] ?? null;
        $first = $payload['parva'] ?? null;
        $monthly = $payload['vnoska'] ?? null;
        if (($payload['currency'] ?? null) !== 'EUR'
            || (string) ($payload['order_id'] ?? '') !== substr((string) $proof->orderId, 0, 13)
            || !is_numeric($price) || !is_numeric($first) || !is_numeric($monthly)
            || !is_finite((float) $price) || !is_finite((float) $first) || !is_finite((float) $monthly)
            || (float) $price <= 0.0 || (float) $first < 0.0 || (float) $monthly <= 0.0
            || !self::withinGrossTolerance($authorizedGross ?? (float) $price, $proof->eurTotal)) {
            throw self::failure();
        }
    }

    private static function matchesLegacyTerms(array $payload, array $terms): bool
    {
        foreach (['financed_amount', 'first_installment', 'monthly_installment'] as $field) {
            if (!is_numeric($terms[$field] ?? null) || !is_finite((float) $terms[$field])) {
                return false;
            }
        }

        // Pre-consent records have no separately frozen gross and retain their principal convention.
        return self::matchesAmount($payload['price'] ?? null, (float) $terms['financed_amount'])
            && self::matchesAmount($payload['parva'] ?? null, (float) $terms['first_installment'])
            && self::matchesAmount($payload['vnoska'] ?? null, (float) $terms['monthly_installment'])
            && (!isset($terms['gpr']) || self::matchesAmount($payload['gpr'] ?? null, (float) $terms['gpr']))
            && (!isset($terms['months']) || ($payload['vnoski'] ?? null) === (int) $terms['months']);
    }

    public static function withinGrossTolerance(float $gross, float $savedGross): bool
    {
        $grossCents = round($gross * 100);
        $savedCents = round($savedGross * 100);

        return is_finite($grossCents) && is_finite($savedCents)
            && abs($grossCents) < PHP_INT_MAX && abs($savedCents) < PHP_INT_MAX
            && abs((int) $grossCents - (int) $savedCents) <= 2;
    }

    public static function matchesAmount(mixed $amount, float $expected): bool
    {
        return is_numeric($amount) && is_finite((float) $amount) && (float) $amount === round($expected, 2);
    }

    public static function failure(): ProductFinancingFlowException
    {
        return new ProductFinancingFlowException(
            'currency_unavailable',
            'Финансирането не е налично за валутата или данните на тази поръчка.'
        );
    }
}
