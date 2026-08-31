<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\Calculator;
use Opencart\System\Library\Extension\MtUniCredit\CartCalculatorPresenter;
use Opencart\System\Library\Extension\MtUniCredit\CartContext;
use Opencart\System\Library\Extension\MtUniCredit\CartSchemeResolver;
use Opencart\System\Library\Extension\MtUniCredit\CurrencyGate;
use Opencart\System\Library\Extension\MtUniCredit\EventRegistry;
use Opencart\System\Library\Extension\MtUniCredit\InstallmentLabelFormatter;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductBuyCheckoutPreference;
use Opencart\System\Library\Extension\MtUniCredit\ProductSchemeList;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/calculator_fixture.php';

/**
 * Phase 11C Remediation 09C — Product Buy handoff across native OC4 shipping/payment rerenders.
 *
 * Operator sequence: Product 12m promo default → user selects 4m → Buy → cart.add → Checkout
 * → shipping_method.save (native payment reset) → payment getMethods/modal → UniCredit + 4m.
 */
final class Phase11CProductBuyCheckoutRerenderHandoffTest extends TestCase
{
    public function testVersionFrozen(): void
    {
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    /**
     * @return array{
     *   presenter:array<string,mixed>,
     *   defaultKey:string,
     *   fourMonthKey:string,
     *   promoTwelveKey:string,
     *   paymentMethodsFirstPb:array<string,mixed>
     * }
     */
    private function operatorFixture(): array
    {
        $months = [];
        foreach ([4, 6, 12, 24] as $m) {
            $months['uni_meseci_' . $m] = 1;
        }
        $shop = mt_uni_credit_calculator_fixture(array_merge($months, [
            'uni_shema_current' => 12,
            'kop'               => [
                'by_default' => [
                    'uni_kop_default'       => 'STD',
                    'uni_kop_default_desc'  => '',
                    'uni_kop_promo'         => 'PROMO',
                    'uni_kop_promo_desc'    => 'computers',
                    'uni_promo_price'       => 500,
                    'uni_promo_meseci_znak' => 'eq',
                    'uni_promo_meseci'      => '12',
                ],
            ],
            'coeff_list'        => [
                ['onlineProductCode' => 'STD', 'installmentCount' => 4, 'coeff' => 0.26, 'interestPercent' => 22],
                ['onlineProductCode' => 'STD', 'installmentCount' => 6, 'coeff' => 0.18, 'interestPercent' => 20],
                ['onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 18],
                ['onlineProductCode' => 'STD', 'installmentCount' => 24, 'coeff' => 0.055, 'interestPercent' => 17],
                ['onlineProductCode' => 'PROMO', 'installmentCount' => 12, 'coeff' => 0.083333, 'interestPercent' => 0],
            ],
        ]));

        $calc = new Calculator();
        // Cart total after shipping (e.g. merchandise + flat) — schemes must remain valid.
        $cart = new CartContext([mt_uni_credit_cart_line(42, [7, 9], 1005.0)], 1005.0);
        $presenter = (new CartCalculatorPresenter(
            new CartSchemeResolver($calc),
            $calc,
            new CurrencyGate(),
            new InstallmentLabelFormatter()
        ))->present($shop, $cart, 'BGN');
        self::assertIsArray($presenter);

        $schemes = ProductBuyCheckoutPreference::collectPresenterSchemes($presenter);
        $keys = array_column($schemes, 'key');
        $fourMonthKey = '';
        $promoTwelveKey = '';
        foreach ($schemes as $scheme) {
            if ((int) $scheme['months'] === 4 && $scheme['scheme_type'] === 'standard') {
                $fourMonthKey = (string) $scheme['key'];
            }
            if ((int) $scheme['months'] === 12 && $scheme['scheme_type'] === 'promo') {
                $promoTwelveKey = (string) $scheme['key'];
            }
        }
        self::assertNotSame('', $fourMonthKey, '4m standard must exist in fixture');
        self::assertNotSame('', $promoTwelveKey, '12m promo must exist in fixture');
        self::assertContains($fourMonthKey, $keys);

        $defaultKey = (string) $presenter['offers']['standard']['preferred_scheme_key'];
        // PreferredOffer uses uni_shema_current=12 — must differ from explicit 4m.
        self::assertNotSame($fourMonthKey, $defaultKey);

        $paymentMethodsFirstPb = [
            'pb_personal' => [
                'name'   => 'PB Personal Finance',
                'option' => [
                    'pb_personal' => [
                        'code' => 'pb_personal.pb_personal',
                        'name' => 'PB Personal Finance',
                    ],
                ],
            ],
            ModuleConstants::PAYMENT_CODE => [
                'name'   => 'UniCredit',
                'option' => [
                    ModuleConstants::PAYMENT_CODE => [
                        'code' => ModuleConstants::PAYMENT_OPTION_CODE,
                        'name' => PaymentIdentity::DISPLAY_NAME,
                    ],
                ],
            ],
            'cod' => [
                'name'   => 'Cash',
                'option' => [
                    'cod' => ['code' => 'cod.cod', 'name' => 'COD'],
                ],
            ],
        ];

        return [
            'presenter'            => $presenter,
            'defaultKey'           => $defaultKey,
            'fourMonthKey'         => $fourMonthKey,
            'promoTwelveKey'       => $promoTwelveKey,
            'paymentMethodsFirstPb' => $paymentMethodsFirstPb,
        ];
    }

    public function testFullOperatorSequencePaymentAndSchemeSurviveShippingReset(): void
    {
        $fx = $this->operatorFixture();
        $session = [];

        // Product: initial default was 12m promo; user selected 4m at Buy click.
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'standard',
            'kop_code'    => 'STD',
            'months'      => 4,
            'filter_id'   => 0,
            'scheme_key'  => $fx['fourMonthKey'],
        ]);
        $session['payment_method'] = PaymentIdentity::paymentMethod();

        $pref = ProductBuyCheckoutPreference::load($session, 0);
        self::assertNotNull($pref);
        self::assertSame(4, $pref['months']);
        self::assertSame($fx['fourMonthKey'], $pref['scheme_key']);
        self::assertNotSame($fx['promoTwelveKey'], $pref['scheme_key']);
        self::assertTrue($pref['prefer_payment']);
        self::assertSame(ModuleConstants::PAYMENT_OPTION_CODE, $pref['payment_code']);
        self::assertFalse($pref['payment_user_overridden']);
        self::assertFalse($pref['scheme_user_override']);

        // First Checkout request — preference intact.
        self::assertNotNull(ProductBuyCheckoutPreference::load($session, 0));

        // Native OC4 shipping_method.save:
        unset($session['payment_method']);
        unset($session['payment_methods']);

        // After shipping: native payment gone, Product Buy intent still pending.
        self::assertArrayNotHasKey('payment_method', $session);
        $afterShip = ProductBuyCheckoutPreference::load($session, 0);
        self::assertNotNull($afterShip);
        self::assertTrue(ProductBuyCheckoutPreference::shouldPreferPayment($afterShip));
        self::assertSame(4, $afterShip['months']);
        self::assertSame($fx['fourMonthKey'], $afterShip['scheme_key']);

        // payment_method.getMethods — PB is first in discovery order.
        $json = [
            'payment_methods' => $fx['paymentMethodsFirstPb'],
        ];
        $json = ProductBuyCheckoutPreference::enrichPaymentMethodsResponse($session, $json, 0);

        self::assertSame(ModuleConstants::PAYMENT_OPTION_CODE, $session['payment_method']['code']);
        self::assertSame(
            ModuleConstants::PAYMENT_CODE,
            array_key_first($json['payment_methods']),
            'UniCredit must be first so empty #input-payment-code selects it'
        );
        self::assertArrayHasKey(ProductBuyCheckoutPreference::JSON_PREFERRED_PAYMENT_KEY, $json);
        self::assertSame(
            ModuleConstants::PAYMENT_OPTION_CODE,
            $json[ProductBuyCheckoutPreference::JSON_PREFERRED_PAYMENT_KEY]['code']
        );

        // Preference still has exact 4m after payment apply.
        $prefAfterPay = ProductBuyCheckoutPreference::load($session, 0);
        self::assertNotNull($prefAfterPay);
        self::assertSame($fx['fourMonthKey'], $prefAfterPay['scheme_key']);

        // confirm.confirm → UniCredit panel scheme selection.
        $selection = ProductBuyCheckoutPreference::resolveInitialSchemeSelection(
            $fx['presenter'],
            $session,
            0
        );
        self::assertSame('product_buy', $selection['source']);
        self::assertSame($fx['fourMonthKey'], $selection['key']);
        self::assertNotSame($fx['promoTwelveKey'], $selection['key']);
        self::assertNotSame($fx['defaultKey'], $selection['key']);

        $html = $this->renderSelect(
            ProductBuyCheckoutPreference::buildCheckoutSchemeOptions($fx['presenter'], $selection['key'])
        );
        self::assertStringContainsString('value="' . $fx['fourMonthKey'] . '" selected', $html);
        self::assertStringNotContainsString('value="' . $fx['promoTwelveKey'] . '" selected', $html);
    }

    public function testPaymentRerenderKeepsIntentPendingThenAppliesUniCredit(): void
    {
        $fx = $this->operatorFixture();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'standard',
            'kop_code'    => 'STD',
            'months'      => 4,
            'filter_id'   => 0,
            'scheme_key'  => $fx['fourMonthKey'],
        ]);
        $session['payment_method'] = PaymentIdentity::paymentMethod();

        self::assertTrue(
            ProductBuyCheckoutPreference::shouldPreferPayment(ProductBuyCheckoutPreference::load($session, 0) ?? [])
        );

        unset($session['payment_method'], $session['payment_methods']);
        self::assertTrue(
            ProductBuyCheckoutPreference::shouldPreferPayment(ProductBuyCheckoutPreference::load($session, 0) ?? [])
        );

        ProductBuyCheckoutPreference::enrichPaymentMethodsResponse(
            $session,
            ['payment_methods' => $fx['paymentMethodsFirstPb']],
            0
        );
        self::assertSame(ModuleConstants::PAYMENT_OPTION_CODE, $session['payment_method']['code']);
    }

    public function testSchemeRerenderKeepsFourMonthsNotPromoTwelve(): void
    {
        $fx = $this->operatorFixture();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'standard',
            'kop_code'    => 'STD',
            'months'      => 4,
            'filter_id'   => 0,
            'scheme_key'  => $fx['fourMonthKey'],
        ]);
        unset($session['payment_method']);

        $selection = ProductBuyCheckoutPreference::resolveInitialSchemeSelection($fx['presenter'], $session, 0);
        self::assertSame($fx['fourMonthKey'], $selection['key']);
        self::assertNotSame($fx['promoTwelveKey'], $selection['key']);
    }

    public function testPromoVsStandardExactIdentitySurvives(): void
    {
        $fx = $this->operatorFixture();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'promo',
            'kop_code'    => 'PROMO',
            'months'      => 12,
            'filter_id'   => 0,
            'scheme_key'  => $fx['promoTwelveKey'],
        ]);
        unset($session['payment_method']);
        $selection = ProductBuyCheckoutPreference::resolveInitialSchemeSelection($fx['presenter'], $session, 0);
        self::assertSame($fx['promoTwelveKey'], $selection['key']);
        self::assertStringContainsString('promo', (string) $selection['key']);
    }

    public function testFirstPaymentMethodRegressionWithoutBuyIntent(): void
    {
        $fx = $this->operatorFixture();
        $session = [];
        $json = ProductBuyCheckoutPreference::enrichPaymentMethodsResponse(
            $session,
            ['payment_methods' => $fx['paymentMethodsFirstPb']],
            0
        );
        self::assertArrayNotHasKey('payment_method', $session);
        self::assertSame('pb_personal', array_key_first($json['payment_methods']));
        self::assertArrayNotHasKey(ProductBuyCheckoutPreference::JSON_PREFERRED_PAYMENT_KEY, $json);
    }

    public function testFirstPaymentMethodWithBuyIntentUniCreditWins(): void
    {
        $fx = $this->operatorFixture();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'standard',
            'kop_code'    => 'STD',
            'months'      => 4,
            'filter_id'   => 0,
            'scheme_key'  => $fx['fourMonthKey'],
        ]);
        $json = ProductBuyCheckoutPreference::enrichPaymentMethodsResponse(
            $session,
            ['payment_methods' => $fx['paymentMethodsFirstPb']],
            0
        );
        self::assertSame(ModuleConstants::PAYMENT_CODE, array_key_first($json['payment_methods']));
        self::assertSame(ModuleConstants::PAYMENT_OPTION_CODE, $session['payment_method']['code']);
    }

    public function testExplicitPaymentOverrideClearsAndDoesNotReforce(): void
    {
        $fx = $this->operatorFixture();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'standard',
            'kop_code'    => 'STD',
            'months'      => 4,
            'filter_id'   => 0,
            'scheme_key'  => $fx['fourMonthKey'],
        ]);
        ProductBuyCheckoutPreference::enrichPaymentMethodsResponse(
            $session,
            ['payment_methods' => $fx['paymentMethodsFirstPb']],
            0
        );
        self::assertSame(ModuleConstants::PAYMENT_OPTION_CODE, $session['payment_method']['code']);

        // Customer manually chooses PB and saves.
        $session['payment_method'] = [
            'code' => 'pb_personal.pb_personal',
            'name' => 'PB Personal Finance',
        ];
        ProductBuyCheckoutPreference::clearIfPaymentChangedAway($session);
        self::assertArrayNotHasKey(ProductBuyCheckoutPreference::SESSION_KEY, $session);

        // Later getMethods must not re-force UniCredit.
        $json = ProductBuyCheckoutPreference::enrichPaymentMethodsResponse(
            $session,
            ['payment_methods' => $fx['paymentMethodsFirstPb']],
            0
        );
        self::assertArrayNotHasKey(ProductBuyCheckoutPreference::JSON_PREFERRED_PAYMENT_KEY, $json);
        self::assertSame('pb_personal', array_key_first($json['payment_methods']));
    }

    public function testExplicitSchemeOverrideSurvivesRerender(): void
    {
        $fx = $this->operatorFixture();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'standard',
            'kop_code'    => 'STD',
            'months'      => 4,
            'filter_id'   => 0,
            'scheme_key'  => $fx['fourMonthKey'],
        ]);
        ProductBuyCheckoutPreference::markSchemeUserOverride($session);

        $selection = ProductBuyCheckoutPreference::resolveInitialSchemeSelection($fx['presenter'], $session, 0);
        self::assertSame('user_override', $selection['source']);
        self::assertNotSame($fx['fourMonthKey'], $selection['key']);
    }

    public function testNativePaymentResetIsNotUserOverride(): void
    {
        $fx = $this->operatorFixture();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'standard',
            'kop_code'    => 'STD',
            'months'      => 4,
            'filter_id'   => 0,
            'scheme_key'  => $fx['fourMonthKey'],
        ]);
        unset($session['payment_method']);
        // clearIfPaymentChangedAway only runs on payment_method.save — calling it with empty
        // payment after native reset would clear; production must NOT call it on shipping save.
        $pref = ProductBuyCheckoutPreference::load($session, 0);
        self::assertNotNull($pref);
        self::assertFalse($pref['payment_user_overridden']);
        self::assertTrue($pref['prefer_payment']);
    }

    public function testEventRegistryIncludesShippingSaveAndCheckoutHandoff(): void
    {
        $triggers = array_column(EventRegistry::definitions(), 'trigger');
        self::assertContains('catalog/controller/checkout/shipping_method.save/after', $triggers);
        self::assertContains('catalog/controller/checkout/checkout/before', $triggers);
        self::assertContains('module_mt_uni_credit_after_shipping_method_save_buy', EventRegistry::eventCodes());
        self::assertContains('module_mt_uni_credit_before_checkout_handoff_js', EventRegistry::eventCodes());
    }

    public function testHandoffJsUsesDataFilterNotPolling(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout_handoff.js'
        );
        self::assertStringContainsString('ajaxPrefilter', $js);
        self::assertStringContainsString('dataFilter', $js);
        self::assertStringContainsString('mt_uni_credit_preferred_payment', $js);
        self::assertStringContainsString('input-payment-code', $js);
        self::assertStringNotContainsString('setTimeout', $js);
        self::assertStringNotContainsString('setInterval', $js);
        self::assertStringNotContainsString('console.', $js);
    }

    public function testProductJsDoesNotOverwriteDomSelectionWithOfferDefault(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js'
        );
        self::assertStringContainsString('findSchemeAcrossOffers', $js);
        self::assertStringContainsString('DOM value is customer intent', $js);
        self::assertMatchesRegularExpression(
            '/syncSelectedSchemeFromDom[\s\S]*?findSchemeAcrossOffers\(selectedSchemeKey\)/s',
            $js
        );
    }

    public function testStashHasNoFinancingSideEffects(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_product.php'
        );
        self::assertMatchesRegularExpression(
            '/function stashBuyPreference\(\): void\s*\{[\s\S]*?ProductBuyCheckoutPreference::save[\s\S]*?\}/s',
            $src
        );
        if (
            preg_match('/function stashBuyPreference\(\): void\s*\{([\s\S]*?)\n    public function/', $src, $m)
            || preg_match('/function stashBuyPreference\(\): void\s*\{([\s\S]*?)\n    private function/', $src, $m)
        ) {
            $body = $m[1];
            self::assertStringNotContainsString('createSubmissionService', $body);
            self::assertStringNotContainsString('SmartUcf', $body);
            self::assertStringNotContainsString('ControlPanel', $body);
        }
    }

    /**
     * @param list<array{key:string,label:string,selected:bool}> $options
     */
    private function renderSelect(array $options): string
    {
        $html = '<select data-mtuc-schemes>';
        foreach ($options as $option) {
            $sel = !empty($option['selected']) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($option['key'], ENT_QUOTES) . '"' . $sel . '></option>';
        }

        return $html . '</select>';
    }
}
