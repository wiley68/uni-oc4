<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use MtUniCredit\Tests\Support\ProductFinancingTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutCpFailureOrderVisibility;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutOrderCartParity;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutSessionOrderGuard;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelErrorClass;
use Opencart\System\Library\Extension\MtUniCredit\CpHttpException;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState;
use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderStatusPolicy;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OperationLockRepository;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingSubmissionService;
use Opencart\System\Library\Extension\MtUniCredit\ProductOperationIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductSubmissionIssuer;
use PHPUnit\Framework\TestCase;

/**
 * Runtime remediation: empty-phone CP 422 taxonomy + Checkout CP-failure visibility.
 */
final class Phase10BCheckoutCpFailureVisibilityTest extends TestCase
{
    private FinancingAttemptRepository $attempts;

    protected function setUp(): void
    {
        if (!getenv('MT_UNI_CREDIT_INTEGRATION')) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for DB integration tests.');
        }
        if (str_contains($this->name(), 'VisibilityUnit')
            || str_contains($this->name(), 'ReuseEligible')
            || str_contains($this->name(), 'CartChange')
            || str_contains($this->name(), 'Wires')
            || str_contains($this->name(), 'ProductCart')
            || str_contains($this->name(), 'CpHttpException')
        ) {
            return;
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $this->attempts = new FinancingAttemptRepository($db);
    }

    public function testGenericCp422MapsToRejectedNotTransport(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        // Application rejection (e.g. historical empty-phone 422) must not become transport_failed.
        $transport->enqueueJson(422, [
            'message' => 'Телефонният номер е задължителен.',
            'errors'  => [
                'phone' => ['Телефонният номер е задължителен.'],
            ],
        ]);
        $orders = new InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);

        try {
            $this->submitProduct($service);
            self::fail('Expected CP rejection');
        } catch (ProductFinancingFlowException $exception) {
            self::assertSame(ControlPanelErrorClass::REJECTED, $exception->errorCode());
            self::assertNotSame(ControlPanelErrorClass::TRANSPORT_FAILED, $exception->errorCode());
        }

        $row = $this->attempts->findByOrderId(ProductFinancingTestHarness::STORE_ID, $orders->lastOrderId());
        self::assertNotNull($row);
        self::assertSame(FinancingAttemptState::CP_FAILED_RETRYABLE, $row['state']);
        self::assertSame(ControlPanelErrorClass::REJECTED, $row['last_error_class']);
        self::assertNull($row['control_panel_order_id']);
    }

    public function testCpHttpException422IsNotConnectionFailure(): void
    {
        $exception = new CpHttpException(422, [
            'message' => 'Телефонният номер е задължителен.',
            'errors'  => ['phone' => ['Телефонният номер е задължителен.']],
        ]);
        self::assertFalse($exception->isTransient());
        self::assertSame(422, $exception->getStatusCode());
        self::assertFalse($exception instanceof \Opencart\System\Library\Extension\MtUniCredit\CpConnectionException);
    }

    public function testVisibilityUnitAppliesConfigOrderStatusWhenStatusZero(): void
    {
        $orders = new InMemoryCheckoutOrderAdapter();
        $orderId = $orders->addOrder([
            'store_id'       => 0,
            'total'          => 1020.0,
            'currency_code'  => 'EUR',
            'payment_method' => PaymentIdentity::paymentMethod(),
            'products'       => [['product_id' => 1, 'quantity' => 1, 'name' => 'X', 'price' => 1020, 'total' => 1020]],
            'totals'         => [['code' => 'total', 'value' => 1020.0]],
        ]);
        self::assertSame(0, (int) $orders->getOrder($orderId)['order_status_id']);

        self::assertTrue(CheckoutCpFailureOrderVisibility::ensureVisible($orders, $orderId, 1));
        self::assertSame(1, (int) $orders->getOrder($orderId)['order_status_id']);
        self::assertSame(1, $orders->historyCountFor($orderId));
    }

    public function testVisibilityUnitPreservesExistingVisibleStatus(): void
    {
        $orders = new InMemoryCheckoutOrderAdapter();
        $orderId = $orders->addOrder([
            'store_id'       => 0,
            'total'          => 100.0,
            'currency_code'  => 'EUR',
            'payment_method' => PaymentIdentity::paymentMethod(),
            'products'       => [['product_id' => 1, 'quantity' => 1, 'name' => 'X', 'price' => 100, 'total' => 100]],
            'totals'         => [['code' => 'total', 'value' => 100.0]],
        ]);
        $orders->addHistory($orderId, 2);

        self::assertFalse(CheckoutCpFailureOrderVisibility::ensureVisible($orders, $orderId, 1));
        self::assertSame(2, (int) $orders->getOrder($orderId)['order_status_id']);
        self::assertSame(1, $orders->historyCountFor($orderId));
    }

    public function testPendingFailureStatusIsReuseEligible(): void
    {
        $policy = new FinancingOrderStatusPolicy(0, 16, 1);
        self::assertTrue($policy->isCheckoutReuseAllowedStatus(0));
        self::assertTrue($policy->isCheckoutReuseAllowedStatus(16));
        self::assertTrue($policy->isCheckoutReuseAllowedStatus(1));
        self::assertFalse($policy->isCheckoutReuseAllowedStatus(2));
    }

    public function testCartChangeAfterPendingFailureStillInvalidatesSessionOrder(): void
    {
        $order = [
            'order_id'        => 580,
            'total'           => 1020.0,
            'currency_code'   => 'EUR',
            'order_status_id' => 1,
        ];
        $orderProducts = [
            ['order_product_id' => 1, 'product_id' => 40, 'quantity' => 1],
        ];
        $cartProducts = [
            [
                'product_id' => 40,
                'quantity'   => 1,
                'option'     => [['product_option_value_id' => 99]],
            ],
        ];
        $getOptions = static fn(): array => [['product_option_value_id' => 1]];
        $session = ['order_id' => 580];

        self::assertTrue(CheckoutSessionOrderGuard::reconcileSessionOrder(
            $session,
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            1030.0,
            'EUR'
        ));
        self::assertArrayNotHasKey('order_id', $session);
        self::assertFalse(CheckoutOrderCartParity::matchesCurrentCart(
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            1030.0,
            'EUR'
        ));
    }

    public function testCheckoutModelWiresFailureVisibilityStatus(): void
    {
        $model = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_checkout.php');
        self::assertStringContainsString('config_order_status_id', $model);
        $service = (string) file_get_contents(dirname(__DIR__) . '/system/library/checkout_financing_submission_service.php');
        self::assertStringContainsString('CheckoutCpFailureOrderVisibility', $service);
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testProductCartModelsStillAvoidConfigOrderStatusFallback(): void
    {
        foreach (['mt_uni_credit_product.php', 'mt_uni_credit_cart.php'] as $file) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/' . $file);
            self::assertStringNotContainsString('config_order_status_id', $src, $file);
            self::assertStringContainsString('PAYMENT_ORDER_STATUS_SETTING', $src, $file);
        }
    }

    private function submitProduct(ProductFinancingSubmissionService $service): \Opencart\System\Library\Extension\MtUniCredit\ProductFinancingResult
    {
        $line = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 42, 1, []);
        $actor = ProductFinancingTestHarness::actorBinding();
        $scheme = ProductFinancingTestHarness::defaultSchemeSelection();
        $selection = ProductFinancingTestHarness::selectionHash(
            $line,
            $scheme['scheme_key'],
            $scheme['scheme_type'],
            $scheme['kop_code'],
            $scheme['months'],
            $scheme['filter_id'],
            $scheme['first_installment'],
            $actor
        );
        $operation = ProductOperationIdentity::hash(ProductFinancingTestHarness::STORE_ID, 42, [], 1, 'BGN');
        $attempt = (new ProductSubmissionIssuer($this->attempts, new \Opencart\System\Library\Extension\MtUniCredit\PersistenceClock()))
            ->issueOrReuse(ProductFinancingTestHarness::STORE_ID, $operation, $actor, $selection);
        $token = (string) $attempt['submission_token'];

        return $service->submit(
            ProductFinancingTestHarness::shop(),
            ProductFinancingTestHarness::STORE_ID,
            $token,
            $actor,
            'sess-a',
            0,
            1,
            42,
            1,
            [],
            'BGN',
            'standard',
            $scheme['scheme_type'],
            $scheme['kop_code'],
            $scheme['months'],
            $scheme['filter_id'],
            $scheme['scheme_key'],
            $scheme['first_installment'],
            ProductFinancingTestHarness::validPostedCustomer(),
            'test-unicid',
            '2026-08-28 12:00:00',
            1,
            'bg-bg',
            1,
            1.0,
            'Store',
            'https://example.test/',
            'INV-',
            \Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator::generate()
        );
    }
}
