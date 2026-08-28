<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use Opencart\System\Library\Extension\MtUniCredit\ApiNonceRepository;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceClock;
use Opencart\System\Library\Extension\MtUniCredit\SecurityConstants;
use PHPUnit\Framework\TestCase;

final class Phase3ApiNonceRepositoryIntegrationTest extends TestCase
{
    private ApiNonceRepository $repository;

    protected function setUp(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $this->repository = new ApiNonceRepository(PersistenceIntegrationHarness::connection(), new PersistenceClock(static fn(): int => 1_700_000_000));
    }

    public function testFirstClaimSucceedsDuplicateFails(): void
    {
        $nonce = str_repeat('a', 64);
        self::assertTrue($this->repository->claim(PersistenceIntegrationHarness::TEST_STORE_ID, PersistenceIntegrationHarness::TEST_UNICID, $nonce));
        self::assertFalse($this->repository->claim(PersistenceIntegrationHarness::TEST_STORE_ID, PersistenceIntegrationHarness::TEST_UNICID, $nonce));
    }

    public function testCrossStoreAndUnicidClaimsAreIndependent(): void
    {
        $nonce = str_repeat('b', 64);
        self::assertTrue($this->repository->claim(PersistenceIntegrationHarness::TEST_STORE_ID, PersistenceIntegrationHarness::TEST_UNICID, $nonce));
        self::assertTrue($this->repository->claim(PersistenceIntegrationHarness::TEST_STORE_ID_B, PersistenceIntegrationHarness::TEST_UNICID, $nonce));
        self::assertTrue($this->repository->claim(PersistenceIntegrationHarness::TEST_STORE_ID, PersistenceIntegrationHarness::TEST_UNICID_B, $nonce));
    }

    public function testRawNonceIsNotStored(): void
    {
        $nonce = str_repeat('c', 64);
        $this->repository->claim(PersistenceIntegrationHarness::TEST_STORE_ID, PersistenceIntegrationHarness::TEST_UNICID, $nonce);
        $db = PersistenceIntegrationHarness::connection();
        $table = $db->getPrefix() . 'mt_uni_credit_api_nonce';
        $result = $db->query("SELECT `nonce_hash` FROM `{$table}` LIMIT 1");
        self::assertIsObject($result);
        self::assertSame(ApiNonceRepository::hashNonce($nonce), $result->row['nonce_hash']);
        self::assertNotSame($nonce, $result->row['nonce_hash']);
    }

    public function testExpiredCleanupIsBounded(): void
    {
        $nonce = str_repeat('d', 64);
        $this->repository->claim(PersistenceIntegrationHarness::TEST_STORE_ID, PersistenceIntegrationHarness::TEST_UNICID, $nonce);
        $clock = new PersistenceClock(static fn(): int => 1_700_000_000 + SecurityConstants::NONCE_RETENTION_SECONDS + 5);
        $repo = new ApiNonceRepository(PersistenceIntegrationHarness::connection(), $clock);
        self::assertSame(1, $repo->deleteExpiredBatch(10));
        self::assertSame(0, $repo->deleteExpiredBatch(10));
    }
}
