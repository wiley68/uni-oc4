<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptContext;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderStatusPolicy;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\OperationLockRepository;
use Opencart\System\Library\Extension\MtUniCredit\OrderCorrelationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Product/Cart order status must come from payment_mt_uni_credit_order_status_id only.
 */
final class Phase10BOrderStatusSourceTest extends TestCase
{
    public function testResolverUsesPaymentMethodStatusOnly(): void
    {
        self::assertSame(0, FinancingOrderStatusPolicy::resolveProductCartOrderStatusId(0));
        self::assertSame(7, FinancingOrderStatusPolicy::resolveProductCartOrderStatusId(7));
        // Non-default fixture — must not silently become config_order_status_id (typically 1).
        self::assertSame(25, FinancingOrderStatusPolicy::resolveProductCartOrderStatusId(25));
        self::assertNotSame(1, FinancingOrderStatusPolicy::resolveProductCartOrderStatusId(25));
    }

    public function testProductMaterializationAppliesPaymentConfiguredStatus(): void
    {
        if (!getenv('MT_UNI_CREDIT_INTEGRATION')) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for DB integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $attempts = new FinancingAttemptRepository($db);
        $locks = new OperationLockRepository($db);
        $orders = new InMemoryCheckoutOrderAdapter();
        $paymentStatus = 42; // non-default; proves not config_order_status_id
        $service = OrderMaterializationTestHarness::buildService(
            $orders,
            $attempts,
            $locks,
            new OrderCorrelationRepository($db),
            $paymentStatus
        );
        $submission = OrderMaterializationTestHarness::productSubmission(OperationEntryPoint::PRODUCT);
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId,
            OperationEntryPoint::PRODUCT,
            $submission->operationKeyHash,
            str_repeat('a', 64),
            $submission->selectionHash
        );
        $created = $service->materializeAndBind(
            $submission,
            new FinancingAttemptContext($row),
            LockOwnerTokenGenerator::generate()
        );
        self::assertSame($paymentStatus, $orders->lastOrderStatusId());
        self::assertSame($paymentStatus, $created->orderStatusId);
    }

    public function testCartMaterializationAppliesSamePaymentConfiguredStatus(): void
    {
        if (!getenv('MT_UNI_CREDIT_INTEGRATION')) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for DB integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $attempts = new FinancingAttemptRepository($db);
        $locks = new OperationLockRepository($db);
        $orders = new InMemoryCheckoutOrderAdapter();
        $paymentStatus = 42;
        $service = OrderMaterializationTestHarness::buildService(
            $orders,
            $attempts,
            $locks,
            new OrderCorrelationRepository($db),
            $paymentStatus
        );
        $submission = OrderMaterializationTestHarness::productSubmission(OperationEntryPoint::CART);
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId,
            OperationEntryPoint::CART,
            $submission->operationKeyHash,
            str_repeat('b', 64),
            $submission->selectionHash
        );
        $created = $service->materializeAndBind(
            $submission,
            new FinancingAttemptContext($row),
            LockOwnerTokenGenerator::generate()
        );
        self::assertSame($paymentStatus, $created->orderStatusId);
    }

    public function testCheckoutDoesNotApplyProductCartStatusInitializer(): void
    {
        $policy = new FinancingOrderStatusPolicy(42, 16);
        self::assertFalse($policy->shouldApplyProductCartStatus(OperationEntryPoint::CHECKOUT));
        self::assertTrue($policy->shouldApplyProductCartStatus(OperationEntryPoint::PRODUCT));
        self::assertTrue($policy->shouldApplyProductCartStatus(OperationEntryPoint::CART));

        $checkoutModel = str_replace("\r\n", "\n", (string) file_get_contents(
            dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_checkout.php'
        ));
        self::assertStringContainsString("new FinancingOrderStatusPolicy(\n            0,", $checkoutModel);
        self::assertStringContainsString('config_order_status_id', $checkoutModel);
        self::assertStringNotContainsString('PAYMENT_ORDER_STATUS_SETTING', $checkoutModel);
        self::assertStringNotContainsString('resolveProductCartOrderStatusId', $checkoutModel);
    }

    public function testDuplicateModuleStatusSettingRemovedFromAdminAndProduction(): void
    {
        $retired = 'module_mt_uni_credit_awaiting_financing_order_status_id';
        $label = 'Статус след Product/Cart локална поръчка';

        $twig = (string) file_get_contents(dirname(__DIR__) . '/admin/view/template/module/mt_uni_credit.twig');
        self::assertStringNotContainsString($retired, $twig);
        self::assertStringNotContainsString('entry_awaiting_financing_order_status', $twig);

        $bg = (string) file_get_contents(dirname(__DIR__) . '/admin/language/bg-bg/module/mt_uni_credit.php');
        self::assertStringNotContainsString($label, $bg);

        $defaults = (string) file_get_contents(dirname(__DIR__) . '/admin/model/module/mt_uni_credit.php');
        self::assertStringNotContainsString('AWAITING_FINANCING_ORDER_STATUS_SETTING', $defaults);
        self::assertStringContainsString($retired, $defaults); // cleanup DELETE only

        $controller = (string) file_get_contents(dirname(__DIR__) . '/admin/controller/module/mt_uni_credit.php');
        self::assertStringNotContainsString('AWAITING_FINANCING_ORDER_STATUS_SETTING', $controller);
        self::assertStringNotContainsString($retired, $controller);

        foreach (['mt_uni_credit_product.php', 'mt_uni_credit_cart.php'] as $file) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/' . $file);
            self::assertStringNotContainsString($retired, $src, $file);
            self::assertStringNotContainsString('config_order_status_id', $src, $file);
            self::assertStringContainsString('PAYMENT_ORDER_STATUS_SETTING', $src, $file);
        }

        $policy = (string) file_get_contents(dirname(__DIR__) . '/system/library/financing_order_status_policy.php');
        self::assertStringNotContainsString($retired, $policy);
        self::assertStringContainsString('resolveProductCartOrderStatusId', $policy);
    }

    public function testPaymentMethodOrderStatusFieldRemains(): void
    {
        $paymentTwig = (string) file_get_contents(dirname(__DIR__) . '/admin/view/template/payment/mt_uni_credit.twig');
        self::assertStringContainsString('payment_mt_uni_credit_order_status_id', $paymentTwig);
        self::assertStringContainsString('entry_order_status', $paymentTwig);

        $bg = (string) file_get_contents(dirname(__DIR__) . '/admin/language/bg-bg/payment/mt_uni_credit.php');
        self::assertStringContainsString('Състояние на поръчката', $bg);

        self::assertSame('payment_mt_uni_credit_order_status_id', ModuleConstants::PAYMENT_ORDER_STATUS_SETTING);
    }

    public function testAddHistoryRemainsStatusApi(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/system/library/order_materialization_service.php');
        self::assertStringContainsString('addHistory', $src);
        self::assertStringNotContainsString('editOrder', $src);
        self::assertStringContainsString('current === $statusId', $src);
    }
}
