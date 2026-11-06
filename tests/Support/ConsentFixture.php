<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\ConsentContext;
use Opencart\System\Library\Extension\MtUniCredit\CpOriginGuard;
use Opencart\System\Library\Extension\MtUniCredit\ValidatedFinancingSubmission;

/** Explicit server-issued/finalized evidence for offline successes, never used by production. */
final class ConsentFixture
{
    /** Complete offline persisted order/receipt proof for alternate status stores. */
    public static function statusDatabase(int $attemptId, int $orderId, int $process = 1): ConsentFakeDb
    {
        $db = new ConsentFakeDb();
        $s = OrderMaterializationTestHarness::productSubmission();
        $db->rows[$attemptId] = self::row($s, ['attempt_id'=>$attemptId, 'order_id'=>$orderId, 'state'=>'cp_created', 'control_panel_order_id'=>99], true, ['uni_proces'=>$process === 2 ? 1 : 0]);
        $db->rows[$attemptId]['cp_payload'] = json_encode(['order_id'=>(string)$orderId, 'currency'=>'EUR', 'price'=>1200.0, 'parva'=>0.0, 'vnoska'=>100.0, 'vnoski'=>12, 'gpr'=>0.0, 'products_id'=>'42', 'products_q'=>'2']);
        $db->orders[$orderId] = ['order_id'=>$orderId, 'store_id'=>$s->storeId, 'total'=>1200.0, 'currency_code'=>'EUR', 'currency_id'=>1, 'currency_value'=>1.0];
        $db->orderLines[$orderId] = $s->orderDraft->products;
        return $db;
    }

    public static function statusAuthority(int $attemptId, int $orderId, int $process = 1): \Opencart\System\Library\Extension\MtUniCredit\DurableConsentAuthority
    {
        return new \Opencart\System\Library\Extension\MtUniCredit\DurableConsentAuthority(self::statusDatabase($attemptId, $orderId, $process));
    }

    public static function seedForEntry(string $entry, ?ValidatedFinancingSubmission $s = null): array
    {
        $s ??= OrderMaterializationTestHarness::productSubmission($entry, $entry === 'checkout' ? 1 : null);
        return ConsentContext::seed(ProductFinancingTestHarness::shop(), ConsentContext::fromCalculation($s->financingCalculation), $entry === 'checkout' ? ($s->existingOrderId ?? 1) : null, ConsentContext::commerce($s->orderDraft->products));
    }

    public static function persistFinalized(array $row, ValidatedFinancingSubmission $s): array
    {
        $s->submissionToken = $row['submission_token'];
        $s->operationKeyHash = $row['operation_key_hash'];
        $s->selectionHash = $row['selection_hash'];
        $s->shopUnicid = $row['unicid'];
        $s->entryPoint = $row['entry_point'];
        $db = PersistenceIntegrationHarness::connection();
        $record = ConsentContext::decode($row['consent_context_json'] ?? null);
        (new \Opencart\System\Library\Extension\MtUniCredit\DurableConsentAuthority($db))->finalizeSubmission(
            (int) $row['attempt_id'], $s, $record['issued']['context']['mandatory_ids'],
            \Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator::generate()
        );
        return (new \Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository($db))->findById((int) $row['attempt_id']);
    }

    public static function row(ValidatedFinancingSubmission $s, array $overrides = [], bool $finalized = true, array $shop = []): array
    {
        $row = $overrides + [
            'attempt_id' => 1, 'store_id' => $s->storeId, 'entry_point' => $s->entryPoint,
            'submission_token' => $s->submissionToken, 'operation_key_hash' => $s->operationKeyHash,
            'actor_binding_hash' => hash('sha256', 'test-actor'), 'selection_hash' => $s->selectionHash,
            'unicid' => $s->shopUnicid, 'cp_origin' => CpOriginGuard::current(),
            'created_at' => '2026-01-01 00:00:00', 'expires_at' => '2099-01-01 00:00:00', 'state' => 'issued', 'order_id' => null,
        ];
        $binding = array_intersect_key($row, array_flip(['store_id', 'entry_point', 'submission_token', 'operation_key_hash', 'actor_binding_hash', 'selection_hash', 'unicid', 'created_at', 'expires_at']));
        // Freeze the expected UNICID; tests deliberately corrupt the persisted row separately.
        $binding['unicid'] = $s->shopUnicid;
        $seed = ConsentContext::seed($shop, ConsentContext::fromCalculation($s->financingCalculation), $s->existingOrderId, ConsentContext::commerce($s->orderDraft->products));
        $raw = ConsentContext::issue($seed, $binding);
        $row['consent_context_json'] = $finalized ? ConsentContext::finalize($raw, (int) $row['attempt_id'], $seed['mandatory_ids'], gmdate('Y-m-d H:i:s', strtotime($row['created_at'] . ' UTC') + 1)) : $raw;
        return $row;
    }
}
