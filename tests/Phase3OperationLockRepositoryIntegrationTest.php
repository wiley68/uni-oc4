<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\OperationLockRepository;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceClock;
use Opencart\System\Library\Extension\MtUniCredit\SecurityConstants;
use PHPUnit\Framework\TestCase;

final class Phase3OperationLockRepositoryIntegrationTest extends TestCase
{
    private const BASE_TIME = 1_700_000_000;

    private OperationLockRepository $repository;

    private string $operationKeyHash;

    protected function setUp(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $this->repository = new OperationLockRepository(
            PersistenceIntegrationHarness::connection(),
            new PersistenceClock(static fn(): int => self::BASE_TIME)
        );
        $this->operationKeyHash = hash('sha256', 'product-op-key');
    }

    public function testAcquireReleaseAndOwnerOnlySemantics(): void
    {
        $ownerA = LockOwnerTokenGenerator::generate();
        $ownerB = LockOwnerTokenGenerator::generate();

        self::assertTrue($this->repository->acquire(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            $this->operationKeyHash,
            $ownerA
        ));
        self::assertFalse($this->repository->acquire(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            $this->operationKeyHash,
            $ownerB
        ));
        self::assertFalse($this->repository->release(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            $this->operationKeyHash,
            $ownerB
        ));
        self::assertTrue($this->repository->release(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            $this->operationKeyHash,
            $ownerA
        ));
    }

    public function testStaleTakeover(): void
    {
        $ownerA = LockOwnerTokenGenerator::generate();
        $ownerB = LockOwnerTokenGenerator::generate();
        self::assertTrue($this->repository->acquire(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::CART,
            $this->operationKeyHash,
            $ownerA
        ));

        $staleRepo = new OperationLockRepository(
            PersistenceIntegrationHarness::connection(),
            new PersistenceClock(static fn(): int => self::BASE_TIME + SecurityConstants::OPERATION_LOCK_TTL_SECONDS + 1)
        );
        self::assertTrue($staleRepo->acquire(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::CART,
            $this->operationKeyHash,
            $ownerB
        ));
    }

    public function testOwnerOnlyHeartbeat(): void
    {
        $ownerA = LockOwnerTokenGenerator::generate();
        $ownerB = LockOwnerTokenGenerator::generate();
        self::assertTrue($this->repository->acquire(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::CART,
            hash('sha256', 'heartbeat-op'),
            $ownerA
        ));

        $activeRepo = new OperationLockRepository(
            PersistenceIntegrationHarness::connection(),
            new PersistenceClock(static fn(): int => self::BASE_TIME + 10)
        );
        self::assertTrue($activeRepo->heartbeat(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::CART,
            hash('sha256', 'heartbeat-op'),
            $ownerA
        ));
        self::assertFalse($activeRepo->heartbeat(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::CART,
            hash('sha256', 'heartbeat-op'),
            $ownerB
        ));
    }

    public function testEntryPointAndStoreIsolation(): void
    {
        $owner = LockOwnerTokenGenerator::generate();
        self::assertTrue($this->repository->acquire(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            $this->operationKeyHash,
            $owner
        ));
        self::assertTrue($this->repository->acquire(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::CHECKOUT,
            $this->operationKeyHash,
            LockOwnerTokenGenerator::generate()
        ));
        self::assertTrue($this->repository->acquire(
            PersistenceIntegrationHarness::TEST_STORE_ID_B,
            OperationEntryPoint::PRODUCT,
            $this->operationKeyHash,
            LockOwnerTokenGenerator::generate()
        ));
    }
}
