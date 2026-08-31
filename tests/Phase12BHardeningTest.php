<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use Opencart\System\Library\Extension\MtUniCredit\EventRegistry;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationSnapshot;
use Opencart\System\Library\Extension\MtUniCredit\ModuleEncryptionKeyProvider;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceClock;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceTableNames;
use Opencart\System\Library\Extension\MtUniCredit\ProductBuyCheckoutPreference;
use Opencart\System\Library\Extension\MtUniCredit\SecurityConstants;
use PHPUnit\Framework\TestCase;

/**
 * Phase 12B — release hardening regressions (retention, IDOR, multistore, cookie, events).
 */
final class Phase12BHardeningTest extends TestCase
{
    private const BASE_TIME = 1_750_000_000;

    public function testThankYouControllerDoesNotTrustGetOrderId(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_checkout_success.php'
        );
        self::assertStringNotContainsString("\$this->request->get['order_id']", $src);
        self::assertStringContainsString('mt_uni_credit_success_order_id', $src);
        self::assertStringContainsString('isCustomerPresentationSafe', $src);
    }

    public function testProductBuyCookieFailsClosedWithoutProductionSecret(): void
    {
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'standard',
            'kop_code'    => 'K',
            'months'      => 4,
            'filter_id'   => 0,
        ]);
        $pref = ProductBuyCheckoutPreference::load($session, 0);
        self::assertNotNull($pref);

        if (defined('DB_PASSWORD') && \DB_PASSWORD !== '') {
            self::markTestSkipped('DB_PASSWORD is set in this environment; fail-closed path requires empty secret.');
        }

        self::assertSame('', ProductBuyCheckoutPreference::buildHandoffCookieValue($pref));
        self::assertNull(ProductBuyCheckoutPreference::parseHandoffCookieValue('body.sig', 0));
        self::assertNotNull(ProductBuyCheckoutPreference::load($session, 0));
    }

    public function testHandoffSecretHasNoPredictableTestFallback(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/product_buy_checkout_preference.php'
        );
        self::assertStringNotContainsString('testSecretInput()', $src);
        self::assertStringContainsString('canUseHandoffSecret', $src);
    }

    public function testProductBuyCookieWorksWithExplicitTestSecret(): void
    {
        $secret = ModuleEncryptionKeyProvider::testSecretInput();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'standard',
            'kop_code'    => 'K',
            'months'      => 4,
            'filter_id'   => 0,
        ]);
        $pref = ProductBuyCheckoutPreference::load($session, 0);
        self::assertNotNull($pref);
        $cookie = ProductBuyCheckoutPreference::buildHandoffCookieValue($pref, $secret);
        self::assertNotSame('', $cookie);
        self::assertNotNull(ProductBuyCheckoutPreference::parseHandoffCookieValue($cookie, 0, $secret));
    }

    public function testRetiredEventCodesAreManaged(): void
    {
        $managed = EventRegistry::managedEventCodes();
        self::assertContains('module_mt_uni_credit_after_checkout_success', $managed);
        self::assertContains('module_mt_uni_credit_before_shipping_method_save_buy', $managed);
        self::assertNotContains('module_mt_uni_credit_before_shipping_method_save_buy', EventRegistry::eventCodes());
        foreach (EventRegistry::eventCodes() as $code) {
            self::assertContains($code, $managed);
        }
    }

    public function testPresentationRetentionUsesCreatedAt(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $table = $db->getPrefix() . PersistenceTableNames::FINANCING_ATTEMPT;
        $snapshotJson = json_encode(
            (new FinancingPresentationSnapshot(123, null, false, 6, 'K', 0, 100, 10, 110, 1, 2))->toArray(),
            JSON_THROW_ON_ERROR
        );
        $recentCreated = gmdate('Y-m-d H:i:s', self::BASE_TIME - (100 * 86400));
        $oldCreated = gmdate('Y-m-d H:i:s', self::BASE_TIME - (200 * 86400));
        $recentUpdated = gmdate('Y-m-d H:i:s', self::BASE_TIME);
        $p2Enc = 'enc:v1:test-ciphertext';

        $db->query(
            "INSERT INTO `{$table}`
                (`attempt_id`, `store_id`, `order_id`, `entry_point`, `operation_key_hash`, `actor_binding_hash`,
                 `selection_hash`, `state`, `leasing_presentation_json`, `process2_sensitive_enc`,
                 `created_at`, `updated_at`, `expires_at`)
             VALUES
                (1, 0, 100, 'product', '" . $db->escape(hash('sha256', 'a')) . "',
                 '" . $db->escape(hash('sha256', 'b')) . "', '" . $db->escape(hash('sha256', 'c')) . "',
                 'order_created', '" . $db->escape($snapshotJson) . "', '" . $db->escape($p2Enc) . "',
                 '" . $db->escape($recentCreated) . "', '" . $db->escape($recentUpdated) . "',
                 '" . $db->escape(gmdate('Y-m-d H:i:s', self::BASE_TIME + 3600)) . "'),
                (2, 0, 101, 'product', '" . $db->escape(hash('sha256', 'd')) . "',
                 '" . $db->escape(hash('sha256', 'e')) . "', '" . $db->escape(hash('sha256', 'f')) . "',
                 'order_created', '" . $db->escape($snapshotJson) . "', '" . $db->escape($p2Enc) . "',
                 '" . $db->escape($oldCreated) . "', '" . $db->escape($recentUpdated) . "',
                 '" . $db->escape(gmdate('Y-m-d H:i:s', self::BASE_TIME + 3600)) . "'),
                (3, 0, 102, 'product', '" . $db->escape(hash('sha256', 'g')) . "',
                 '" . $db->escape(hash('sha256', 'h')) . "', '" . $db->escape(hash('sha256', 'i')) . "',
                 'order_created', NULL, NULL,
                 '" . $db->escape($oldCreated) . "', '" . $db->escape($oldCreated) . "',
                 '" . $db->escape(gmdate('Y-m-d H:i:s', self::BASE_TIME + 3600)) . "')"
        );

        $clock = new PersistenceClock(static fn (): int => self::BASE_TIME);
        $repo = new FinancingPresentationRepository($db, $clock);
        self::assertSame(1, $repo->redactExpiredPresentationBatch(SecurityConstants::PRESENTATION_RETENTION_DAYS, 10));

        $result = $db->query("SELECT `attempt_id`, `leasing_presentation_json`, `process2_sensitive_enc`, `state`
                              FROM `{$table}` ORDER BY `attempt_id`");
        self::assertIsObject($result);
        self::assertSame(3, $result->num_rows);
        self::assertNotNull($result->rows[0]['leasing_presentation_json']);
        self::assertNull($result->rows[1]['leasing_presentation_json']);
        self::assertSame($p2Enc, $result->rows[1]['process2_sensitive_enc']);
        self::assertSame('order_created', $result->rows[1]['state']);
        self::assertNull($result->rows[2]['leasing_presentation_json']);
    }

    public function testMultistorePresentationLookupIsStoreScoped(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $table = $db->getPrefix() . PersistenceTableNames::FINANCING_ATTEMPT;
        $store0Json = json_encode(['shop_order_id' => 123, 'months' => 6, 'kop_code' => 'S0'], JSON_THROW_ON_ERROR);
        $store2Json = json_encode(['shop_order_id' => 123, 'months' => 12, 'kop_code' => 'S2'], JSON_THROW_ON_ERROR);
        $now = gmdate('Y-m-d H:i:s', self::BASE_TIME);
        $expires = gmdate('Y-m-d H:i:s', self::BASE_TIME + 3600);
        $hash = static fn (string $s): string => hash('sha256', $s);

        foreach ([
            [1, 0, $store0Json, $hash('op0')],
            [2, 2, $store2Json, $hash('op2')],
        ] as [$attemptId, $storeId, $json, $opHash]) {
            $db->query(
                "INSERT INTO `{$table}`
                    (`attempt_id`, `store_id`, `order_id`, `entry_point`, `operation_key_hash`, `actor_binding_hash`,
                     `selection_hash`, `state`, `leasing_presentation_json`, `created_at`, `updated_at`, `expires_at`)
                 VALUES (
                    " . (int) $attemptId . ", " . (int) $storeId . ", 123, 'product',
                    '" . $db->escape($opHash) . "', '" . $db->escape($hash('a' . $attemptId)) . "',
                    '" . $db->escape($hash('s' . $attemptId)) . "', 'order_created',
                    '" . $db->escape($json) . "',
                    '" . $db->escape($now) . "', '" . $db->escape($now) . "', '" . $db->escape($expires) . "'
                 )"
            );
        }

        $repo = new FinancingPresentationRepository($db);
        $snap0 = $repo->findByOrderId(0, 123);
        $snap2 = $repo->findByOrderId(2, 123);
        self::assertNotNull($snap0);
        self::assertNotNull($snap2);
        self::assertSame(6, $snap0->months);
        self::assertSame(12, $snap2->months);
        self::assertNull($repo->findByOrderId(0, 999));
    }

    public function testProcessTwoSensitiveRetentionUnaffectedByPresentationRedact(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $table = $db->getPrefix() . PersistenceTableNames::FINANCING_ATTEMPT;
        $old = gmdate('Y-m-d H:i:s', self::BASE_TIME - (200 * 86400));
        $snapshotJson = json_encode(['shop_order_id' => 50, 'months' => 6], JSON_THROW_ON_ERROR);
        $db->query(
            "INSERT INTO `{$table}`
                (`attempt_id`, `store_id`, `order_id`, `entry_point`, `operation_key_hash`, `actor_binding_hash`,
                 `selection_hash`, `state`, `leasing_presentation_json`, `process2_sensitive_enc`,
                 `created_at`, `updated_at`, `expires_at`)
             VALUES (
                1, 0, 50, 'checkout', '" . $db->escape(hash('sha256', 'p2')) . "',
                '" . $db->escape(hash('sha256', 'a')) . "', '" . $db->escape(hash('sha256', 's')) . "',
                'order_created', '" . $db->escape($snapshotJson) . "', 'enc:v1:old',
                '" . $db->escape($old) . "', '" . $db->escape($old) . "',
                '" . $db->escape(gmdate('Y-m-d H:i:s', self::BASE_TIME + 3600)) . "'
             )"
        );

        $presentationRepo = new FinancingPresentationRepository(
            $db,
            new PersistenceClock(static fn (): int => self::BASE_TIME)
        );
        $presentationRepo->redactExpiredPresentationBatch(SecurityConstants::PRESENTATION_RETENTION_DAYS, 10);

        $row = $db->query("SELECT `process2_sensitive_enc`, `leasing_presentation_json` FROM `{$table}` WHERE `attempt_id` = 1");
        self::assertIsObject($row);
        self::assertSame('enc:v1:old', $row->row['process2_sensitive_enc']);
        self::assertNull($row->row['leasing_presentation_json']);
    }
}
