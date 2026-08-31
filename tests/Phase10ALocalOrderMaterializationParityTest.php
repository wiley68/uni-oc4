<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderStatusPolicy;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Phase 10A — Product/Cart durable local-order boundary (parity with Checkout).
 * Status source (remediated): UniCredit payment-method order status only.
 */
final class Phase10ALocalOrderMaterializationParityTest extends TestCase
{
    public function testProductCartStatusResolvesFromPaymentMethodSetting(): void
    {
        self::assertSame(0, FinancingOrderStatusPolicy::resolveProductCartOrderStatusId(0));
        self::assertSame(1, FinancingOrderStatusPolicy::resolveProductCartOrderStatusId(1));
        self::assertSame(25, FinancingOrderStatusPolicy::resolveProductCartOrderStatusId(25));
    }

    public function testProductAndCartModelsWirePaymentOrderStatus(): void
    {
        foreach (['mt_uni_credit_product.php', 'mt_uni_credit_cart.php'] as $file) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/' . $file);
            self::assertStringContainsString(
                'FinancingOrderStatusPolicy::resolveProductCartOrderStatusId',
                $src,
                $file
            );
            self::assertStringContainsString('PAYMENT_ORDER_STATUS_SETTING', $src, $file);
            self::assertStringNotContainsString('config_order_status_id', $src, $file);
            self::assertStringNotContainsString(
                'module_mt_uni_credit_awaiting_financing_order_status_id',
                $src,
                $file
            );
        }
        $checkout = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_checkout.php');
        self::assertStringNotContainsString('resolveProductCartOrderStatusId', $checkout);
        self::assertStringNotContainsString('PAYMENT_ORDER_STATUS_SETTING', $checkout);
    }

    public function testAdminNoLongerExposesDuplicateAwaitingFinancingOrderStatus(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__) . '/admin/model/module/mt_uni_credit.php');
        self::assertStringNotContainsString('AWAITING_FINANCING_ORDER_STATUS_SETTING', $defaults);
        $twig = (string) file_get_contents(dirname(__DIR__) . '/admin/view/template/module/mt_uni_credit.twig');
        self::assertStringNotContainsString('module_mt_uni_credit_awaiting_financing_order_status_id', $twig);
        $controller = (string) file_get_contents(dirname(__DIR__) . '/admin/controller/module/mt_uni_credit.php');
        self::assertStringNotContainsString('AWAITING_FINANCING_ORDER_STATUS_SETTING', $controller);
        self::assertSame('payment_mt_uni_credit_order_status_id', ModuleConstants::PAYMENT_ORDER_STATUS_SETTING);
    }

    public function testMaterializationAppliesStatusForProductAndCartOnly(): void
    {
        $policy = new FinancingOrderStatusPolicy(1, 16);
        self::assertTrue($policy->shouldApplyProductCartStatus(OperationEntryPoint::PRODUCT));
        self::assertTrue($policy->shouldApplyProductCartStatus(OperationEntryPoint::CART));
        self::assertFalse($policy->shouldApplyProductCartStatus(OperationEntryPoint::CHECKOUT));
    }

    public function testCheckoutConfirmDoesNotCallAddOrder(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php');
        self::assertStringNotContainsString('->addOrder(', $controller);
        $service = (string) file_get_contents(dirname(__DIR__) . '/system/library/checkout_financing_submission_service.php');
        self::assertStringNotContainsString('->addOrder(', $service);
    }

    public function testMaterializationServiceAppliesInterimStatusOnBoundRetry(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/system/library/order_materialization_service.php');
        self::assertStringContainsString('ensureInterimVisibleStatus', $src);
        self::assertStringContainsString('boundOrderId !== null', $src);
        self::assertStringContainsString('current === $statusId', $src);
    }

    public function testProductCartOrderTotalsUseOpencartExtension(): void
    {
        foreach ([
            'open_cart_product_order_draft_builder.php',
            'open_cart_cart_order_products_builder.php',
        ] as $file) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/system/library/' . $file);
            self::assertStringContainsString("'extension' => 'opencart'", $src, $file);
            self::assertStringNotContainsString("'extension' => ''", $src, $file);
        }
    }

    public function testPhase10ADoesNotIntroduceCpOrSmartUcfLifecycle(): void
    {
        $paths = [
            dirname(__DIR__) . '/system/library/financing_order_status_policy.php',
            dirname(__DIR__) . '/system/library/order_materialization_service.php',
            dirname(__DIR__) . '/admin/controller/module/mt_uni_credit.php',
        ];
        $forbidden = ['SmartUcfSession', 'Process1', 'Process2', 'bank_redirect', 'sucfOnlineSessionStart'];
        foreach ($paths as $file) {
            $contents = (string) file_get_contents($file);
            foreach ($forbidden as $marker) {
                self::assertStringNotContainsString($marker, $contents, $file);
            }
        }
    }

    public function testPaymentIdentityConstantIsCanonical(): void
    {
        self::assertSame('mt_uni_credit.mt_uni_credit', ModuleConstants::PAYMENT_OPTION_CODE);
        self::assertSame('mt_uni_credit.mt_uni_credit', PaymentIdentity::optionCode());
    }
}
