<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\OperationLockRepository;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceConflictException;
use PHPUnit\Framework\TestCase;

final class Phase6OrderMaterializationIntegrationTest extends TestCase
{
    private InMemoryCheckoutOrderAdapter $orders;

    private FinancingAttemptRepository $attempts;

    private OperationLockRepository $locks;

    protected function setUp(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $this->orders = new InMemoryCheckoutOrderAdapter();
        $this->attempts = new FinancingAttemptRepository($db);
        $this->locks = new OperationLockRepository($db);
    }

    public function testServiceMaterializeAndBindProductOrder(): void
    {
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempt = $this->issueAttempt(OperationEntryPoint::PRODUCT, $submission->operationKeyHash);
        $service = OrderMaterializationTestHarness::buildService($this->orders, $this->attempts, $this->locks);

        $created = $service->materializeAndBind(
            $submission,
            OrderMaterializationTestHarness::attemptContext($attempt),
            LockOwnerTokenGenerator::generate()
        );

        self::assertSame(1, $this->orders->addOrderCallCount());
        self::assertSame(OrderMaterializationTestHarness::TEST_AWAITING_STATUS_ID, $created->orderStatusId);
        $row = $this->attempts->findById((int) $attempt['attempt_id']);
        self::assertSame(FinancingAttemptState::ORDER_CREATED, $row['state']);
        self::assertSame($created->orderId, (int) $row['order_id']);
    }

    public function testAttemptAttachOnceAndSameOrderRetry(): void
    {
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempt = $this->issueAttempt(OperationEntryPoint::PRODUCT, $submission->operationKeyHash);
        $service = OrderMaterializationTestHarness::buildService($this->orders, $this->attempts, $this->locks);
        $context = OrderMaterializationTestHarness::attemptContext($attempt);

        $first = $service->materializeAndBind($submission, $context, LockOwnerTokenGenerator::generate());
        $second = $service->materializeAndBind($submission, $context, LockOwnerTokenGenerator::generate());

        self::assertSame($first->orderId, $second->orderId);
        self::assertSame(1, $this->orders->addOrderCallCount());
    }

    public function testDifferentOrderAttachConflict(): void
    {
        $attempt = $this->issueAttempt(OperationEntryPoint::PRODUCT, hash('sha256', 'conflict-op'));
        $attemptId = (int) $attempt['attempt_id'];
        $this->attempts->attachOrder($attemptId, 70001);

        $this->expectException(PersistenceConflictException::class);
        $this->attempts->attachOrder($attemptId, 70002);
    }

    public function testTwoAttemptsCannotOwnSameOrder(): void
    {
        $hash = static fn(string $suffix): string => hash('sha256', $suffix);
        $first = $this->attempts->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            $hash('op-a'),
            $hash('actor'),
            $hash('selection')
        );
        $second = $this->attempts->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            $hash('op-b'),
            $hash('actor'),
            $hash('selection')
        );

        $this->attempts->attachOrder((int) $first['attempt_id'], 71001);
        $this->expectException(PersistenceConflictException::class);
        $this->attempts->attachOrder((int) $second['attempt_id'], 71001);
    }

    public function testParallelRepeatedAttemptUsesSingleOrder(): void
    {
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempt = $this->issueAttempt(OperationEntryPoint::PRODUCT, $submission->operationKeyHash);
        $service = OrderMaterializationTestHarness::buildService($this->orders, $this->attempts, $this->locks);
        $context = OrderMaterializationTestHarness::attemptContext($attempt);

        $first = $service->materializeAndBind($submission, $context, LockOwnerTokenGenerator::generate());
        $second = $service->materializeAndBind($submission, $context, LockOwnerTokenGenerator::generate());

        self::assertSame($first->orderId, $second->orderId);
        self::assertSame(1, $this->orders->addOrderCallCount());
    }

    /** @return array<string, mixed> */
    private function issueAttempt(string $entryPoint, string $operationKeyHash): array
    {
        $hash = static fn(string $suffix): string => hash('sha256', $suffix);

        return $this->attempts->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            $entryPoint,
            $operationKeyHash,
            $hash('actor'),
            $hash('selection')
        );
    }
}
