<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\Calculator;
use Opencart\System\Library\Extension\MtUniCredit\CartCalculatorPresenter;
use Opencart\System\Library\Extension\MtUniCredit\CartContext;
use Opencart\System\Library\Extension\MtUniCredit\CartSchemeResolver;
use Opencart\System\Library\Extension\MtUniCredit\CurrencyGate;
use Opencart\System\Library\Extension\MtUniCredit\InstallmentLabelFormatter;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartProductLine;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductBuyCheckoutPreference;
use Opencart\System\Library\Extension\MtUniCredit\ProductCalculatorPresenter;
use Opencart\System\Library\Extension\MtUniCredit\ProductSchemeList;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/calculator_fixture.php';

/**
 * Phase 11C Remediation 09B — Product Buy → Checkout render/init integration (not isolated matcher only).
 */
final class Phase11CProductBuySchemeCheckoutIntegrationTest extends TestCase
{
    /** @return array{shop:array<string,mixed>,productLine:OpenCartProductLine,cart:CartContext,checkoutPresenter:array<string,mixed>,defaultKey:string,selectedKey:string} */
    private function fixtureSixDefaultTwelveSelected(): array
    {
        $shop = mt_uni_credit_calculator_fixture(['uni_shema_current' => 6]);
        $calc = new Calculator();
        $productLine = new OpenCartProductLine(
            42,
            42,
            [7, 9],
            'Test',
            'T',
            1,
            0,
            0,
            false,
            1000.0,
            0.0,
            1000.0,
            [],
            []
        );
        $cart = new CartContext([mt_uni_credit_cart_line(42, [7, 9], 1000.0)], 1000.0);
        $checkoutPresenter = (new CartCalculatorPresenter(
            new CartSchemeResolver($calc),
            $calc,
            new CurrencyGate(),
            new InstallmentLabelFormatter()
        ))->present($shop, $cart, 'BGN');
        self::assertIsArray($checkoutPresenter);

        $defaultKey = (string) $checkoutPresenter['offers']['standard']['preferred_scheme_key'];
        $selectedKey = '';
        foreach ($checkoutPresenter['offers']['standard']['schemes'] as $scheme) {
            if ((int) $scheme['months'] === 12 && $scheme['key'] !== $defaultKey) {
                $selectedKey = (string) $scheme['key'];
                break;
            }
        }
        self::assertNotSame('', $selectedKey);
        self::assertNotSame($defaultKey, $selectedKey);

        return [
            'shop'               => $shop,
            'productLine'        => $productLine,
            'cart'               => $cart,
            'checkoutPresenter'  => $checkoutPresenter,
            'defaultKey'         => $defaultKey,
            'selectedKey'        => $selectedKey,
        ];
    }

    public function testVersionFrozen(): void
    {
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testFullHandoffFlowPreferenceSurvivesPaymentDiscovery(): void
    {
        $fixture = $this->fixtureSixDefaultTwelveSelected();
        $session = [];

        ProductBuyCheckoutPreference::save($session, 0, $this->preferenceFromKey($fixture['selectedKey'], $fixture['checkoutPresenter']));
        self::assertNotNull(ProductBuyCheckoutPreference::load($session, 0));

        $paymentMethods = [
            ModuleConstants::PAYMENT_CODE => [
                'option' => [
                    ModuleConstants::PAYMENT_CODE => [
                        'code' => PaymentIdentity::optionCode(),
                        'name' => PaymentIdentity::DISPLAY_NAME,
                    ],
                ],
            ],
        ];
        self::assertTrue(ProductBuyCheckoutPreference::applyPaymentIfAvailable($session, $paymentMethods, 0));
        self::assertSame(PaymentIdentity::optionCode(), $session['payment_method']['code']);
        self::assertNotNull(ProductBuyCheckoutPreference::load($session, 0));

        ProductBuyCheckoutPreference::clearIfPaymentChangedAway($session);
        self::assertNotNull(ProductBuyCheckoutPreference::load($session, 0));
    }

    public function testPresenterInitialSelectionUsesProductBuyOverDefault(): void
    {
        $fixture = $this->fixtureSixDefaultTwelveSelected();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, $this->preferenceFromKey($fixture['selectedKey'], $fixture['checkoutPresenter']));

        $presenter = $fixture['checkoutPresenter'];
        $selection = ProductBuyCheckoutPreference::resolveInitialSchemeSelection($presenter, $session, 0);

        self::assertSame('product_buy', $selection['source']);
        self::assertTrue($selection['buy_matched']);
        self::assertSame($fixture['selectedKey'], $selection['key']);
        self::assertNotSame($fixture['defaultKey'], $selection['key']);
    }

    public function testRenderedHtmlMarksExactSchemeSelectedNotDefault(): void
    {
        $fixture = $this->fixtureSixDefaultTwelveSelected();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, $this->preferenceFromKey($fixture['selectedKey'], $fixture['checkoutPresenter']));

        $presenter = $fixture['checkoutPresenter'];
        $selection = ProductBuyCheckoutPreference::resolveInitialSchemeSelection($presenter, $session, 0);
        $options = ProductBuyCheckoutPreference::buildCheckoutSchemeOptions($presenter, $selection['key']);

        $html = $this->renderSchemeSelectHtml($options);
        self::assertStringContainsString(
            'value="' . $fixture['selectedKey'] . '" selected',
            $html
        );
        self::assertStringNotContainsString(
            'value="' . $fixture['defaultKey'] . '" selected',
            $html
        );
    }

    public function testPromoNotStandardSixMonthsExactSelection(): void
    {
        $fixture = $this->fixtureSixDefaultTwelveSelected();
        $presenter = $fixture['checkoutPresenter'];
        $promoKey = '';
        $standardSixKey = '';
        foreach ($presenter['offers']['standard']['schemes'] as $scheme) {
            if ($scheme['scheme_type'] === 'promo' && (int) $scheme['months'] === 12) {
                $promoKey = (string) $scheme['key'];
            }
            if ($scheme['scheme_type'] === 'standard' && (int) $scheme['months'] === 6) {
                $standardSixKey = (string) $scheme['key'];
            }
        }
        self::assertNotSame('', $promoKey);
        self::assertNotSame('', $standardSixKey);
        self::assertNotSame($promoKey, $standardSixKey);

        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, $this->preferenceFromKey($promoKey, $presenter));
        $selection = ProductBuyCheckoutPreference::resolveInitialSchemeSelection($presenter, $session, 0);
        self::assertSame($promoKey, $selection['key']);
        self::assertNotSame($standardSixKey, $selection['key']);
    }

    public function testJsInitSimulationRespectsBuyPreferenceOverDefault(): void
    {
        $fixture = $this->fixtureSixDefaultTwelveSelected();
        $presenter = $fixture['checkoutPresenter'];
        $presenter['buy_preference_scheme_key'] = $fixture['selectedKey'];

        $finalKey = $this->simulateCheckoutJsInitialSelection($presenter);
        self::assertSame($fixture['selectedKey'], $finalKey);
        self::assertNotSame($fixture['defaultKey'], $finalKey);
    }

    public function testJsInitSimulationUsesServerSelectedWhenBootstrapMatches(): void
    {
        $fixture = $this->fixtureSixDefaultTwelveSelected();
        $presenter = $fixture['checkoutPresenter'];
        $presenter['buy_preference_scheme_key'] = $fixture['selectedKey'];
        $serverHtml = $this->renderSchemeSelectHtml(
            ProductBuyCheckoutPreference::buildCheckoutSchemeOptions($presenter, $fixture['selectedKey'])
        );

        $finalKey = $this->simulateCheckoutJsInitialSelection($presenter, $serverHtml);
        self::assertSame($fixture['selectedKey'], $finalKey);
    }

    public function testManualOverrideStopsProductBuyReapplication(): void
    {
        $fixture = $this->fixtureSixDefaultTwelveSelected();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, $this->preferenceFromKey($fixture['selectedKey'], $fixture['checkoutPresenter']));
        ProductBuyCheckoutPreference::markSchemeUserOverride($session);

        $selection = ProductBuyCheckoutPreference::resolveInitialSchemeSelection(
            $fixture['checkoutPresenter'],
            $session,
            0
        );
        self::assertSame('user_override', $selection['source']);
        self::assertFalse($selection['buy_matched']);
        self::assertSame($fixture['defaultKey'], $selection['key']);
    }

    public function testInvalidPreferenceFallsBackToCheckoutDefault(): void
    {
        $fixture = $this->fixtureSixDefaultTwelveSelected();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'promo',
            'kop_code'    => 'MISSING',
            'months'      => 99,
            'filter_id'   => 999,
            'scheme_key'  => 'promo|MISSING|99|999',
        ]);

        $selection = ProductBuyCheckoutPreference::resolveInitialSchemeSelection(
            $fixture['checkoutPresenter'],
            $session,
            0
        );
        self::assertSame('checkout_default', $selection['source']);
        self::assertSame($fixture['defaultKey'], $selection['key']);
    }

    public function testPaymentAwayClearsPreference(): void
    {
        $fixture = $this->fixtureSixDefaultTwelveSelected();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, $this->preferenceFromKey($fixture['selectedKey'], $fixture['checkoutPresenter']));
        $session['payment_method'] = ['code' => 'cod.cod', 'name' => 'COD'];
        ProductBuyCheckoutPreference::clearIfPaymentChangedAway($session);
        self::assertArrayNotHasKey(ProductBuyCheckoutPreference::SESSION_KEY, $session);
    }

    public function testCheckoutControllerUsesInitialSelectionAndSchemeOptions(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php');
        self::assertStringContainsString('resolveInitialSchemeSelection', $src);
        self::assertStringContainsString('buildCheckoutSchemeOptions', $src);
        self::assertStringNotContainsString('resolveBuyPreferenceSchemeKey', $src);

        $twig = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/payment/mt_uni_credit.twig');
        self::assertStringContainsString('{% for option in scheme_options %}', $twig);
        self::assertStringContainsString('{% if option.selected %} selected{% endif %}', $twig);

        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js');
        self::assertStringContainsString('resolveInitialSchemeKey', $js);
        self::assertStringContainsString('readServerSelectedSchemeKey', $js);
        self::assertStringNotContainsString('resolvePreferredSchemeKey', $js);

        $productJs = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
        self::assertStringContainsString('syncSelectedSchemeFromDom()', $productJs);
        self::assertMatchesRegularExpression(
            '/triggerSecondaryAction[\s\S]*?syncSelectedSchemeFromDom\(\)/s',
            $productJs
        );
    }

    /**
     * @param list<array{key:string,label:string,selected:bool}> $options
     */
    private function renderSchemeSelectHtml(array $options): string
    {
        $html = '<select id="mtuc-checkout-months" data-mtuc-schemes>';
        foreach ($options as $option) {
            $selected = !empty($option['selected']) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($option['key'], ENT_QUOTES) . '"' . $selected . '>'
                . htmlspecialchars($option['label'], ENT_QUOTES) . '</option>';
        }
        $html .= '</select>';

        return $html;
    }

    /**
     * Mirrors checkout.js resolveInitialSchemeKey + populateSchemeSelect initial bind.
     *
     * @param array<string, mixed> $presenter
     */
    private function simulateCheckoutJsInitialSelection(array $presenter, string $serverHtml = ''): string
    {
        $schemes = $presenter['offers']['standard']['schemes'] ?? [];
        $keys = array_column($schemes, 'key');
        $isValid = static fn(string $key): bool => $key !== '' && in_array($key, $keys, true);

        $buyKey = (string) ($presenter['buy_preference_scheme_key'] ?? '');
        if ($buyKey !== '' && $isValid($buyKey)) {
            return $buyKey;
        }

        $serverSelected = '';
        if ($serverHtml !== '' && preg_match('/<option value="([^"]+)" selected/', $serverHtml, $m)) {
            $serverSelected = $m[1];
        }
        if ($serverSelected !== '' && $isValid($serverSelected)) {
            return $serverSelected;
        }

        $defaultKey = (string) ($presenter['offers']['standard']['preferred_scheme_key'] ?? '');
        if ($defaultKey !== '' && $isValid($defaultKey)) {
            return $defaultKey;
        }

        return (string) ($schemes[0]['key'] ?? '');
    }

    /**
     * @param array<string, mixed> $presenter
     * @return array<string, mixed>
     */
    private function preferenceFromKey(string $key, array $presenter): array
    {
        foreach (ProductBuyCheckoutPreference::collectPresenterSchemes($presenter) as $scheme) {
            if (($scheme['key'] ?? '') === $key) {
                return [
                    'product_id'  => 42,
                    'scheme_type' => $scheme['scheme_type'],
                    'kop_code'    => $scheme['kop_code'],
                    'months'      => $scheme['months'],
                    'filter_id'   => $scheme['filter_id'],
                    'scheme_key'  => $key,
                ];
            }
        }

        self::fail('Scheme row not found for key ' . $key);
    }
}
