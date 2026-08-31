<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Transient Product popup „Купи“ handoff into Checkout (payment + scheme preference).
 *
 * Not a financing attempt — only UX preselection after native cart.add.
 */
final class ProductBuyCheckoutPreference
{
    public const SESSION_KEY = 'mt_uni_credit_product_buy_preference';

    public const FLOW = 'product_buy';

    public const TTL_SECONDS = 1800;

    /**
     * @param array<string, mixed> $sessionData
     * @param array{
     *     product_id:int,
     *     scheme_type:string,
     *     kop_code:string,
     *     months:int,
     *     filter_id:int,
     *     first_installment?:float|int|string
     * } $selection
     */
    public static function save(array &$sessionData, int $storeId, array $selection): void
    {
        $sessionData[self::SESSION_KEY] = [
            'flow'              => self::FLOW,
            'source'            => 'product_buy',
            'store_id'          => $storeId,
            'product_id'        => (int) ($selection['product_id'] ?? 0),
            'scheme_type'       => (string) ($selection['scheme_type'] ?? ''),
            'kop_code'          => (string) ($selection['kop_code'] ?? ''),
            'months'            => (int) ($selection['months'] ?? 0),
            'filter_id'         => (int) ($selection['filter_id'] ?? 0),
            'first_installment' => (float) ($selection['first_installment'] ?? 0),
            'prefer_payment'    => true,
            'created_at'        => time(),
        ];
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return array<string, mixed>|null
     */
    public static function load(array &$sessionData, int $storeId): ?array
    {
        $raw = $sessionData[self::SESSION_KEY] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        if ((string) ($raw['flow'] ?? '') !== self::FLOW) {
            self::clear($sessionData);

            return null;
        }

        if ((int) ($raw['store_id'] ?? -1) !== $storeId) {
            self::clear($sessionData);

            return null;
        }

        $createdAt = (int) ($raw['created_at'] ?? 0);
        if ($createdAt <= 0 || (time() - $createdAt) > self::TTL_SECONDS) {
            self::clear($sessionData);

            return null;
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    public static function clear(array &$sessionData): void
    {
        unset($sessionData[self::SESSION_KEY]);
    }

    /**
     * Business identity match: scheme_type + kop_code + months; prefer exact filter_id.
     *
     * @param list<array<string, mixed>> $schemes Presenter scheme rows with key/scheme_type/kop_code/months/filter_id
     * @param array<string, mixed> $preference
     */
    public static function resolveSchemeKey(array $schemes, array $preference): ?string
    {
        $candidates = [];
        foreach ($schemes as $scheme) {
            if (!is_array($scheme)) {
                continue;
            }
            if ((string) ($scheme['scheme_type'] ?? '') !== (string) ($preference['scheme_type'] ?? '')) {
                continue;
            }
            if ((string) ($scheme['kop_code'] ?? '') !== (string) ($preference['kop_code'] ?? '')) {
                continue;
            }
            if ((int) ($scheme['months'] ?? 0) !== (int) ($preference['months'] ?? 0)) {
                continue;
            }
            $candidates[] = $scheme;
        }
        if ($candidates === []) {
            return null;
        }

        $wantFilter = (int) ($preference['filter_id'] ?? 0);
        foreach ($candidates as $scheme) {
            if ((int) ($scheme['filter_id'] ?? -1) === $wantFilter) {
                $key = (string) ($scheme['key'] ?? '');

                return $key !== '' ? $key : null;
            }
        }

        $key = (string) ($candidates[0]['key'] ?? '');

        return $key !== '' ? $key : null;
    }

    /**
     * Apply UniCredit payment method into session when listed in discovered methods.
     *
     * @param array<string, mixed> $sessionData
     * @param array<string, mixed> $paymentMethods Native session.payment_methods shape
     */
    public static function applyPaymentIfAvailable(array &$sessionData, array $paymentMethods, int $storeId): bool
    {
        $preference = self::load($sessionData, $storeId);
        if ($preference === null || empty($preference['prefer_payment'])) {
            return false;
        }

        $code = ModuleConstants::PAYMENT_OPTION_CODE;
        $parts = explode('.', $code, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$extension, $option] = $parts;
        $row = $paymentMethods[$extension]['option'][$option] ?? null;
        if (!is_array($row) || (string) ($row['code'] ?? '') !== $code) {
            // UniCredit unavailable for current cart — do not fake-select.
            if (PaymentIdentity::matchesStoredPayment($sessionData['payment_method'] ?? null)) {
                unset($sessionData['payment_method']);
            }
            $preference['prefer_payment'] = false;
            $sessionData[self::SESSION_KEY] = $preference;

            return false;
        }

        $sessionData['payment_method'] = $row;

        return true;
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    public static function clearIfPaymentChangedAway(array &$sessionData): void
    {
        if (!isset($sessionData[self::SESSION_KEY])) {
            return;
        }
        if (PaymentIdentity::matchesStoredPayment($sessionData['payment_method'] ?? null)) {
            return;
        }
        self::clear($sessionData);
    }
}
