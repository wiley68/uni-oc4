<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\CheckoutOrderCartParity;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutSessionOrderGuard;
use Opencart\System\Library\Extension\MtUniCredit\EventRegistry;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use PHPUnit\Framework\TestCase;

/**
 * Guest/logged checkout: Voided session.order_id must not survive a materially changed cart.
 */
final class Phase10BStaleCheckoutSessionOrderTest extends TestCase
{
    public function testCartMutationAlwaysClearsSessionOrderId(): void
    {
        $session = ['order_id' => 1010, 'payment_method' => ['code' => 'x']];
        self::assertTrue(CheckoutSessionOrderGuard::invalidateOnCartMutation($session));
        self::assertArrayNotHasKey('order_id', $session);
        self::assertArrayHasKey('payment_method', $session);

        $empty = [];
        self::assertFalse(CheckoutSessionOrderGuard::invalidateOnCartMutation($empty));
    }

    public function testMatchingCartKeepsSessionOrderEvenIfVoided(): void
    {
        $order = [
            'order_id'      => 55,
            'total'         => 1010.0,
            'currency_code' => 'EUR',
            'order_status_id' => 16,
        ];
        $orderProducts = [
            ['order_product_id' => 1, 'product_id' => 10, 'quantity' => 1],
        ];
        $cartProducts = [
            [
                'product_id' => 10,
                'quantity'   => 1,
                'option'     => [['product_option_value_id' => 100]],
            ],
        ];
        $getOptions = static fn(int $oid, int $opid): array => [
            ['product_option_value_id' => 100],
        ];

        self::assertFalse(CheckoutSessionOrderGuard::shouldInvalidateForCurrentCart(
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            1010.0,
            'EUR'
        ));
    }

    public function testTotalChangeInvalidatesVoidedOrder(): void
    {
        $order = [
            'order_id'        => 55,
            'total'           => 1010.0,
            'currency_code'   => 'EUR',
            'order_status_id' => 16,
        ];
        $orderProducts = [
            ['order_product_id' => 1, 'product_id' => 10, 'quantity' => 1],
        ];
        $cartProducts = [
            [
                'product_id' => 10,
                'quantity'   => 1,
                'option'     => [['product_option_value_id' => 200]],
            ],
        ];
        $getOptions = static fn(int $oid, int $opid): array => [
            ['product_option_value_id' => 100],
        ];

        self::assertTrue(CheckoutSessionOrderGuard::shouldInvalidateForCurrentCart(
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            1030.0,
            'EUR'
        ));

        $session = ['order_id' => 55];
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
    }

    public function testOptionChangeInvalidatesEvenWhenTotalMatchesAccidentally(): void
    {
        $order = [
            'order_id'      => 7,
            'total'         => 500.0,
            'currency_code' => 'EUR',
        ];
        $orderProducts = [
            ['order_product_id' => 1, 'product_id' => 1, 'quantity' => 1],
        ];
        $cartProducts = [
            [
                'product_id' => 1,
                'quantity'   => 1,
                'option'     => [['product_option_value_id' => 2]],
            ],
        ];
        $getOptions = static fn(): array => [['product_option_value_id' => 1]];

        self::assertTrue(CheckoutSessionOrderGuard::shouldInvalidateForCurrentCart(
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            500.0,
            'EUR'
        ));
    }

    public function testQuantityChangeInvalidates(): void
    {
        $order = [
            'order_id'      => 7,
            'total'         => 200.0,
            'currency_code' => 'EUR',
        ];
        $orderProducts = [
            ['order_product_id' => 1, 'product_id' => 1, 'quantity' => 1],
        ];
        $cartProducts = [
            ['product_id' => 1, 'quantity' => 2, 'option' => []],
        ];
        $getOptions = static fn(): array => [];

        self::assertTrue(CheckoutSessionOrderGuard::shouldInvalidateForCurrentCart(
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            400.0,
            'EUR'
        ));
    }

    public function testCurrencyMismatchInvalidates(): void
    {
        $order = [
            'order_id'      => 7,
            'total'         => 100.0,
            'currency_code' => 'EUR',
        ];
        $orderProducts = [
            ['order_product_id' => 1, 'product_id' => 1, 'quantity' => 1],
        ];
        $cartProducts = [
            ['product_id' => 1, 'quantity' => 1, 'option' => []],
        ];
        $getOptions = static fn(): array => [];

        self::assertTrue(CheckoutSessionOrderGuard::shouldInvalidateForCurrentCart(
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            100.0,
            'BGN'
        ));
    }

    public function testUnchangedCartRetryDoesNotClear(): void
    {
        $order = [
            'order_id'      => 9,
            'total'         => 1030.0,
            'currency_code' => 'EUR',
        ];
        $orderProducts = [
            ['order_product_id' => 1, 'product_id' => 42, 'quantity' => 1],
        ];
        $cartProducts = [
            [
                'product_id' => 42,
                'quantity'   => 1,
                'option'     => [['product_option_value_id' => 5]],
            ],
        ];
        $getOptions = static fn(): array => [['product_option_value_id' => 5]];
        $session = ['order_id' => 9];

        self::assertFalse(CheckoutSessionOrderGuard::reconcileSessionOrder(
            $session,
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            1030.0,
            'EUR'
        ));
        self::assertSame(9, $session['order_id']);
    }

    public function testStructuralKeysStableAcrossSortOrder(): void
    {
        $a = CheckoutOrderCartParity::structuralKeyFromCartProducts([
            ['product_id' => 2, 'quantity' => 1, 'option' => []],
            ['product_id' => 1, 'quantity' => 3, 'option' => [['product_option_value_id' => 9]]],
        ]);
        $b = CheckoutOrderCartParity::structuralKeyFromCartProducts([
            ['product_id' => 1, 'quantity' => 3, 'option' => [['product_option_value_id' => 9]]],
            ['product_id' => 2, 'quantity' => 1, 'option' => []],
        ]);
        self::assertSame($a, $b);
    }

    public function testEventRegistryRegistersCartMutationAndConfirmHooks(): void
    {
        $triggers = array_column(EventRegistry::definitions(), 'trigger');
        self::assertContains('catalog/controller/checkout/cart/add/after', $triggers);
        self::assertContains('catalog/controller/checkout/cart/edit/after', $triggers);
        self::assertContains('catalog/controller/checkout/cart/remove/after', $triggers);
        self::assertContains('catalog/controller/checkout/confirm/before', $triggers);

        $controller = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_checkout_session_order.php'
        );
        self::assertStringContainsString('onCartMutated', $controller);
        self::assertStringContainsString('onConfirmBefore', $controller);
        self::assertStringContainsString('CheckoutSessionOrderGuard', $controller);
    }

    public function testResolveSessionOrderWiresGuard(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_checkout.php'
        );
        self::assertStringContainsString('CheckoutSessionOrderGuard::reconcileSessionOrder', $src);
        self::assertStringContainsString('liveCheckoutGrandTotal()', $src);
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testMerchandiseTotalIsNotOrderParityBase(): void
    {
        $order = [
            'order_id'      => 597,
            'total'         => 1004.996,
            'currency_code' => 'EUR',
        ];
        $orderProducts = [
            ['order_product_id' => 1, 'product_id' => 40, 'quantity' => 1],
        ];
        $cartProducts = [
            ['product_id' => 40, 'quantity' => 1, 'option' => []],
        ];
        $getOptions = static fn(): array => [];

        // Logged flat-shipping false positive when comparing cart->getTotal() (999.996).
        self::assertTrue(CheckoutSessionOrderGuard::shouldInvalidateForCurrentCart(
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            999.996,
            'EUR'
        ));
        self::assertFalse(CheckoutSessionOrderGuard::shouldInvalidateForCurrentCart(
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            1004.996,
            'EUR'
        ));
    }

    public function testNativeConfirmOnlyEditsStatusZero(): void
    {
        $confirm = (string) file_get_contents('/var/www/open40.avalonbg.com/catalog/controller/checkout/confirm.php');
        self::assertStringContainsString("elseif (\$order_info && !\$order_info['order_status_id'])", $confirm);
        self::assertStringContainsString('editOrder', $confirm);

        $orderModel = (string) file_get_contents('/var/www/open40.avalonbg.com/catalog/model/checkout/order.php');
        self::assertStringContainsString("config_void_status_id", $orderModel);
        self::assertStringContainsString('Void the order first', $orderModel);

        $cartEdit = (string) file_get_contents('/var/www/open40.avalonbg.com/catalog/controller/checkout/cart.php');
        self::assertStringContainsString("unset(\$this->session->data['payment_method'])", $cartEdit);
        self::assertStringNotContainsString("unset(\$this->session->data['order_id'])", $cartEdit);
    }

    public function testNoSmartUcfInRemediation(): void
    {
        foreach ([
            dirname(__DIR__) . '/system/library/checkout_session_order_guard.php',
            dirname(__DIR__) . '/system/library/checkout_order_cart_parity.php',
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_checkout_session_order.php',
        ] as $file) {
            $contents = (string) file_get_contents($file);
            foreach (['SmartUCF', 'sucfOnlineSessionStart', 'Process1', 'Process2'] as $marker) {
                self::assertStringNotContainsString($marker, $contents, $file);
            }
        }
    }
}
