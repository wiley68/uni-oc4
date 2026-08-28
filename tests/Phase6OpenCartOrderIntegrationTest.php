<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\OpenCartOrderIntegrationHarness;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderDataBuilder;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderMaterializer;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderVerifier;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\OperationLockRepository;
use Opencart\System\Library\Extension\MtUniCredit\OrderRecoveryMarker;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use PHPUnit\Framework\TestCase;

final class Phase6OpenCartOrderIntegrationTest extends TestCase
{
    private FinancingAttemptRepository $attempts;

    private OperationLockRepository $locks;

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
    }

    protected function tearDown(): void
    {
        OpenCartOrderIntegrationHarness::cleanupModuleTestOrders();
    }

    public function testRealProductOrderMaterialization(): void
    {
        $orders = OpenCartOrderIntegrationHarness::orders();
        $service = OrderMaterializationTestHarness::buildService($orders, $this->attempts, $this->locks);
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
        self::assertNotSame([], $row);
        self::assertSame(PersistenceIntegrationHarness::TEST_STORE_ID, (int) $row['store_id']);
        self::assertSame('BGN', $row['currency_code']);
        self::assertTrue(OrderRecoveryMarker::isModuleMarker((string) $row['tracking']));
        self::assertTrue(PaymentIdentity::matchesStoredPayment($row['payment_method']));
        self::assertCount(1, $orders->getProducts($created->orderId));
    }

    public function testRealCartOrderMaterialization(): void
    {
        $orders = OpenCartOrderIntegrationHarness::orders();
        $service = OrderMaterializationTestHarness::buildService($orders, $this->attempts, $this->locks);
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
        self::assertGreaterThanOrEqual(2, count($orders->getTotals($created->orderId)));
    }

    public function testRealCheckoutOrderReuse(): void
    {
        $orders = OpenCartOrderIntegrationHarness::orders();
        $builder = new OpenCartOrderDataBuilder();
        $existingId = $orders->addOrder($builder->build(
            OrderMaterializationTestHarness::productSubmission()->orderDraft,
            'mtuc:precheckout'
        ));
        $orders->addHistory($existingId, 0);

        $submission = OrderMaterializationTestHarness::checkoutSubmissionForOrder($existingId);
        $service = OrderMaterializationTestHarness::buildService($orders, $this->attempts, $this->locks);
        $attempt = $this->attempts->issueCheckoutAttempt(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            $submission->operationKeyHash,
            hash('sha256', 'actor-checkout'),
            $submission->selectionHash
        );

        $beforeCount = $this->countModuleRecoveryOrders($orders);
        $created = $service->materializeAndBind(
            $submission,
            OrderMaterializationTestHarness::attemptContext($attempt),
            LockOwnerTokenGenerator::generate()
        );
        $afterCount = $this->countModuleRecoveryOrders($orders);

        self::assertSame($existingId, $created->orderId);
        self::assertSame($beforeCount, $afterCount);
    }

    public function testCrashRecoveryAgainstRealOrders(): void
    {
        $orders = OpenCartOrderIntegrationHarness::orders();
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempt = $this->attempts->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            hash('sha256', 'crash-op'),
            hash('sha256', 'actor-crash'),
            hash('sha256', 'selection-crash')
        );
        $attemptId = (int) $attempt['attempt_id'];
        $marker = OrderRecoveryMarker::forAttempt(PersistenceIntegrationHarness::TEST_STORE_ID, $attemptId);
        $builder = new OpenCartOrderDataBuilder();
        $existingId = $orders->addOrder($builder->build($submission->orderDraft, $marker));

        $materializer = new OpenCartOrderMaterializer($orders, $builder, new OpenCartOrderVerifier());
        $recovered = $materializer->materializeNew(
            $submission,
            OrderMaterializationTestHarness::attemptContext($attempt)
        );

        self::assertSame($existingId, $recovered->orderId);
        self::assertTrue($recovered->recovered);
        self::assertSame(1, $this->countModuleRecoveryOrders($orders));
    }

    private function countModuleRecoveryOrders(\MtUniCredit\Tests\Support\SqlCheckoutOrderAdapter $orders): int
    {
        $db = OpenCartOrderIntegrationHarness::connection();
        $prefix = $db->getPrefix();
        $result = $db->query(
            "SELECT COUNT(*) AS c FROM `{$prefix}order` WHERE `tracking` LIKE 'mtuc:%'"
        );

        return (int) ($result->row['c'] ?? 0);
    }
}
