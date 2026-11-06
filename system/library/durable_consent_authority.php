<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Durable authority at every effect boundary. No current configuration can repair absent/invalid evidence. */
final class DurableConsentAuthority
{
    public function __construct(private DbConnection $db, private ?PersistenceClock $clock = null)
    {
        $this->clock ??= new PersistenceClock();
    }

    public function row(int $attemptId): array
    {
        $table = $this->table();
        $result = $this->db->query("SELECT * FROM `{$table}` WHERE `attempt_id` = {$attemptId} LIMIT 1");
        if (!is_object($result) || (int) ($result->num_rows ?? 0) !== 1) throw ConsentResolver::refusal();
        return $result->row;
    }

    public function requireFinalized(int $attemptId, ?ValidatedFinancingSubmission $submission = null, ?int $orderId = null, ?int $process = null): array
    {
        $row = $this->row($attemptId);
        CpOriginGuard::assertAttempt($row);
        $record = ConsentContext::decode($row['consent_context_json'] ?? null);
        ConsentContext::assertBinding($record, $row);
        if ($record['finalized'] === null) throw ConsentResolver::refusal();
        $c = $record['issued']['context'];
        if ($orderId !== null && (int) ($row['order_id'] ?? 0) !== $orderId) throw ConsentResolver::refusal();
        if ($process !== null && $c['process'] !== $process) throw ConsentResolver::refusal();
        $nativeProducts = $orderId === null ? null : $this->assertNativeCommerce($c, $orderId);
        if ($submission !== null) self::assertSubmission($record, $row, $submission);
        if ($orderId !== null && is_string($row['cp_payload'] ?? null) && $row['cp_payload'] !== '') {
            try { $payload = json_decode($row['cp_payload'], true, 64, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw ConsentResolver::refusal(); }
            if (!is_array($payload)) throw ConsentResolver::refusal();
            self::compareCpPayload($c['conditions'], $payload, $nativeProducts ?? [], $orderId);
        }
        return $record;
    }

    /** Fresh durable proof, including the caller's actor/attempt binding; never acquires a lease. */
    public function requireMaterialization(FinancingAttemptContext $attempt, ValidatedFinancingSubmission $submission): void
    {
        $record = $this->requireFinalized($attempt->attemptId(), $submission);
        ConsentContext::assertBinding($record, $attempt->row());
        $draft = $submission->orderDraft;
        if ($draft->storeId !== $submission->storeId || $draft->currencyCode !== 'EUR' || !is_finite($draft->orderTotal)) {
            throw ConsentResolver::refusal();
        }
        try {
            $eurTotal = EurFinancingAmount::fromOrder($draft->orderTotal, $draft->currencyCode, $draft->currencyId, $draft->currencyValue);
        } catch (\InvalidArgumentException) {
            throw ConsentResolver::refusal();
        }
        if (abs($eurTotal - $record['issued']['context']['conditions']['price']) > 0.01) {
            throw ConsentResolver::refusal();
        }
    }

    /** Independent of the status store implementation; persisted receipt/order/process are authority. */
    public function assertStatus(int $attemptId, ?string $orderReference = null, ?string $statusId = null): void
    {
        $row = $this->row($attemptId);
        $record = $this->requireFinalized($attemptId, null, (int) ($row['order_id'] ?? 0));
        if ($orderReference !== null && $orderReference !== substr((string) $row['order_id'], 0, 13)) throw ConsentResolver::refusal();
        $process = $record['issued']['context']['process'];
        if (($statusId === BankStatus::SENT_PROCESS1 && $process !== 1) || ($statusId === BankStatus::SENT_PROCESS2 && $process !== 2)) throw ConsentResolver::refusal();
        (new DurableEurOrderGuard($this->db))->prove($attemptId, (int) $row['store_id'], (int) $row['order_id'], true);
    }

    private function assertNativeCommerce(array $context, int $orderId): array
    {
        $prefix = $this->db->getPrefix();
        $result = $this->db->query("SELECT `order_product_id`, `product_id`, `quantity`, `price`, `total`, `tax` FROM `{$prefix}order_product` WHERE `order_id` = {$orderId} ORDER BY `order_product_id` ASC");
        $products = is_object($result) ? ($result->rows ?? []) : [];
        if ($products === []) throw ConsentResolver::refusal();
        foreach ($products as &$product) {
            $options = $this->db->query("SELECT `product_option_id`, `product_option_value_id`, `value`, `type` FROM `{$prefix}order_option` WHERE `order_id` = {$orderId} AND `order_product_id` = " . (int) ($product['order_product_id'] ?? 0));
            $product['option'] = is_object($options) ? ($options->rows ?? []) : [];
        }
        unset($product);
        if ($context['commerce_hash'] !== ConsentContext::commerce($products)) throw ConsentResolver::refusal();
        return $products;
    }

    public static function assertSubmission(array $record, array $row, ValidatedFinancingSubmission $s): void
    {
        ConsentContext::assertBinding($record, $row);
        $b = $record['issued']['binding'];
        $c = $record['issued']['context'];
        if ($s->storeId !== $b['store_id'] || $s->entryPoint !== $b['entry_point'] || $s->submissionToken !== $b['submission_token']
            || $s->operationKeyHash !== $b['operation_key_hash'] || $s->selectionHash !== $b['selection_hash'] || $s->shopUnicid !== $b['unicid']
            || ($s->existingOrderId !== $c['existing_order_id'] && $s->submissionSource !== 'resume')
            || $c['commerce_hash'] !== ConsentContext::commerce($s->orderDraft->products)
            || ConsentContext::hash(ConsentContext::fromCalculation($s->financingCalculation)) !== ConsentContext::hash($c['conditions'])) throw ConsentResolver::refusal();
    }

    /** Called before sensitive preparation, order writes or network. Its lease is released before materialization acquires its lease. */
    public function finalizeSubmission(int $attemptId, ValidatedFinancingSubmission $s, mixed $accepted, string $owner, ?int $process = null, ?array $currentShop = null): array
    {
        $locks = new OperationLockRepository($this->db, $this->clock);
        if (!$locks->acquire($s->storeId, $s->entryPoint, $s->operationKeyHash, $owner)) throw ConsentResolver::refusal();
        $transaction = false;
        try {
            $this->db->query('START TRANSACTION');
            $transaction = true;
            $row = $this->row($attemptId);
            CpOriginGuard::assertAttempt($row);
            $record = ConsentContext::decode($row['consent_context_json'] ?? null);
            self::assertSubmission($record, $row, $s);
            if ($process !== null && $record['issued']['context']['process'] !== $process) throw ConsentResolver::refusal();
            $this->assertNoCompetitor($row);
            if ($record['finalized'] !== null) {
                $this->db->query('COMMIT');
                $transaction = false;
                return $record;
            }
            if ($currentShop === null && $record['issued']['context']['has_scoped']) throw ConsentResolver::refusal();
            if ($currentShop !== null) {
                $currentSeed = ConsentContext::seed($currentShop, ConsentContext::fromCalculation($s->financingCalculation), $s->existingOrderId, ConsentContext::commerce($s->orderDraft->products));
                if (!$currentSeed['has_scoped'] && !$record['issued']['context']['has_scoped']) $currentSeed['interest'] = $record['issued']['context']['interest'];
                if (ConsentContext::hash($currentSeed) !== ConsentContext::hash($record['issued']['context'])) throw ConsentResolver::refusal();
            }
            if (!in_array($row['state'] ?? null, [FinancingAttemptState::ISSUED, FinancingAttemptState::VALIDATING], true)
                || !empty($row['order_id']) || strtotime($row['expires_at'] . ' UTC') <= $this->clock->now()) throw ConsentResolver::refusal();
            $raw = (string) $row['consent_context_json'];
            $final = ConsentContext::finalize($raw, $attemptId, $accepted, $this->clock->formatUtc($this->clock->now()));
            $where = '';
            foreach (['submission_token', 'actor_binding_hash', 'operation_key_hash', 'selection_hash', 'entry_point', 'unicid', 'created_at', 'expires_at'] as $key) {
                $where .= " AND BINARY `{$key}` = BINARY '" . $this->db->escape((string) $row[$key]) . "'";
            }
            $this->db->query("UPDATE `{$this->table()}` SET `consent_context_json` = '" . $this->db->escape($final) . "'
                WHERE `attempt_id` = {$attemptId} AND `store_id` = " . (int) $row['store_id'] . " AND `order_id` IS NULL
                AND `state` IN ('issued', 'validating') AND BINARY `consent_context_json` = BINARY '" . $this->db->escape($raw) . "'" . $where);
            if ($this->db->countAffected() !== 1) throw ConsentResolver::refusal();
            $this->db->query('COMMIT');
            $transaction = false;
            return ConsentContext::decode($final);
        } catch (\Throwable $error) {
            if ($transaction) { try { $this->db->query('ROLLBACK'); } catch (\Throwable) {} }
            // COMMIT/update may have succeeded despite a lost acknowledgement. Only persisted finalized A can authorize continuation.
            try {
                $record = $this->requireFinalized($attemptId, $s, null, $process);
                $this->assertNoCompetitor($this->row($attemptId));
                return $record;
            } catch (\Throwable) { throw ConsentResolver::refusal(); }
        } finally {
            $locks->release($s->storeId, $s->entryPoint, $s->operationKeyHash, $owner);
        }
    }

    private function assertNoCompetitor(array $row): void
    {
        $sql = "SELECT * FROM `{$this->table()}` WHERE `store_id` = " . (int) $row['store_id']
            . " AND `entry_point` = '" . $this->db->escape($row['entry_point']) . "' AND `operation_key_hash` = '" . $this->db->escape($row['operation_key_hash']) . "'";
        if ($row['entry_point'] !== OperationEntryPoint::CHECKOUT) $sql .= " AND `actor_binding_hash` = '" . $this->db->escape($row['actor_binding_hash']) . "'";
        $result = $this->db->query($sql . ' ORDER BY `attempt_id` ASC FOR UPDATE');
        foreach (is_object($result) ? ($result->rows ?? []) : [] as $other) {
            if ((int) $other['attempt_id'] === (int) $row['attempt_id']) continue;
            $kind = ConsentContext::classify($other['consent_context_json'] ?? null);
            // Unprovable authority must not be bypassed with a new token.
            if ($kind === 'malformed' || ($kind === 'legacy_absent' && (!empty($other['order_id']) || !in_array($other['state'] ?? '', [FinancingAttemptState::ISSUED, FinancingAttemptState::VALIDATING], true)))) throw ConsentResolver::refusal();
            if (in_array($kind, ['issued', 'finalized'], true)) ConsentContext::assertBinding(ConsentContext::decode($other['consent_context_json']), $other);
            if ($kind !== 'finalized') continue;
            $overlap = $other['created_at'] <= $row['expires_at'] && $other['expires_at'] >= $row['created_at'];
            $resolved = ($other['smartucf_state'] ?? '') === SmartUcfLifecycleStates::CREATED || ($other['process2_state'] ?? '') === ProcessTwoLifecycleStates::PREPARED
                || (($other['state'] ?? '') === FinancingAttemptState::TERMINAL_FAILED && ($other['last_error_class'] ?? '') === ControlPanelErrorClass::REJECTED)
                || (($other['smartucf_state'] ?? '') === SmartUcfLifecycleStates::FAILED && empty($other['smartucf_retryable']));
            if ($row['entry_point'] === OperationEntryPoint::CHECKOUT || $overlap || !$resolved) throw ConsentResolver::refusal();
        }
    }

    public function assertCpPayload(int $attemptId, array $payload, ValidatedFinancingSubmission $s, int $orderId): void
    {
        $r = $this->requireFinalized($attemptId, $s, $orderId);
        self::compareCpPayload($r['issued']['context']['conditions'], $payload, $s->orderDraft->products, $orderId);
    }

    private static function compareCpPayload(array $c, array $payload, array $products, int $orderId): void
    {
        foreach (['price' => 'financed_amount', 'vnoska' => 'monthly_installment', 'gpr' => 'gpr', 'parva' => 'first_installment'] as $key => $condition) {
            if (!is_numeric($payload[$key] ?? null) || (float) $payload[$key] !== round($c[$condition], 2)) throw ConsentResolver::refusal();
        }
        $ids = array_map(static fn(array $p): int => (int) $p['product_id'], $products);
        $quantities = array_map(static fn(array $p): int => (int) $p['quantity'], $products);
        if (($payload['vnoski'] ?? null) !== $c['months'] || ($payload['order_id'] ?? null) !== (string) $orderId || ($payload['currency'] ?? null) !== 'EUR'
            || ($payload['products_id'] ?? null) !== implode('_', $ids) || ($payload['products_q'] ?? null) !== implode('_', $quantities)) throw ConsentResolver::refusal();
    }

    /** Legacy read-only retrieval is allowed only for an already recorded outcome; callers must return without effects. */
    public function legacyRecorded(int $attemptId): ?array
    {
        $row = $this->row($attemptId);
        $kind = ConsentContext::classify($row['consent_context_json'] ?? null);
        if ($kind === 'malformed') throw ConsentResolver::refusal();
        if ($kind !== 'legacy_absent') return null;
        if (($row['smartucf_state'] ?? '') === SmartUcfLifecycleStates::CREATED || ($row['process2_state'] ?? '') === ProcessTwoLifecycleStates::PREPARED) return $row;
        throw ConsentResolver::refusal();
    }

    public function shopForContinuation(int $attemptId, array $shop): array
    {
        $row = $this->row($attemptId);
        CpOriginGuard::assertAttempt($row);
        $legacy = $this->legacyRecorded($attemptId);
        if ($legacy !== null) {
            $process = ($legacy['smartucf_state'] ?? '') === SmartUcfLifecycleStates::CREATED ? 1 : 2;
        } else {
            $process = $this->requireFinalized($attemptId)['issued']['context']['process'];
        }
        $shop['uni_proces'] = $process === 2 ? 1 : 0;
        return $shop;
    }

    private function table(): string { return $this->db->getPrefix() . PersistenceTableNames::FINANCING_ATTEMPT; }
}
