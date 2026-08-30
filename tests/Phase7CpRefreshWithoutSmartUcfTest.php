<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\DeploymentHealthService;
use Opencart\System\Library\Extension\MtUniCredit\DeploymentHealthStatus;
use Opencart\System\Library\Extension\MtUniCredit\ShopCacheRepository;
use PHPUnit\Framework\TestCase;

/**
 * CP login + GET /shop must work without SmartUCF mTLS deployment material.
 */
final class Phase7CpRefreshWithoutSmartUcfTest extends TestCase
{
    public function testRefreshSucceedsIndependentOfSmartUcfMtlsMaterial(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required (MT_UNI_CREDIT_INTEGRATION=1).');
        }

        // Phase 11A may deploy keys/avalon_*.pem; CP login + GET /shop must not require them.
        $health = (new DeploymentHealthService())->evaluate();
        self::assertArrayHasKey('deployment_ready', $health);
        self::assertArrayHasKey('certificate', $health);
        self::assertArrayHasKey('private_key', $health);

        PersistenceIntegrationHarness::resetTables();
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        $storeId = PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT;
        $stack = Phase4TestHarness::services(
            $transport,
            Phase4TestHarness::settings(),
            PersistenceIntegrationHarness::connection(),
            $storeId
        );

        $shop = $stack['shopConfiguration']->refreshRemote();
        self::assertTrue($stack['tokens']->hasToken());
        self::assertIsArray($shop);
        self::assertNotNull(
            (new ShopCacheRepository(PersistenceIntegrationHarness::connection()))
                ->findLatest($storeId, Phase4TestHarness::TEST_UNICID)
        );

        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertStringContainsString('/auth/login', $transport->requests[0]['url']);
        self::assertSame('GET', $transport->requests[1]['method']);
        self::assertStringContainsString('/shop', $transport->requests[1]['url']);
    }

    public function testCpClientSourceHasNoSmartUcfDependency(): void
    {
        $client = (string) file_get_contents(dirname(__DIR__) . '/system/library/control_panel_client.php');
        $shop = (string) file_get_contents(dirname(__DIR__) . '/system/library/shop_configuration_service.php');
        $factory = (string) file_get_contents(dirname(__DIR__) . '/system/library/cp_service_factory.php');

        foreach ([$client, $shop, $factory] as $source) {
            self::assertStringNotContainsString('DeploymentHealthService', $source);
            self::assertStringNotContainsString('avalon_cert', $source);
            self::assertStringNotContainsString('avalon_private_key', $source);
            self::assertStringNotContainsString('smartucf-key', strtolower($source));
        }
    }
}
