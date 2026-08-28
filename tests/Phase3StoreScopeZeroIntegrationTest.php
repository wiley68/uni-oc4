<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use Opencart\System\Library\Extension\MtUniCredit\ApiNonceRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartStoreScope;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\OperationLockRepository;
use Opencart\System\Library\Extension\MtUniCredit\OrderCorrelationRepository;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceClock;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceValidationException;
use Opencart\System\Library\Extension\MtUniCredit\ShopCacheRepository;
use PHPUnit\Framework\TestCase;

/**
 * OpenCart default store_id = 0 must be a valid explicit persistence scope.
 */
final class Phase3StoreScopeZeroIntegrationTest extends TestCase
{
    private const BASE_TIME = 1_700_000_000;

    protected function setUp(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
    }

    public function testOpenCartStoreScopeContract(): void
    {
        self::assertTrue(OpenCartStoreScope::isValid(0));
        self::assertTrue(OpenCartStoreScope::isValid(1));
        self::assertFalse(OpenCartStoreScope::isValid(-1));
        OpenCartStoreScope::require(0);
        $this->expectException(PersistenceValidationException::class);
        OpenCartStoreScope::require(-1);
    }

    public function testStoreZeroNonceClaimAndIsolationFromStoreOne(): void
    {
        $repo = new ApiNonceRepository(
            PersistenceIntegrationHarness::connection(),
            new PersistenceClock(static fn(): int => self::BASE_TIME)
        );
        $nonce = str_repeat('e', 64);
        self::assertTrue($repo->claim(
            PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT,
            PersistenceIntegrationHarness::TEST_UNICID,
            $nonce
        ));
        self::assertFalse($repo->claim(
            PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT,
            PersistenceIntegrationHarness::TEST_UNICID,
            $nonce
        ));
        self::assertTrue($repo->claim(
            PersistenceIntegrationHarness::TEST_STORE_ID_ONE,
            PersistenceIntegrationHarness::TEST_UNICID,
            $nonce
        ));

        $this->expectException(PersistenceValidationException::class);
        $repo->claim(-1, PersistenceIntegrationHarness::TEST_UNICID, str_repeat('f', 64));
    }

    public function testStoreZeroOperationLock(): void
    {
        $repo = new OperationLockRepository(
            PersistenceIntegrationHarness::connection(),
            new PersistenceClock(static fn(): int => self::BASE_TIME)
        );
        $hash = hash('sha256', 'store-zero-lock');
        $owner = LockOwnerTokenGenerator::generate();
        self::assertTrue($repo->acquire(
            PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT,
            OperationEntryPoint::PRODUCT,
            $hash,
            $owner
        ));
        self::assertTrue($repo->acquire(
            PersistenceIntegrationHarness::TEST_STORE_ID_ONE,
            OperationEntryPoint::PRODUCT,
            $hash,
            LockOwnerTokenGenerator::generate()
        ));
        self::assertTrue($repo->release(
            PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT,
            OperationEntryPoint::PRODUCT,
            $hash,
            $owner
        ));

        $this->expectException(PersistenceValidationException::class);
        $repo->acquire(-5, OperationEntryPoint::PRODUCT, $hash, LockOwnerTokenGenerator::generate());
    }

    public function testStoreZeroFinancingAttempt(): void
    {
        $repo = new FinancingAttemptRepository(PersistenceIntegrationHarness::connection());
        $row = $repo->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT,
            OperationEntryPoint::PRODUCT,
            hash('sha256', 'op-zero'),
            hash('sha256', 'actor-zero'),
            hash('sha256', 'selection-zero')
        );
        self::assertSame(0, (int) $row['store_id']);
        self::assertSame(FinancingAttemptState::ISSUED, $row['state']);
        $found = $repo->findByToken(
            PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT,
            (string) $row['submission_token']
        );
        self::assertNotNull($found);
        self::assertNull($repo->findByToken(
            PersistenceIntegrationHarness::TEST_STORE_ID_ONE,
            (string) $row['submission_token']
        ));

        $this->expectException(PersistenceValidationException::class);
        $repo->issueWithSubmissionToken(
            -1,
            OperationEntryPoint::PRODUCT,
            hash('sha256', 'op-neg'),
            hash('sha256', 'actor-neg'),
            hash('sha256', 'selection-neg')
        );
    }

    public function testStoreZeroShopCache(): void
    {
        $repo = new ShopCacheRepository(PersistenceIntegrationHarness::connection());
        $repo->replaceValidated(
            PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT,
            PersistenceIntegrationHarness::TEST_UNICID,
            ['shop' => 'default-store']
        );
        self::assertNotNull($repo->findFresh(
            PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT,
            PersistenceIntegrationHarness::TEST_UNICID
        ));
        self::assertNull($repo->findFresh(
            PersistenceIntegrationHarness::TEST_STORE_ID_ONE,
            PersistenceIntegrationHarness::TEST_UNICID
        ));

        $repo->replaceValidated(
            PersistenceIntegrationHarness::TEST_STORE_ID_ONE,
            PersistenceIntegrationHarness::TEST_UNICID,
            ['shop' => 'store-one']
        );
        $repo->deleteScoped(
            PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT,
            PersistenceIntegrationHarness::TEST_UNICID
        );
        self::assertNull($repo->findLatest(
            PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT,
            PersistenceIntegrationHarness::TEST_UNICID
        ));
        self::assertNotNull($repo->findLatest(
            PersistenceIntegrationHarness::TEST_STORE_ID_ONE,
            PersistenceIntegrationHarness::TEST_UNICID
        ));

        $this->expectException(PersistenceValidationException::class);
        $repo->deleteScoped(-1, PersistenceIntegrationHarness::TEST_UNICID);
    }

    public function testStoreZeroOrderCorrelation(): void
    {
        $attempts = new FinancingAttemptRepository(PersistenceIntegrationHarness::connection());
        $correlations = new OrderCorrelationRepository(PersistenceIntegrationHarness::connection());
        $attempt = $attempts->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT,
            OperationEntryPoint::PRODUCT,
            hash('sha256', 'corr-op'),
            hash('sha256', 'corr-actor'),
            hash('sha256', 'corr-selection')
        );
        $attemptId = (int) $attempt['attempt_id'];
        $correlations->linkCreatedOrder(
            PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT,
            $attemptId,
            88001
        );
        self::assertSame(
            88001,
            $correlations->findOrderIdByAttempt(PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT, $attemptId)
        );
        self::assertNull(
            $correlations->findOrderIdByAttempt(PersistenceIntegrationHarness::TEST_STORE_ID_ONE, $attemptId)
        );

        $this->expectException(PersistenceValidationException::class);
        $correlations->linkCreatedOrder(-1, $attemptId, 88002);
    }
}
