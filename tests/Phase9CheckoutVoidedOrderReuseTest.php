<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderStatusPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Regression: OC4.1 editOrder voids active checkout orders before rewrite.
 * Operator technical_failure: CheckoutExistingOrderGateway rejected status 16 (Voided).
 */
final class Phase9CheckoutVoidedOrderReuseTest extends TestCase
{
    public function testVoidedCheckoutOrderIsReuseAllowed(): void
    {
        $policy = new FinancingOrderStatusPolicy(25, 16);
        self::assertTrue($policy->isCheckoutReuseAllowedStatus(0));
        self::assertTrue($policy->isCheckoutReuseAllowedStatus(16));
        self::assertTrue($policy->isCheckoutReuseAllowedStatus(25));
        self::assertFalse($policy->isCheckoutReuseAllowedStatus(2));
        self::assertFalse($policy->isCheckoutReuseAllowedStatus(1));
    }

    public function testWithoutConfiguredVoidOnlyZeroAndAwaitingAllowed(): void
    {
        $policy = new FinancingOrderStatusPolicy(25, 0);
        self::assertTrue($policy->isCheckoutReuseAllowedStatus(0));
        self::assertTrue($policy->isCheckoutReuseAllowedStatus(25));
        self::assertFalse($policy->isCheckoutReuseAllowedStatus(16));
    }

    public function testCheckoutModelWiresConfigVoidStatusId(): void
    {
        $model = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_checkout.php');
        self::assertStringContainsString("config_void_status_id", $model);
        self::assertStringContainsString('new FinancingOrderStatusPolicy(', $model);
    }

    public function testConfirmRetryAllowsOrderCreatingState(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__) . '/system/library/checkout_financing_submission_service.php');
        self::assertStringContainsString('FinancingAttemptState::ORDER_CREATING', $service);
        self::assertStringContainsString('FinancingAttemptState::VALIDATING', $service);
    }

    public function testTechnicalFailureLogsPreviousMaterializationException(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php');
        self::assertStringContainsString("errorCode() === 'technical_failure'", $controller);
        self::assertStringContainsString('entry_point=checkout', $controller);
        self::assertStringContainsString('order_id=%d', $controller);
        self::assertStringContainsString('store_id=%d', $controller);
    }
}
