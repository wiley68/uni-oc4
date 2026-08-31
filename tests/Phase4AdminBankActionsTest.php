<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\ShopCacheRepository;
use PHPUnit\Framework\TestCase;

/**
 * Admin UX contract + refreshBankData operator action (store 0, transparent auth).
 */
final class Phase4AdminBankActionsTest extends TestCase
{
    public function testAdminTwigExposesSaveRefreshAndJournalWithoutTechnicalAuthButtons(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__) . '/admin/view/template/module/mt_uni_credit.twig');
        $bg = (string) file_get_contents(dirname(__DIR__) . '/admin/language/bg-bg/module/mt_uni_credit.php');

        self::assertStringContainsString('button_save', $twig);
        self::assertStringContainsString('form="form-module"', $twig);
        self::assertStringContainsString('button_refresh_bank_data', $twig);
        self::assertStringContainsString('button_download_journal', $twig);
        self::assertStringContainsString('refresh_bank_data', $twig);
        self::assertStringContainsString('download_journal', $twig);
        self::assertStringContainsString('method="post"', $twig);
        self::assertStringContainsString('help_download_journal', $twig);
        self::assertStringNotContainsString('help_journal_unavailable', $twig);

        self::assertStringNotContainsString('button_cp_connect', $twig);
        self::assertStringNotContainsString('button_cp_disconnect', $twig);
        self::assertStringNotContainsString('cp_connect', $twig);
        self::assertStringNotContainsString('cp_disconnect', $twig);
        self::assertStringNotContainsString('Запиши настройките', $twig);
        self::assertStringNotContainsString('Запиши настройките', $bg);

        self::assertStringContainsString('Обнови данните от банката', $bg);
        self::assertStringContainsString('Изтегли журнал операции', $bg);
        self::assertStringNotContainsString('Свързване / login', $bg);
        self::assertStringNotContainsString('Изключване / logout', $bg);
    }

    public function testControllerUsesPostRedirectRefreshBankDataRoute(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/admin/controller/module/mt_uni_credit.php');
        self::assertStringContainsString('function refreshBankData', $controller);
        self::assertStringContainsString('function downloadJournal', $controller);
        self::assertStringContainsString("REQUEST_METHOD", $controller);
        self::assertStringContainsString('hasPermission(\'modify\'', $controller);
        self::assertStringContainsString('session->data[\'success\']', $controller);
        self::assertStringContainsString('session->data[\'error\']', $controller);
        self::assertStringContainsString('response->redirect', $controller);
        self::assertStringNotContainsString('function connect(', $controller);
        self::assertStringNotContainsString('function disconnect(', $controller);
        self::assertStringNotContainsString('function refreshShop(', $controller);
    }

    public function testRefreshBankDataStoreZeroAutoLoginAndCachesSnapshot(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required (MT_UNI_CREDIT_INTEGRATION=1).');
        }

        PersistenceIntegrationHarness::resetTables();
        $settings = Phase4TestHarness::settings();
        $db = PersistenceIntegrationHarness::connection();
        $storeId = PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT;

        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        $stack = Phase4TestHarness::services($transport, $settings, $db, $storeId);

        self::assertFalse($stack['tokens']->hasToken());

        $shop = $stack['shopConfiguration']->refreshRemote();
        self::assertTrue($stack['tokens']->hasToken());
        self::assertIsArray($shop);
        self::assertArrayHasKey('coeff_list', $shop);

        $cache = new ShopCacheRepository($db);
        $row = $cache->findLatest($storeId, Phase4TestHarness::TEST_UNICID);
        self::assertNotNull($row);
        $table = $db->getPrefix() . 'mt_uni_credit_shop_cache';
        $stored = $db->query(
            "SELECT `store_id` FROM `{$table}` WHERE `unicid` = '" . $db->escape(Phase4TestHarness::TEST_UNICID) . "' LIMIT 1"
        );
        self::assertIsObject($stored);
        self::assertSame(0, (int) $stored->row['store_id']);
    }

    public function testRefreshBankDataReusesValidToken(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required (MT_UNI_CREDIT_INTEGRATION=1).');
        }

        PersistenceIntegrationHarness::resetTables();
        $settings = Phase4TestHarness::settings();
        $db = PersistenceIntegrationHarness::connection();
        $storeId = PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT;

        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        $stack = Phase4TestHarness::services($transport, $settings, $db, $storeId);
        $stack['client']->login();
        self::assertCount(1, $transport->requests);

        $stack['shopConfiguration']->refreshRemote();
        self::assertCount(2, $transport->requests);
        self::assertSame('GET', $transport->requests[1]['method']);
        self::assertStringContainsString('/shop', $transport->requests[1]['url']);
    }

    public function testRefreshBankDataExpiredTokenTriggersLoginThenShop(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required (MT_UNI_CREDIT_INTEGRATION=1).');
        }

        PersistenceIntegrationHarness::resetTables();
        $settings = Phase4TestHarness::settings();
        $db = PersistenceIntegrationHarness::connection();
        $storeId = PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT;

        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload([
            'expires_in' => 1,
        ]));
        // ensureToken sees expiry and logs in again, then GET /shop
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());

        $now = 1_700_000_000;
        $stack = Phase4TestHarness::services($transport, $settings, $db, $storeId, $now);
        $stack['client']->login();

        $later = Phase4TestHarness::services($transport, $settings, $db, $storeId, $now + 120);
        $later['shopConfiguration']->refreshRemote();

        $methods = array_column($transport->requests, 'method');
        self::assertContains('POST', $methods);
        self::assertContains('GET', $methods);
    }

    public function testRefreshBankDataAuthFailureAndInvalidSnapshot(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required (MT_UNI_CREDIT_INTEGRATION=1).');
        }

        PersistenceIntegrationHarness::resetTables();
        $settings = Phase4TestHarness::settings();
        $db = PersistenceIntegrationHarness::connection();
        $storeId = PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT;

        $authFail = new FakeCpHttpTransport();
        $authFail->enqueueJson(401, ['error' => 'invalid']);
        $stack = Phase4TestHarness::services($authFail, $settings, $db, $storeId);
        try {
            $stack['shopConfiguration']->refreshRemote();
            self::fail('Expected authentication failure');
        } catch (\Opencart\System\Library\Extension\MtUniCredit\CpAuthenticationException $exception) {
            self::assertFalse($stack['tokens']->hasToken());
        }

        PersistenceIntegrationHarness::resetTables();
        $badShop = new FakeCpHttpTransport();
        $badShop->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $badShop->enqueueJson(200, [
            'success' => true,
            'message' => 'ok',
            'data' => ['unicid' => Phase4TestHarness::TEST_UNICID],
        ]);
        $stack2 = Phase4TestHarness::services($badShop, Phase4TestHarness::settings(), $db, $storeId);
        try {
            $stack2['shopConfiguration']->refreshRemote();
            self::fail('Expected invalid snapshot');
        } catch (\Opencart\System\Library\Extension\MtUniCredit\ShopSnapshotValidationException $exception) {
        }
        self::assertNull((new ShopCacheRepository($db))->findLatest($storeId, Phase4TestHarness::TEST_UNICID));
    }

    public function testRefreshBankDataCp5xxIsTransient(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required (MT_UNI_CREDIT_INTEGRATION=1).');
        }

        PersistenceIntegrationHarness::resetTables();
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueue(503, 'unavailable');
        $stack = Phase4TestHarness::services(
            $transport,
            Phase4TestHarness::settings(),
            PersistenceIntegrationHarness::connection(),
            PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT
        );
        try {
            $stack['shopConfiguration']->refreshRemote();
            self::fail('Expected CP HTTP failure');
        } catch (\Opencart\System\Library\Extension\MtUniCredit\CpException $exception) {
            self::assertTrue($exception->isTransient());
        }
    }

    public function testMissingCredentialsAreDetectedWithoutCallingCp(): void
    {
        $settings = Phase4TestHarness::settings();
        self::assertSame('', (new ModuleCredentialsRepository($settings, Phase4TestHarness::cipher()))
            ->getUnicid(PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT));
        self::assertFalse((new ModuleCredentialsRepository($settings, Phase4TestHarness::cipher()))
            ->hasSecret(PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT));
    }
}
