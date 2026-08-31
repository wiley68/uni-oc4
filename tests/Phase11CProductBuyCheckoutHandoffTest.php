<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\EventRegistry;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductBuyCheckoutPreference;
use PHPUnit\Framework\TestCase;

/**
 * Phase 11C Remediation 09 — Product popup „Купи“ → native cart.add → Checkout preference.
 */
final class Phase11CProductBuyCheckoutHandoffTest extends TestCase
{
    public function testVersionFrozen(): void
    {
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testPreferenceStoresMinimumSchemeIdentity(): void
    {
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'        => 40,
            'scheme_type'       => 'promo',
            'kop_code'          => 'KOP0',
            'months'            => 6,
            'filter_id'         => 2,
            'first_installment' => 10.5,
        ]);

        $loaded = ProductBuyCheckoutPreference::load($session, 0);
        self::assertNotNull($loaded);
        self::assertSame(ProductBuyCheckoutPreference::FLOW, $loaded['flow']);
        self::assertSame('product_buy', $loaded['source']);
        self::assertSame(0, $loaded['store_id']);
        self::assertSame(40, $loaded['product_id']);
        self::assertSame('promo', $loaded['scheme_type']);
        self::assertSame('KOP0', $loaded['kop_code']);
        self::assertSame(6, $loaded['months']);
        self::assertSame(2, $loaded['filter_id']);
        self::assertTrue($loaded['prefer_payment']);
        self::assertSame(ModuleConstants::PAYMENT_OPTION_CODE, $loaded['payment_code']);
        self::assertFalse($loaded['payment_user_overridden']);
        self::assertNotSame('', $loaded['scheme_key']);
        self::assertArrayNotHasKey('redirect_url', $loaded);
        self::assertArrayNotHasKey('monthly_installment', $loaded);
    }

    public function testPreferenceIsStoreScoped(): void
    {
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 1,
            'scheme_type' => 'standard',
            'kop_code'    => 'KOP',
            'months'      => 12,
            'filter_id'   => 1,
        ]);
        self::assertNull(ProductBuyCheckoutPreference::load($session, 1));
        self::assertArrayNotHasKey(ProductBuyCheckoutPreference::SESSION_KEY, $session);
    }

    public function testResolveSchemeKeyPrefersExactFilterAndPromoIdentity(): void
    {
        $schemes = [
            [
                'key'         => 'standard|KOP|6|1',
                'scheme_type' => 'standard',
                'kop_code'    => 'KOP',
                'months'      => 6,
                'filter_id'   => 1,
            ],
            [
                'key'         => 'promo|KOP|6|9',
                'scheme_type' => 'promo',
                'kop_code'    => 'KOP',
                'months'      => 6,
                'filter_id'   => 9,
            ],
        ];
        $preference = [
            'scheme_type' => 'promo',
            'kop_code'    => 'KOP',
            'months'      => 6,
            'filter_id'   => 9,
        ];
        self::assertSame(
            'promo|KOP|6|9',
            ProductBuyCheckoutPreference::resolveSchemeKey($schemes, $preference)
        );
    }

    public function testResolveSchemeKeyReturnsNullWhenInvalidated(): void
    {
        $schemes = [
            [
                'key'         => 'standard|KOP|12|1',
                'scheme_type' => 'standard',
                'kop_code'    => 'KOP',
                'months'      => 12,
                'filter_id'   => 1,
            ],
        ];
        $preference = [
            'scheme_type' => 'promo',
            'kop_code'    => 'KOP0',
            'months'      => 6,
            'filter_id'   => 2,
        ];
        self::assertNull(ProductBuyCheckoutPreference::resolveSchemeKey($schemes, $preference));
    }

    public function testApplyPaymentOnlyWhenUniCreditListed(): void
    {
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 1,
            'scheme_type' => 'standard',
            'kop_code'    => 'KOP',
            'months'      => 12,
            'filter_id'   => 0,
        ]);
        $session['payment_method'] = PaymentIdentity::paymentMethod();

        $unavailable = ['cod' => ['option' => ['cod' => ['code' => 'cod.cod', 'name' => 'COD']]]];
        self::assertFalse(ProductBuyCheckoutPreference::applyPaymentIfAvailable($session, $unavailable, 0));
        self::assertArrayNotHasKey('payment_method', $session);

        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 1,
            'scheme_type' => 'standard',
            'kop_code'    => 'KOP',
            'months'      => 12,
            'filter_id'   => 0,
        ]);
        $available = [
            ModuleConstants::PAYMENT_CODE => [
                'option' => [
                    ModuleConstants::PAYMENT_CODE => [
                        'code' => ModuleConstants::PAYMENT_OPTION_CODE,
                        'name' => 'UniCredit',
                    ],
                ],
            ],
        ];
        self::assertTrue(ProductBuyCheckoutPreference::applyPaymentIfAvailable($session, $available, 0));
        self::assertSame(ModuleConstants::PAYMENT_OPTION_CODE, $session['payment_method']['code']);
    }

    public function testClearWhenPaymentLeavesUniCredit(): void
    {
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 1,
            'scheme_type' => 'standard',
            'kop_code'    => 'KOP',
            'months'      => 12,
            'filter_id'   => 0,
        ]);
        $session['payment_method'] = ['code' => 'cod.cod', 'name' => 'COD'];
        ProductBuyCheckoutPreference::clearIfPaymentChangedAway($session);
        self::assertArrayNotHasKey(ProductBuyCheckoutPreference::SESSION_KEY, $session);
    }

    public function testProductJsBuyUsesNativeCartAddBeforeCheckout(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
        self::assertStringContainsString('bindBuyNativeCartAddThenCheckout', $js);
        self::assertStringContainsString('stashBuyPreferenceAndGoCheckout', $js);
        self::assertStringContainsString('isCheckoutCartAddUrl', $js);
        self::assertStringContainsString('awaitingNativeCartAdd', $js);
        self::assertStringNotContainsString('window.location.href = checkoutUrl', $js);
        self::assertMatchesRegularExpression(
            '/bindBuyNativeCartAddThenCheckout[\s\S]*?cartBtn\.click\(\)/s',
            $js
        );
        self::assertMatchesRegularExpression(
            '/json\.success[\s\S]*?stashBuyPreferenceAndGoCheckout/s',
            $js
        );
    }

    public function testProductControllerExposesStashBuyPreferenceWithoutFinancingSideEffects(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_product.php'
        );
        self::assertStringContainsString('function stashBuyPreference', $src);
        self::assertStringContainsString('ProductBuyCheckoutPreference::save', $src);
        self::assertStringContainsString('cartContainsProduct', $src);
        self::assertStringContainsString('buy_preference_stashed', $src);
        self::assertMatchesRegularExpression(
            '/function stashBuyPreference\(\): void\s*\{[\s\S]*?buy_preference_stashed[\s\S]*?\n    \}/',
            $src
        );
        if (preg_match('/function stashBuyPreference\(\): void\s*\{([\s\S]*?)\n    (?:public|private) function /', $src, $m)) {
            $body = $m[1];
            self::assertStringNotContainsString('SmartUcf', $body);
            self::assertStringNotContainsString('createSubmissionService', $body);
            self::assertStringNotContainsString('ControlPanel', $body);
        } else {
            self::fail('stashBuyPreference body not isolated');
        }
    }

    public function testEventRegistryRegistersBuyPreferenceHooks(): void
    {
        $triggers = array_column(EventRegistry::definitions(), 'trigger');
        self::assertContains('catalog/controller/checkout/payment_method.getMethods/after', $triggers);
        self::assertContains('catalog/controller/checkout/payment_method.save/after', $triggers);
        self::assertContains('catalog/controller/checkout/shipping_method.save/after', $triggers);
        self::assertContains('catalog/controller/checkout/checkout/before', $triggers);
        self::assertContains('module_mt_uni_credit_after_payment_methods', EventRegistry::eventCodes());
        self::assertContains('module_mt_uni_credit_after_payment_method_save', EventRegistry::eventCodes());
        self::assertContains('module_mt_uni_credit_after_shipping_method_save_buy', EventRegistry::eventCodes());
        self::assertContains('module_mt_uni_credit_before_checkout_handoff_js', EventRegistry::eventCodes());
        self::assertContains('module_mt_uni_credit_before_checkout_success_clear_buy', EventRegistry::eventCodes());
    }

    public function testCheckoutConsumesBuyPreferenceSchemeKey(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php'
        );
        self::assertStringContainsString('resolveInitialSchemeSelection', $controller);
        self::assertStringContainsString('buy_preference_scheme_key', $controller);
        self::assertStringContainsString('buildCheckoutSchemeOptions', $controller);

        $js = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js'
        );
        self::assertStringContainsString('buy_preference_scheme_key', $js);
        self::assertStringContainsString('resolveInitialSchemeKey', $js);
    }

    public function testProductViewBootstrapIncludesStashBuyUrl(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_product_view.php'
        );
        self::assertStringContainsString('stashBuyPreference', $src);
        self::assertStringContainsString('stash_buy_url', $src);
    }

    public function testDirectFinancingSubmitPathStillPresent(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
        self::assertStringContainsString('submitForm', $js);
        self::assertStringContainsString('submit_url', $js);
        self::assertStringContainsString('data-mtuc-apply', $js);
        self::assertStringContainsString('secondaryActionUsesNativeAddToCart', $js);
    }
}
