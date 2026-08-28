<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutExistingOrderGateway;
use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderStatusPolicy;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderDataBuilder;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderMaterializer;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderVerifier;
use Opencart\System\Library\Extension\MtUniCredit\OrderMaterializationException;
use Opencart\System\Library\Extension\MtUniCredit\OrderRecoveryMarker;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\CartOrderGateway;
use Opencart\System\Library\Extension\MtUniCredit\ProductOrderGateway;
use PHPUnit\Framework\TestCase;

final class Phase6OrderMaterializationTest extends TestCase
{
    private InMemoryCheckoutOrderAdapter $orders;

    protected function setUp(): void
    {
        $this->orders = new InMemoryCheckoutOrderAdapter();
    }

    public function testProductGatewayMaterialization(): void
    {
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempt = OrderMaterializationTestHarness::attemptContext([
            'attempt_id' => 101,
            'store_id'   => $submission->storeId,
        ]);
        $materializer = $this->materializer();
        $created = (new ProductOrderGateway($materializer))
            ->materialize($submission, $attempt);

        self::assertSame(PaymentIdentity::optionCode(), $created->paymentMethodCode);
        self::assertSame(1200.0, $created->total);
        self::assertSame('BGN', $created->currencyCode);
        self::assertSame(2, $created->products[0]['quantity']);
    }

    public function testCartGatewayMaterialization(): void
    {
        $submission = OrderMaterializationTestHarness::cartSubmission();
        $attempt = OrderMaterializationTestHarness::attemptContext([
            'attempt_id' => 102,
            'store_id'   => $submission->storeId,
        ]);
        $created = (new CartOrderGateway($this->materializer()))
            ->materialize($submission, $attempt);

        self::assertCount(2, $created->products);
        self::assertNotEmpty($created->totals);
    }

    public function testCheckoutReusesExistingOrderWithoutAddOrder(): void
    {
        $this->orders->seedExistingOrder(88001, [
            'store_id'        => PersistenceIntegrationHarness::TEST_STORE_ID,
            'total'           => 1200.0,
            'currency_code'   => 'BGN',
            'payment_method'  => PaymentIdentity::paymentMethod(),
            'order_status_id' => 0,
        ], [
            ['order_product_id' => 1, 'order_id' => 88001, 'product_id' => 42, 'quantity' => 2, 'price' => 500.0, 'total' => 1000.0],
        ], [
            ['code' => 'total', 'title' => 'Total', 'value' => 1200.0, 'sort_order' => 9],
        ]);

        $submission = OrderMaterializationTestHarness::checkoutSubmissionForOrder(88001);
        $gateway = new CheckoutExistingOrderGateway(
            $this->orders,
            new OpenCartOrderVerifier(),
            new FinancingOrderStatusPolicy(OrderMaterializationTestHarness::TEST_AWAITING_STATUS_ID)
        );

        $created = $gateway->materialize(
            $submission,
            OrderMaterializationTestHarness::attemptContext(['attempt_id' => 1, 'store_id' => $submission->storeId])
        );

        self::assertSame(0, $this->orders->addOrderCallCount());
        self::assertSame(88001, $created->orderId);
    }

    public function testCheckoutMissingOrderRejected(): void
    {
        $submission = OrderMaterializationTestHarness::checkoutSubmissionForOrder(88002);
        $gateway = new CheckoutExistingOrderGateway(
            $this->orders,
            new OpenCartOrderVerifier(),
            new FinancingOrderStatusPolicy(OrderMaterializationTestHarness::TEST_AWAITING_STATUS_ID)
        );

        $this->expectException(OrderMaterializationException::class);
        $gateway->materialize(
            $submission,
            OrderMaterializationTestHarness::attemptContext(['attempt_id' => 1, 'store_id' => $submission->storeId])
        );
    }

    public function testCheckoutWrongStoreRejected(): void
    {
        $this->orders->seedExistingOrder(88003, [
            'store_id'        => 1,
            'total'           => 1200.0,
            'currency_code'   => 'BGN',
            'payment_method'  => PaymentIdentity::paymentMethod(),
            'order_status_id' => 0,
        ], [
            ['order_product_id' => 1, 'order_id' => 88003, 'product_id' => 42, 'quantity' => 2, 'price' => 500.0, 'total' => 1000.0],
        ]);

        $submission = OrderMaterializationTestHarness::checkoutSubmissionForOrder(88003);
        $gateway = new CheckoutExistingOrderGateway(
            $this->orders,
            new OpenCartOrderVerifier(),
            new FinancingOrderStatusPolicy(OrderMaterializationTestHarness::TEST_AWAITING_STATUS_ID)
        );

        $this->expectException(OrderMaterializationException::class);
        $gateway->materialize(
            $submission,
            OrderMaterializationTestHarness::attemptContext(['attempt_id' => 1, 'store_id' => $submission->storeId])
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
        $materializer = $this->materializer();
        $marker = OrderRecoveryMarker::forAttempt($submission->storeId, $attemptId);
        $orderId = $this->orders->addOrder($builder->build($submission->orderDraft, $marker));

        $recovered = (new ProductOrderGateway($materializer))->materialize($submission, $attempt);

        self::assertTrue($recovered->recovered);
        self::assertSame($orderId, $recovered->orderId);
        self::assertSame(1, $this->orders->addOrderCallCount());
    }

    public function testPaymentIdentityStoredConsistently(): void
    {
        self::assertSame('mt_uni_credit.mt_uni_credit', PaymentIdentity::optionCode());
        self::assertTrue(PaymentIdentity::matchesStoredPayment(PaymentIdentity::paymentMethod()));
    }

    private function materializer(): OpenCartOrderMaterializer
    {
        return new OpenCartOrderMaterializer(
            $this->orders,
            new OpenCartOrderDataBuilder(),
            new OpenCartOrderVerifier()
        );
    }
}
