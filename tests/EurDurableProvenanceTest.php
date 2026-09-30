<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use Opencart\System\Library\Extension\MtUniCredit\DurableEurOrderGuard;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceTableNames;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationSnapshotValidator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

final class EurDurableProvenanceTest extends TestCase
{
    public function testSnapshotCurrencyModeIsIgnoredForAbsentTemporaryAndHistoricalValues(): void
    {
        $validator = new ShopConfigurationSnapshotValidator();
        $base = mt_uni_credit_valid_shop_snapshot();
        foreach ([null, 3, 0, 1, 99, 'obsolete'] as $mode) {
            $shop = $base;
            unset($shop['uni_eur']);
            if ($mode !== null) {
                $shop['uni_eur'] = $mode;
            }
            $validator->validate($shop);
            self::assertTrue(true);
        }
    }

    public function testSavedEurFactorAndPayloadAreRequiredForReplay(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration database is unavailable.');
        }
        PersistenceIntegrationHarness::resetTables();
        $submission = OrderMaterializationTestHarness::productSubmission();
        $db = PersistenceIntegrationHarness::connection();
        $attempts = new FinancingAttemptRepository($db);
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId, $submission->entryPoint,
            hash('sha256', 'eur-provenance-operation'), hash('sha256', 'eur-provenance-actor'),
            hash('sha256', 'eur-provenance-selection'), PersistenceIntegrationHarness::TEST_UNICID
        );
        $attemptId = (int) $row['attempt_id'];
        $orderId = 99123;
        $attempts->attachOrder($attemptId, $orderId);
        PersistenceIntegrationHarness::seedSuccessfulEurAttempt($attemptId, $orderId, $submission, 500);
        PersistenceIntegrationHarness::seedNativeOrder($orderId, $submission->storeId, 1200.0, 'EUR', 1, 0.5);
        $table = $db->getPrefix() . PersistenceTableNames::FINANCING_ATTEMPT;
        $db->query("UPDATE `{$table}` SET `cp_payload` = '" . $db->escape(json_encode([
            'order_id' => (string) $orderId, 'currency' => 'EUR',
            'price' => 600.0, 'parva' => 0.0, 'vnoska' => 50.0,
        ], JSON_THROW_ON_ERROR)) . "', `leasing_presentation_json` = '" . $db->escape(json_encode([
            'shop_order_id' => $orderId, 'financed_amount' => 600.0,
            'first_installment' => 0.0, 'monthly_installment' => 50.0,
        ], JSON_THROW_ON_ERROR)) . "' WHERE `attempt_id` = {$attemptId}");
        $guard = new DurableEurOrderGuard($db);
        self::assertSame(600.0, $guard->prove($attemptId, $submission->storeId, $orderId, true)->eurTotal);

        foreach ([
            ['EUR', 2, 0.5], ['BGN', 2, 0.5], ['EUR', 1, 0.0],
        ] as [$code, $id, $factor]) {
            PersistenceIntegrationHarness::seedNativeOrder($orderId, $submission->storeId, 1200.0, $code, $id, $factor);
            try {
                $guard->prove($attemptId, $submission->storeId, $orderId, true);
                self::fail('Invalid durable currency provenance was accepted.');
            } catch (ProductFinancingFlowException $expected) {
                self::assertSame('currency_unavailable', $expected->errorCode());
            }
        }
        PersistenceIntegrationHarness::seedNativeOrder($orderId, $submission->storeId, 1200.0, 'EUR', 1, 0.5);
        foreach ([null, ['currency' => 'BGN', 'order_id' => (string) $orderId, 'price' => 600, 'parva' => 0, 'vnoska' => 50]] as $bad) {
            $encoded = $bad === null ? 'NULL' : "'" . $db->escape(json_encode($bad, JSON_THROW_ON_ERROR)) . "'";
            $db->query("UPDATE `{$table}` SET `cp_payload` = {$encoded} WHERE `attempt_id` = {$attemptId}");
            try {
                $guard->prove($attemptId, $submission->storeId, $orderId, true);
                self::fail('Unverifiable CP success was accepted.');
            } catch (ProductFinancingFlowException $expected) {
                self::assertSame('currency_unavailable', $expected->errorCode());
            }
        }
    }
}
