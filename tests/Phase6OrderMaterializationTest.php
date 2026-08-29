<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter;
use MtUniCredit\Tests\Support\InMemoryOrderCorrelationRepository;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use Opencart\System\Library\Extension\MtUniCredit\CartOrderGateway;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderDataBuilder;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductOrderGateway;
use PHPUnit\Framework\TestCase;

final class Phase6OrderMaterializationTest extends TestCase
{
    private InMemoryCheckoutOrderAdapter $orders;

    private InMemoryOrderCorrelationRepository $correlations;

    protected function setUp(): void
    {
        $this->orders = new InMemoryCheckoutOrderAdapter();
        $this->correlations = new InMemoryOrderCorrelationRepository();
    }

    public function testProductGatewayMaterialization(): void
    {
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempt = OrderMaterializationTestHarness::attemptContext([
            'attempt_id' => 101,
            'store_id'   => $submission->storeId,
        ]);
        $materializer = OrderMaterializationTestHarness::buildMaterializer($this->orders, $this->correlations);
        $created = (new ProductOrderGateway($materializer))->materialize($submission, $attempt);

        self::assertSame(PaymentIdentity::optionCode(), $created->paymentMethodCode);
        self::assertSame('', (string) ($this->orders->getOrder($created->orderId)['tracking'] ?? 'x'));
    }

    public function testCartGatewayMaterialization(): void
    {
        $submission = OrderMaterializationTestHarness::cartSubmission();
        $attempt = OrderMaterializationTestHarness::attemptContext([
            'attempt_id' => 102,
            'store_id'   => $submission->storeId,
        ]);
        $created = (new CartOrderGateway(
            OrderMaterializationTestHarness::buildMaterializer($this->orders, $this->correlations)
        ))->materialize($submission, $attempt);

        self::assertCount(2, $created->products);
    }

    public function testCheckoutReusesExistingOrderWithoutAddOrder(): void
    {
        $this->orders->seedExistingOrder(88001, [
            'store_id'        => PersistenceIntegrationHarness::TEST_STORE_ID,
            'total'           => 1200.0,
            'currency_code'   => 'BGN',
            'payment_method'  => PaymentIdentity::paymentMethod(),
            'order_status_id' => 0,
            'tracking'        => 'REAL-CARRIER-123',
        ], [
            ['order_product_id' => 1, 'order_id' => 88001, 'product_id' => 42, 'quantity' => 2, 'price' => 500.0, 'total' => 1000.0],
        ]);

        $submission = OrderMaterializationTestHarness::checkoutSubmissionForOrder(88001);
        $gateway = new \Opencart\System\Library\Extension\MtUniCredit\CheckoutExistingOrderGateway(
            $this->orders,
            new \Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderVerifier(),
            new \Opencart\System\Library\Extension\MtUniCredit\FinancingOrderStatusPolicy(
                OrderMaterializationTestHarness::TEST_AWAITING_STATUS_ID,
                OrderMaterializationTestHarness::TEST_VOID_STATUS_ID
            )
        );

        $created = $gateway->materialize(
            $submission,
            OrderMaterializationTestHarness::attemptContext(['attempt_id' => 1, 'store_id' => $submission->storeId])
        );

        self::assertSame(0, $this->orders->addOrderCallCount());
        self::assertSame('REAL-CARRIER-123', $this->orders->getOrder($created->orderId)['tracking']);
    }

    public function testCheckoutReusesOc41VoidedOrderAfterEditOrder(): void
    {
        // OC 4.1 editOrder() voids via config_void_status_id; active session.order_id often sits at void.
        $this->orders->seedExistingOrder(88002, [
            'store_id'        => PersistenceIntegrationHarness::TEST_STORE_ID,
            'total'           => 1200.0,
            'currency_code'   => 'BGN',
            'payment_method'  => PaymentIdentity::paymentMethod(),
            'order_status_id' => OrderMaterializationTestHarness::TEST_VOID_STATUS_ID,
            'tracking'        => 'KEEP',
        ], [
            ['order_product_id' => 1, 'order_id' => 88002, 'product_id' => 42, 'quantity' => 2, 'price' => 500.0, 'total' => 1000.0],
        ]);

        $submission = OrderMaterializationTestHarness::checkoutSubmissionForOrder(88002);
        $gateway = new \Opencart\System\Library\Extension\MtUniCredit\CheckoutExistingOrderGateway(
            $this->orders,
            new \Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderVerifier(),
            new \Opencart\System\Library\Extension\MtUniCredit\FinancingOrderStatusPolicy(
                OrderMaterializationTestHarness::TEST_AWAITING_STATUS_ID,
                OrderMaterializationTestHarness::TEST_VOID_STATUS_ID
            )
        );

        $created = $gateway->materialize(
            $submission,
            OrderMaterializationTestHarness::attemptContext(['attempt_id' => 2, 'store_id' => $submission->storeId])
        );

        self::assertSame(88002, $created->orderId);
        self::assertSame(0, $this->orders->addOrderCallCount());
        self::assertSame(OrderMaterializationTestHarness::TEST_VOID_STATUS_ID, $created->orderStatusId);
    }

    public function testCheckoutRejectsProcessingStatusNotReadyForReuse(): void
    {
        $this->orders->seedExistingOrder(88003, [
            'store_id'        => PersistenceIntegrationHarness::TEST_STORE_ID,
            'total'           => 1200.0,
            'currency_code'   => 'BGN',
            'payment_method'  => PaymentIdentity::paymentMethod(),
            'order_status_id' => 2,
        ], [
            ['order_product_id' => 1, 'order_id' => 88003, 'product_id' => 42, 'quantity' => 2, 'price' => 500.0, 'total' => 1000.0],
        ]);

        $submission = OrderMaterializationTestHarness::checkoutSubmissionForOrder(88003);
        $gateway = new \Opencart\System\Library\Extension\MtUniCredit\CheckoutExistingOrderGateway(
            $this->orders,
            new \Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderVerifier(),
            new \Opencart\System\Library\Extension\MtUniCredit\FinancingOrderStatusPolicy(
                OrderMaterializationTestHarness::TEST_AWAITING_STATUS_ID,
                OrderMaterializationTestHarness::TEST_VOID_STATUS_ID
            )
        );

        $this->expectException(\Opencart\System\Library\Extension\MtUniCredit\OrderMaterializationException::class);
        $this->expectExceptionMessage('Checkout order is not in a financing-ready status.');
        $gateway->materialize(
            $submission,
            OrderMaterializationTestHarness::attemptContext(['attempt_id' => 3, 'store_id' => $submission->storeId])
        );
    }

    public function testCrashRecoveryDoesNotCreateSecondOrder(): void
    {
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attemptId = 555;
        $attempt = OrderMaterializationTestHarness::attemptContext([
            'attempt_id' => $attemptId,
            'store_id'   => $submission->storeId,
        ]);
        $builder = new OpenCartOrderDataBuilder();
        $orderId = $this->orders->addOrder($builder->build($submission->orderDraft));
        $this->correlations->linkCreatedOrder($submission->storeId, $attemptId, $orderId);

        $recovered = (new ProductOrderGateway(
            OrderMaterializationTestHarness::buildMaterializer($this->orders, $this->correlations)
        ))->materialize($submission, $attempt);

        self::assertTrue($recovered->recovered);
        self::assertSame($orderId, $recovered->orderId);
        self::assertSame(1, $this->orders->addOrderCallCount());
    }

    public function testOrderBuilderLeavesBusinessFieldsEmpty(): void
    {
        $payload = (new OpenCartOrderDataBuilder())->build(
            OrderMaterializationTestHarness::productSubmission()->orderDraft
        );
        self::assertSame('', $payload['tracking']);
        self::assertSame('', $payload['comment']);
    }
}
