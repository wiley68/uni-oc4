<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\OpenCartOrderIntegrationHarness;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderDataBuilder;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\OperationLockRepository;
use Opencart\System\Library\Extension\MtUniCredit\OrderCorrelationRepository;
use Opencart\System\Library\Extension\MtUniCredit\OrderMaterializationService;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceConflictException;
use PHPUnit\Framework\TestCase;

final class Phase6OpenCartOrderIntegrationTest extends TestCase
{
    private FinancingAttemptRepository $attempts;

    private OperationLockRepository $locks;

    private OrderCorrelationRepository $correlations;

    protected function setUp(): void
    {
        if (!OpenCartOrderIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for OpenCart order integration tests.');
        }

        PersistenceIntegrationHarness::resetTables();
        OpenCartOrderIntegrationHarness::cleanupModuleTestOrders();

        $db = PersistenceIntegrationHarness::connection();
        $this->attempts = new FinancingAttemptRepository($db);
        $this->locks = new OperationLockRepository($db);
        $this->correlations = new OrderCorrelationRepository($db);
    }

    protected function tearDown(): void
    {
        OpenCartOrderIntegrationHarness::cleanupModuleTestOrders();
    }

    public function testRealProductOrderMaterialization(): void
    {
        $orders = OpenCartOrderIntegrationHarness::orders();
        $service = OrderMaterializationTestHarness::buildService(
            $orders,
            $this->attempts,
            $this->locks,
            $this->correlations
        );
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempt = $this->attempts->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            $submission->operationKeyHash,
            hash('sha256', 'actor'),
            $submission->selectionHash
        );

        $created = $service->materializeAndBind(
            $submission,
            OrderMaterializationTestHarness::attemptContext($attempt),
            LockOwnerTokenGenerator::generate()
        );

        $row = $orders->getOrder($created->orderId);
        self::assertSame('', (string) ($row['tracking'] ?? 'x'));
        self::assertSame('', (string) ($row['comment'] ?? 'x'));
        self::assertTrue(PaymentIdentity::matchesStoredPayment($row['payment_method']));
    }

    public function testRealCartOrderMaterialization(): void
    {
        $orders = OpenCartOrderIntegrationHarness::orders();
        $service = OrderMaterializationTestHarness::buildService(
            $orders,
            $this->attempts,
            $this->locks,
            $this->correlations
        );
        $submission = OrderMaterializationTestHarness::cartSubmission();
        $attempt = $this->attempts->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::CART,
            $submission->operationKeyHash,
            hash('sha256', 'actor-cart'),
            $submission->selectionHash,
            777,
            $submission->cartFingerprint
        );

        $created = $service->materializeAndBind(
            $submission,
            OrderMaterializationTestHarness::attemptContext($attempt),
            LockOwnerTokenGenerator::generate()
        );

        self::assertCount(2, $orders->getProducts($created->orderId));
        self::assertSame('', (string) $orders->getOrder($created->orderId)['tracking']);
    }

    public function testRealCheckoutOrderReuse(): void
    {
        $orders = OpenCartOrderIntegrationHarness::orders();
        $existingId = $orders->addOrder(
            (new OpenCartOrderDataBuilder())->build(OrderMaterializationTestHarness::productSubmission()->orderDraft)
        );
        $orders->addHistory($existingId, 0);

        $submission = OrderMaterializationTestHarness::checkoutSubmissionForOrder($existingId);
        $service = OrderMaterializationTestHarness::buildService(
            $orders,
            $this->attempts,
            $this->locks,
            $this->correlations
        );
        $attempt = $this->attempts->issueCheckoutAttempt(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            $submission->operationKeyHash,
            hash('sha256', 'actor-checkout'),
            $submission->selectionHash
        );

        $before = $this->countOpenCartOrders($orders);
        $created = $service->materializeAndBind(
            $submission,
            OrderMaterializationTestHarness::attemptContext($attempt),
            LockOwnerTokenGenerator::generate()
        );

        self::assertSame($existingId, $created->orderId);
        self::assertSame($before, $this->countOpenCartOrders($orders));
    }

    public function testExactCrashWindowRecoveryViaCorrelationTable(): void
    {
        $orders = OpenCartOrderIntegrationHarness::orders();
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempt = $this->attempts->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            hash('sha256', 'crash-window-op'),
            hash('sha256', 'actor-crash-window'),
            hash('sha256', 'selection-crash-window')
        );
        $attemptId = (int) $attempt['attempt_id'];
        $this->attempts->transition($attemptId, FinancingAttemptState::ISSUED, FinancingAttemptState::ORDER_CREATING);

        $orderId = $orders->addOrder((new OpenCartOrderDataBuilder())->build($submission->orderDraft));
        $this->correlations->linkCreatedOrder(PersistenceIntegrationHarness::TEST_STORE_ID, $attemptId, $orderId);

        $retryMaterializer = OrderMaterializationTestHarness::buildMaterializer($orders, $this->correlations);
        $beforeCount = $this->countOpenCartOrders($orders);
        $recovered = $retryMaterializer->materializeNew(
            $submission,
            OrderMaterializationTestHarness::attemptContext($attempt)
        );
        self::assertSame($beforeCount, $this->countOpenCartOrders($orders));

        self::assertTrue($recovered->recovered);
        self::assertSame($orderId, $recovered->orderId);
        self::assertNull($this->attempts->findById($attemptId)['order_id']);

        $service = $this->buildFreshService($orders);
        $bound = $service->materializeAndBind(
            $submission,
            OrderMaterializationTestHarness::attemptContext($attempt),
            LockOwnerTokenGenerator::generate()
        );
        self::assertSame($orderId, $bound->orderId);
        self::assertSame($orderId, (int) $this->attempts->findById($attemptId)['order_id']);
    }

    public function testRecoveryIsStoreAndAttemptScoped(): void
    {
        $orders = OpenCartOrderIntegrationHarness::orders();
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempt = $this->attempts->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            hash('sha256', 'scope-op'),
            hash('sha256', 'actor-scope'),
            hash('sha256', 'selection-scope')
        );
        $attemptId = (int) $attempt['attempt_id'];
        $orderId = $orders->addOrder((new OpenCartOrderDataBuilder())->build($submission->orderDraft));
        $this->correlations->linkCreatedOrder(PersistenceIntegrationHarness::TEST_STORE_ID, $attemptId, $orderId);

        self::assertNull($this->correlations->findOrderIdByAttempt(PersistenceIntegrationHarness::TEST_STORE_ID_B, $attemptId));

        $otherAttempt = $this->attempts->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            hash('sha256', 'scope-op-b'),
            hash('sha256', 'actor-scope-b'),
            hash('sha256', 'selection-scope-b')
        );
        $this->expectException(PersistenceConflictException::class);
        $this->correlations->linkCreatedOrder(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            (int) $otherAttempt['attempt_id'],
            $orderId
        );
    }

    private function buildFreshService(\MtUniCredit\Tests\Support\SqlCheckoutOrderAdapter $orders): OrderMaterializationService
    {
        return OrderMaterializationTestHarness::buildService(
            $orders,
            $this->attempts,
            $this->locks,
            $this->correlations
        );
    }

    private function countOpenCartOrders(\MtUniCredit\Tests\Support\SqlCheckoutOrderAdapter $orders): int
    {
        $db = OpenCartOrderIntegrationHarness::connection();
        $prefix = $db->getPrefix();
        $result = $db->query(
            "SELECT COUNT(*) AS c FROM `{$prefix}order`
             WHERE `store_id` = " . PersistenceIntegrationHarness::TEST_STORE_ID . "
               AND `payment_method` LIKE '%" . $db->escape(PaymentIdentity::optionCode()) . "%'"
        );

        return (int) ($result->row['c'] ?? 0);
    }
}
