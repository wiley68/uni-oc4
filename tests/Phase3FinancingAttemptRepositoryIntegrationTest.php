<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceConflictException;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceValidationException;
use Opencart\System\Library\Extension\MtUniCredit\ShopCacheRepository;
use Opencart\System\Library\Extension\MtUniCredit\SubmissionTokenGenerator;
use PHPUnit\Framework\TestCase;

final class Phase3FinancingAttemptRepositoryIntegrationTest extends TestCase
{
    private FinancingAttemptRepository $repository;

    protected function setUp(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $this->repository = new FinancingAttemptRepository(PersistenceIntegrationHarness::connection());
    }

    public function testIssueProductAndCartAttemptsWithUniqueTokens(): void
    {
        $hash = static fn(string $suffix): string => hash('sha256', $suffix);
        $product = $this->repository->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            $hash('op-product'),
            $hash('actor'),
            $hash('selection')
        );
        $cart = $this->repository->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::CART,
            $hash('op-cart'),
            $hash('actor'),
            $hash('selection')
        );

        self::assertTrue(SubmissionTokenGenerator::isValidFormat((string) $product['submission_token']));
        self::assertTrue(SubmissionTokenGenerator::isValidFormat((string) $cart['submission_token']));
        self::assertNotSame($product['submission_token'], $cart['submission_token']);
        self::assertSame(FinancingAttemptState::ISSUED, $product['state']);
    }

    public function testCheckoutAttemptHasNullSubmissionToken(): void
    {
        $hash = static fn(string $suffix): string => hash('sha256', $suffix);
        $checkout = $this->repository->issueCheckoutAttempt(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            $hash('op-checkout'),
            $hash('actor'),
            $hash('selection')
        );
        self::assertNull($checkout['submission_token']);
        self::assertSame(OperationEntryPoint::CHECKOUT, $checkout['entry_point']);
    }

    public function testTransitionAndInvalidTransition(): void
    {
        $hash = static fn(string $suffix): string => hash('sha256', $suffix);
        $row = $this->repository->issueCheckoutAttempt(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            $hash('op-transition'),
            $hash('actor'),
            $hash('selection')
        );
        $attemptId = (int) $row['attempt_id'];
        self::assertTrue($this->repository->transition($attemptId, FinancingAttemptState::ISSUED, FinancingAttemptState::VALIDATING));
        self::assertFalse($this->repository->transition($attemptId, FinancingAttemptState::ISSUED, FinancingAttemptState::ORDER_CREATING));
    }

    public function testAttachOrderOnceAndConflictSafety(): void
    {
        $hash = static fn(string $suffix): string => hash('sha256', $suffix);
        $attemptA = $this->repository->issueCheckoutAttempt(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            $hash('op-a'),
            $hash('actor-a'),
            $hash('selection-a')
        );
        $attemptB = $this->repository->issueCheckoutAttempt(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            $hash('op-b'),
            $hash('actor-b'),
            $hash('selection-b')
        );

        $attemptAId = (int) $attemptA['attempt_id'];
        $attemptBId = (int) $attemptB['attempt_id'];
        self::assertTrue($this->repository->attachOrder($attemptAId, 5001));
        self::assertTrue($this->repository->attachOrder($attemptAId, 5001));

        $this->expectException(PersistenceConflictException::class);
        $this->repository->attachOrder($attemptBId, 5001);
    }

    public function testLookupByTokenOrderAndOperationIdentity(): void
    {
        $hash = static fn(string $suffix): string => hash('sha256', $suffix);
        $operationHash = $hash('lookup-op');
        $issued = $this->repository->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            $operationHash,
            $hash('actor'),
            $hash('selection')
        );
        $token = (string) $issued['submission_token'];
        self::assertNotNull($this->repository->findByToken(PersistenceIntegrationHarness::TEST_STORE_ID, $token));
        self::assertNotNull($this->repository->findByOperationIdentity(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            $operationHash,
            FinancingAttemptState::ISSUED
        ));
        $this->repository->attachOrder((int) $issued['attempt_id'], 6001);
        self::assertNotNull($this->repository->findByOrderId(PersistenceIntegrationHarness::TEST_STORE_ID, 6001));
    }
}

final class Phase3ShopCacheRepositoryIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
    }

    public function testReplaceValidatedAndRejectInvalidWithoutDeletingExisting(): void
    {
        $repo = new ShopCacheRepository(PersistenceIntegrationHarness::connection());
        $repo->replaceValidated(PersistenceIntegrationHarness::TEST_STORE_ID, PersistenceIntegrationHarness::TEST_UNICID, ['shop' => 'ok']);
        self::assertNotNull($repo->findFresh(PersistenceIntegrationHarness::TEST_STORE_ID, PersistenceIntegrationHarness::TEST_UNICID));

        try {
            $repo->replaceValidated(PersistenceIntegrationHarness::TEST_STORE_ID, PersistenceIntegrationHarness::TEST_UNICID, []);
            self::fail('Expected invalid replacement to throw.');
        } catch (PersistenceValidationException) {
        }

        self::assertNotNull($repo->findFresh(PersistenceIntegrationHarness::TEST_STORE_ID, PersistenceIntegrationHarness::TEST_UNICID));
    }
}
