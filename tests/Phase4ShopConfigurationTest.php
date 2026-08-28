<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\CpAuthenticationException;
use Opencart\System\Library\Extension\MtUniCredit\CpHttpException;
use Opencart\System\Library\Extension\MtUniCredit\ShopCacheRepository;
use Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationSnapshotValidator;
use Opencart\System\Library\Extension\MtUniCredit\ShopSnapshotValidationException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/fixtures/cp_shop_snapshot.php';

final class Phase4ShopConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required (MT_UNI_CREDIT_INTEGRATION=1).');
        }
        PersistenceIntegrationHarness::resetTables();
    }

    public function testValidSnapshotIsCached(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        $stack = Phase4TestHarness::services($transport);
        $stack['client']->login();
        $data = $stack['shopConfiguration']->refreshRemote();
        self::assertSame(Phase4TestHarness::TEST_UNICID, $data['unicid']);
        $metadata = $stack['shopConfiguration']->getMetadata();
        self::assertNotNull($metadata);
        self::assertTrue($metadata['is_fresh']);
    }

    public function testInvalidSnapshotRejectedAndPreservesCache(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        $stack = Phase4TestHarness::services($transport);
        $stack['client']->login();
        $stack['shopConfiguration']->refreshRemote();

        $bad = mt_uni_credit_valid_shop_snapshot();
        unset($bad['kop']);
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload($bad));
        try {
            $stack['shopConfiguration']->refreshRemote();
            self::fail('Expected validation failure');
        } catch (ShopSnapshotValidationException $exception) {
        }

        self::assertNotNull($stack['shopConfiguration']->getMetadata());
        self::assertSame(Phase4TestHarness::TEST_UNICID, $stack['shopConfiguration']->get(false)['unicid']);
    }

    public function testTransientFailurePreservesCache(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        $stack = Phase4TestHarness::services($transport);
        $stack['client']->login();
        $stack['shopConfiguration']->refreshRemote();

        $transport->enqueueJson(503, ['error' => 'down']);
        try {
            $stack['shopConfiguration']->refreshRemote();
            self::fail('Expected transient HTTP failure');
        } catch (CpHttpException $exception) {
            self::assertTrue($exception->isTransient());
        }

        self::assertNotNull($stack['shopConfiguration']->getMetadata());
        self::assertTrue($stack['tokens']->hasToken());
    }

    public function testPermanentAuthFailurePurgesCacheAndTokens(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        $stack = Phase4TestHarness::services($transport);
        $stack['client']->login();
        $stack['shopConfiguration']->refreshRemote();

        $transport->enqueueJson(403, ['error' => 'forbidden']);
        try {
            $stack['shopConfiguration']->refreshRemote();
            self::fail('Expected permanent HTTP failure');
        } catch (CpHttpException $exception) {
        }

        self::assertNull($stack['shopConfiguration']->getMetadata());
        self::assertFalse($stack['tokens']->hasToken());
    }

    public function testStoreAndUnicidScopeIsolation(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        $stack = Phase4TestHarness::services($transport);

        $db = PersistenceIntegrationHarness::connection();
        $cache = new ShopCacheRepository($db);
        $snapshot = mt_uni_credit_valid_shop_snapshot(['unicid' => PersistenceIntegrationHarness::TEST_UNICID_B]);
        $cache->replaceValidated(
            PersistenceIntegrationHarness::TEST_STORE_ID_B,
            PersistenceIntegrationHarness::TEST_UNICID_B,
            $snapshot
        );

        $stack['client']->login();
        $stack['shopConfiguration']->refreshRemote();

        self::assertNotNull($cache->findLatest(PersistenceIntegrationHarness::TEST_STORE_ID_B, PersistenceIntegrationHarness::TEST_UNICID_B));
    }

    public function testSnapshotValidatorMatchesContractFixture(): void
    {
        $validator = new ShopConfigurationSnapshotValidator();
        $validator->validate(mt_uni_credit_valid_shop_snapshot(), Phase4TestHarness::TEST_UNICID);
        self::assertTrue(true);
    }

    public function testFreshCacheServedWithoutRemoteCall(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        $stack = Phase4TestHarness::services($transport);
        $stack['client']->login();
        $stack['shopConfiguration']->refreshRemote();
        $requestCount = count($transport->requests);
        $cached = $stack['shopConfiguration']->get(false);
        self::assertSame(Phase4TestHarness::TEST_UNICID, $cached['unicid']);
        self::assertCount($requestCount, $transport->requests);
    }
}
