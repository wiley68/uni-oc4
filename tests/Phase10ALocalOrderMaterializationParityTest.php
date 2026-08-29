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
 */
final class Phase10ALocalOrderMaterializationParityTest extends TestCase
{
    public function testAwaitingStatusFallsBackToConfigOrderStatusPending(): void
    {
        self::assertSame(0, FinancingOrderStatusPolicy::resolveConfiguredAwaitingStatusId(0, 0));
        self::assertSame(1, FinancingOrderStatusPolicy::resolveConfiguredAwaitingStatusId(0, 1));
        self::assertSame(1, FinancingOrderStatusPolicy::resolveConfiguredAwaitingStatusId(1, 2));
        self::assertSame(25, FinancingOrderStatusPolicy::resolveConfiguredAwaitingStatusId(25, 0));
    }

    public function testProductAndCartModelsWireAwaitingStatusResolver(): void
    {
        foreach (['mt_uni_credit_product.php', 'mt_uni_credit_cart.php', 'mt_uni_credit_checkout.php'] as $file) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/' . $file);
            self::assertStringContainsString(
                'FinancingOrderStatusPolicy::resolveConfiguredAwaitingStatusId',
                $src,
                $file
            );
            self::assertStringContainsString('config_order_status_id', $src, $file);
            self::assertStringNotContainsString(
                "get('payment_mt_uni_credit_order_status_id')",
                $src,
                $file
            );
        }
    }

    public function testAdminExposesAwaitingFinancingOrderStatus(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__) . '/admin/model/module/mt_uni_credit.php');
        self::assertStringContainsString('AWAITING_FINANCING_ORDER_STATUS_SETTING', $defaults);
        $twig = (string) file_get_contents(dirname(__DIR__) . '/admin/view/template/module/mt_uni_credit.twig');
        self::assertStringContainsString('module_mt_uni_credit_awaiting_financing_order_status_id', $twig);
        $controller = (string) file_get_contents(dirname(__DIR__) . '/admin/controller/module/mt_uni_credit.php');
        self::assertStringContainsString('AWAITING_FINANCING_ORDER_STATUS_SETTING', $controller);
    }

    public function testMaterializationAppliesAwaitingStatusForProductAndCartOnly(): void
    {
        $policy = new FinancingOrderStatusPolicy(1, 16);
        self::assertTrue($policy->shouldApplyAwaitingStatus(OperationEntryPoint::PRODUCT));
        self::assertTrue($policy->shouldApplyAwaitingStatus(OperationEntryPoint::CART));
        self::assertFalse($policy->shouldApplyAwaitingStatus(OperationEntryPoint::CHECKOUT));
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
        // Idempotent: skip when already at interim status.
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
            dirname(__DIR__) . '/system/library/product_financing_submission_service.php',
            dirname(__DIR__) . '/system/library/cart_financing_submission_service.php',
            dirname(__DIR__) . '/admin/controller/module/mt_uni_credit.php',
        ];
        $forbidden = ['SmartUcfSession', 'SmartUCF', 'Process1', 'Process2', 'cp_submitting', 'bank_redirect'];
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
