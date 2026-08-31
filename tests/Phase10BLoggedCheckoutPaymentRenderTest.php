<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\CheckoutLiveGrandTotal;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutOrderCartParity;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutSessionOrderGuard;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Logged Checkout payment UI: UniCredit panel must render when confirm creates an order
 * with paid shipping (flat). Guest free-shipping path previously masked a false parity miss.
 */
final class Phase10BLoggedCheckoutPaymentRenderTest extends TestCase
{
    public function testPaidShippingOrderMatchesWhenCheckoutGrandTotalUsed(): void
    {
        // Operator evidence: logged order 597 — merchandise 999.996 + flat 5.00 = 1004.996
        $order = [
            'order_id'        => 597,
            'total'           => 1004.996,
            'currency_code'   => 'EUR',
            'customer_id'     => 1,
            'order_status_id' => 0,
        ];
        $orderProducts = [
            ['order_product_id' => 1, 'product_id' => 40, 'quantity' => 1],
        ];
        $cartProducts = [
            ['product_id' => 40, 'quantity' => 1, 'option' => []],
        ];
        $getOptions = static fn(): array => [];

        $merchandiseOnly = 999.996;
        self::assertFalse(
            CheckoutOrderCartParity::matchesCurrentCart(
                $order,
                $orderProducts,
                $getOptions,
                $cartProducts,
                $merchandiseOnly,
                'EUR'
            ),
            'cart->getTotal() (no shipping) must not be treated as order.total parity'
        );

        $checkoutGrandTotal = 1004.996;
        self::assertTrue(CheckoutOrderCartParity::matchesCurrentCart(
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            $checkoutGrandTotal,
            'EUR'
        ));
        self::assertFalse(CheckoutSessionOrderGuard::shouldInvalidateForCurrentCart(
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            $checkoutGrandTotal,
            'EUR'
        ));
    }

    public function testGuestFreeShippingStillMatches(): void
    {
        $order = [
            'order_id'      => 592,
            'total'         => 1029.996,
            'currency_code' => 'EUR',
            'customer_id'   => 0,
        ];
        $orderProducts = [
            ['order_product_id' => 1, 'product_id' => 40, 'quantity' => 1],
        ];
        $cartProducts = [
            ['product_id' => 40, 'quantity' => 1, 'option' => []],
        ];
        $getOptions = static fn(): array => [];

        self::assertTrue(CheckoutOrderCartParity::matchesCurrentCart(
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            1029.996,
            'EUR'
        ));
    }

    public function testLiveGrandTotalUsesRunnerResultNotMerchandise(): void
    {
        $runner = static function (array &$totals, array &$taxes, &$total): void {
            $totals[] = ['code' => 'sub_total', 'value' => 999.99];
            $totals[] = ['code' => 'shipping', 'value' => 5.0];
            $total = 1004.99;
        };

        self::assertSame(1004.99, CheckoutLiveGrandTotal::compute($runner, []));
    }

    public function testPaymentIndexRequiresResolvedOrderBeforeHtml(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php'
        );
        self::assertStringContainsString('resolveSessionOrder()', $controller);
        self::assertStringContainsString("return '';", $controller);
        self::assertStringContainsString('mt_uni_credit_checkout.js', $controller);

        $model = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_checkout.php'
        );
        self::assertStringContainsString('liveCheckoutGrandTotal()', $model);
        self::assertStringContainsString('CheckoutLiveGrandTotal::compute', $model);
        self::assertStringNotContainsString(
            '$cartTotal = (float) $this->cart->getTotal();',
            $model
        );

        $event = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_checkout_session_order.php'
        );
        self::assertStringContainsString('CheckoutLiveGrandTotal::compute', $event);
        self::assertStringNotContainsString('cart->getTotal()', $event);
    }

    public function testSelectedPaymentOptionCodeUnchangedForLoggedAndGuest(): void
    {
        self::assertSame('mt_uni_credit.mt_uni_credit', PaymentIdentity::optionCode());
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testConfirmAjaxInsertsPaymentIntoCheckoutPaymentContainer(): void
    {
        $confirmTwig = (string) file_get_contents(
            '/var/www/open40.avalonbg.com/catalog/view/template/checkout/confirm.twig'
        );
        self::assertStringContainsString('id="checkout-payment"', $confirmTwig);
        self::assertStringContainsString('{{ payment }}', $confirmTwig);

        $paymentTwig = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/template/payment/mt_uni_credit.twig'
        );
        self::assertStringContainsString('id="mt-uni-credit-checkout-root"', $paymentTwig);
        self::assertStringContainsString('data-mtuc-checkout-helper', $paymentTwig);
        self::assertStringContainsString('data-mtuc-schemes', $paymentTwig);
        self::assertStringContainsString('data-mtuc-submit', $paymentTwig);

        $js = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js'
        );
        self::assertMatchesRegularExpression('/getElementById\([\'"]checkout-confirm[\'"]\)/', $js);
        self::assertStringContainsString('mtucCheckoutObserved', $js);
        self::assertStringNotContainsString('console.', $js);

        $paymentMethodTwig = (string) file_get_contents(
            '/var/www/open40.avalonbg.com/catalog/view/template/checkout/payment_method.twig'
        );
        self::assertStringContainsString(
            "$('#checkout-confirm').load('index.php?route=checkout/confirm.confirm",
            $paymentMethodTwig
        );
    }

    public function testCodToUniCreditSwitchUsesSameConfirmReload(): void
    {
        $paymentMethodTwig = (string) file_get_contents(
            '/var/www/open40.avalonbg.com/catalog/view/template/checkout/payment_method.twig'
        );
        self::assertStringContainsString("name=\"payment_method\"", $paymentMethodTwig);
        self::assertStringContainsString('checkout/payment_method.save', $paymentMethodTwig);
        self::assertStringContainsString('#checkout-confirm', $paymentMethodTwig);
    }

    public function testNoPhase11MarkersInRenderFix(): void
    {
        foreach ([
            dirname(__DIR__) . '/system/library/checkout_live_grand_total.php',
            dirname(__DIR__) . '/system/library/checkout_order_cart_parity.php',
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js',
        ] as $file) {
            $contents = (string) file_get_contents($file);
            foreach (['sucfOnlineSessionStart', 'Process1Execution', 'bank redirect'] as $marker) {
                self::assertStringNotContainsString($marker, $contents, $file);
            }
        }
    }
}
