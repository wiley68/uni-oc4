<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\CalculatorTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\AvailableScheme;
use Opencart\System\Library\Extension\MtUniCredit\Calculator;
use Opencart\System\Library\Extension\MtUniCredit\CartCalculatorPresenter;
use Opencart\System\Library\Extension\MtUniCredit\CartContext;
use Opencart\System\Library\Extension\MtUniCredit\CartSchemeResolver;
use Opencart\System\Library\Extension\MtUniCredit\CurrencyGate;
use Opencart\System\Library\Extension\MtUniCredit\InstallmentLabelFormatter;
use Opencart\System\Library\Extension\MtUniCredit\ProductCalculatorPresenter;
use Opencart\System\Library\Extension\MtUniCredit\ProductSchemeList;
use Opencart\System\Library\Extension\MtUniCredit\SchemePresentationCategory;
use PHPUnit\Framework\TestCase;

/**
 * Checkout UX cleanup + shared scheme ordering across Product / Cart / Checkout.
 */
final class Phase9CheckoutUxAndSchemeOrderingTest extends TestCase
{
    public function testCheckoutTwigHasNoOfferButtonsOrOrderDataBlock(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/payment/mt_uni_credit.twig');
        self::assertStringNotContainsString('data-mtuc-offers', $twig);
        self::assertStringNotContainsString('data-mtuc-customer-summary', $twig);
        self::assertStringNotContainsString('text_customer_summary', $twig);
        self::assertStringNotContainsString('data-mtuc-customer-display', $twig);
        self::assertStringNotContainsString('name="firstname"', $twig);
        self::assertStringNotContainsString('name="telephone"', $twig);
        // Process 2: only egn + phone2 behind process2 flag (no Process 1 customer fields).
        self::assertStringContainsString('{% if process2 %}', $twig);
        self::assertMatchesRegularExpression(
            '/\{\%\s*if\s+process2\s*\%\}[\s\S]*name="egn"[\s\S]*name="phone2"/',
            $twig
        );
        self::assertStringContainsString('data-mtuc-schemes', $twig);
        self::assertStringContainsString('data-mtuc-submit', $twig);
        self::assertStringContainsString('{% if consents %}', $twig);
        self::assertStringContainsString('data-mtuc-consents', $twig);
        self::assertStringContainsString('data-mtuc-checkout-helper', $twig);
        self::assertStringContainsString('checkout_helper', $twig);
    }

    public function testCheckoutHelperLanguageKeysExistAndProcess2MatchesWooSource(): void
    {
        $bgFile = (string) file_get_contents(dirname(__DIR__) . '/catalog/language/bg-bg/payment/mt_uni_credit.php');
        $enFile = (string) file_get_contents(dirname(__DIR__) . '/catalog/language/en-gb/payment/mt_uni_credit.php');
        self::assertStringContainsString('text_checkout_helper_process1', $bgFile);
        self::assertStringContainsString('text_checkout_helper_process2', $bgFile);
        self::assertStringContainsString('text_checkout_helper_process1', $enFile);
        self::assertStringContainsString('text_checkout_helper_process2', $enFile);

        // Verbatim copy from wiley68/uni-woo templates/checkout-payment-fields.php (esc_html_e msgid).
        // Current uni-woo / PS8 / PS9 use the same intro for Process 1 and Process 2
        // (Process 2 only adds EGN + phone2 fields; no distinct P2 helper string).
        $wooIntro = "Можете да изберете 'Срок за кредита', предпочитаната от Вас 'Месечна вноска', както и при желание 'Първоначална вноска'. След което да потвърдите избора си. Ще бъдете прехвърлени към страницата на UniCredit за довършване на покупката си на кредит.";

        $_ = [];
        include dirname(__DIR__) . '/catalog/language/bg-bg/payment/mt_uni_credit.php';
        self::assertSame($wooIntro, $_['text_checkout_helper_process1']);
        self::assertSame($wooIntro, $_['text_checkout_helper_process2']);
        self::assertStringContainsString('UniCredit', $_['text_checkout_helper_process1']);
        self::assertStringNotContainsString('УниКредит', $_['text_checkout_helper_process1']);
        self::assertStringNotContainsString('Въведете необходимите лични данни', $_['text_checkout_helper_process2']);
    }

    public function testPrimaryProcessSelectsOnlyHelperProcess1Key(): void
    {
        $langs = $this->loadCheckoutHelperLangs();
        $shop = ['uni_proces' => 0];
        $secondary = \Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationFlags::isSecondaryProcess($shop);
        $key = $secondary ? 'text_checkout_helper_process2' : 'text_checkout_helper_process1';
        self::assertFalse($secondary);
        self::assertSame('text_checkout_helper_process1', $key);
        self::assertSame($langs['bg']['text_checkout_helper_process1'], $langs['bg'][$key]);
        self::assertNotSame('text_checkout_helper_process2', $key);
    }

    public function testSecondaryProcessSelectsOnlyExactCopiedHelperKey(): void
    {
        $langs = $this->loadCheckoutHelperLangs();
        $shop = ['uni_proces' => 1];
        $secondary = \Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationFlags::isSecondaryProcess($shop);
        $key = $secondary ? 'text_checkout_helper_process2' : 'text_checkout_helper_process1';
        self::assertTrue($secondary);
        self::assertSame('text_checkout_helper_process2', $key);
        self::assertSame($langs['bg']['text_checkout_helper_process2'], $langs['bg'][$key]);
        self::assertNotSame('text_checkout_helper_process1', $key);
        $wooIntro = "Можете да изберете 'Срок за кредита', предпочитаната от Вас 'Месечна вноска', както и при желание 'Първоначална вноска'. След което да потвърдите избора си. Ще бъдете прехвърлени към страницата на UniCredit за довършване на покупката си на кредит.";
        self::assertSame($wooIntro, $langs['bg']['text_checkout_helper_process2']);
    }

    public function testControllerSelectsHelperFromShopProcessFlag(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php');
        self::assertStringContainsString('ShopConfigurationFlags::isSecondaryProcess', $controller);
        self::assertStringContainsString('text_checkout_helper_process2', $controller);
        self::assertStringContainsString('text_checkout_helper_process1', $controller);
        self::assertStringContainsString('checkout_helper', $controller);
        self::assertStringNotContainsString('data-mtuc-offers', $controller);
        self::assertStringNotContainsString('Process2', $controller);
        self::assertStringNotContainsString('Process1', $controller);
        $twig = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/payment/mt_uni_credit.twig');
        self::assertSame(1, substr_count($twig, 'data-mtuc-checkout-helper'));
        self::assertStringNotContainsString('text_checkout_helper_process1', $twig);
        self::assertStringNotContainsString('text_checkout_helper_process2', $twig);
    }

    public function testShopConfigurationFlagsSecondaryProcess(): void
    {
        self::assertFalse(\Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationFlags::isSecondaryProcess([]));
        self::assertFalse(\Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationFlags::isSecondaryProcess(['uni_proces' => 0]));
        self::assertTrue(\Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationFlags::isSecondaryProcess(['uni_proces' => 1]));
    }

    /**
     * @return array{bg: array<string, string>, en: array<string, string>}
     */
    private function loadCheckoutHelperLangs(): array
    {
        $_ = [];
        include dirname(__DIR__) . '/catalog/language/bg-bg/payment/mt_uni_credit.php';
        $bg = $_;
        $_ = [];
        include dirname(__DIR__) . '/catalog/language/en-gb/payment/mt_uni_credit.php';
        $en = $_;

        return ['bg' => $bg, 'en' => $en];
    }

    public function testCheckoutJsUsesUnifiedDropdownWithoutOfferTabs(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js');
        self::assertStringNotContainsString('renderOfferTabs', $js);
        self::assertStringNotContainsString('data-mtuc-offers', $js);
        self::assertStringContainsString('unifiedSchemes', $js);
        self::assertStringContainsString('resolvePreferredSchemeKey', $js);
        self::assertStringContainsString("popup_offer_type: 'standard'", $js);
        self::assertStringContainsString('preferred_scheme_key', $js);
    }

    public function testConfirmButtonWordingUnchanged(): void
    {
        $bg = (string) file_get_contents(dirname(__DIR__) . '/catalog/language/bg-bg/payment/mt_uni_credit.php');
        $en = (string) file_get_contents(dirname(__DIR__) . '/catalog/language/en-gb/payment/mt_uni_credit.php');
        self::assertStringContainsString("'Потвърди поръчката'", $bg);
        self::assertStringContainsString("'Confirm order'", $en);
    }

    public function testSharedOrderingInputPermutation(): void
    {
        $shop = CalculatorTestHarness::defaultShop();
        $schemes = [
            new AvailableScheme('promo', 'P12', 12, 4, ['uni_promo' => 1], ['interestPercent' => 0, 'coeff' => 0.08]),
            new AvailableScheme('standard', 'S6', 6, 1, ['uni_promo' => 0], ['interestPercent' => 18, 'coeff' => 0.09]),
            new AvailableScheme('standard', 'S12', 12, 2, ['uni_promo' => 0], ['interestPercent' => 18, 'coeff' => 0.09]),
            new AvailableScheme('promo', 'P6', 6, 3, ['uni_promo' => 1], ['interestPercent' => 0, 'coeff' => 0.08]),
            new AvailableScheme('standard', 'S18', 18, 5, ['uni_promo' => 0], ['interestPercent' => 18, 'coeff' => 0.09]),
        ];
        $sorted = SchemePresentationCategory::sort($schemes, $shop);
        self::assertSame([
            '6:standard',
            '6:promo',
            '12:standard',
            '12:promo',
            '18:standard',
        ], array_map(static fn(AvailableScheme $s): string => $s->months . ':' . $s->type, $sorted));
    }

    public function testProductPresenterEqualMonthStandardBeforePromo(): void
    {
        $shop = CalculatorTestHarness::defaultShop();
        $line = new \Opencart\System\Library\Extension\MtUniCredit\OpenCartProductLine(
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
        $presenter = new ProductCalculatorPresenter(
            new Calculator(),
            new CurrencyGate(),
            new ProductSchemeList(new Calculator()),
            new InstallmentLabelFormatter()
        );
        $payload = $presenter->present($shop, $line, 'BGN');
        self::assertNotNull($payload);
        self::assertArrayHasKey('standard', $payload['offers']);
        $schemes = $payload['offers']['standard']['schemes'];
        $byMonth = [];
        foreach ($schemes as $scheme) {
            $byMonth[(int) $scheme['months']][] = (string) $scheme['scheme_type'];
        }
        foreach ($byMonth as $types) {
            $promoIndexes = [];
            $standardIndexes = [];
            foreach ($types as $i => $type) {
                if ($type === 'promo') {
                    $promoIndexes[] = $i;
                }
                if ($type === 'standard') {
                    $standardIndexes[] = $i;
                }
            }
            if ($promoIndexes !== [] && $standardIndexes !== []) {
                // PHPUnit: assertLessThan($expected, $actual) ⇒ $actual < $expected
                self::assertLessThan(min($promoIndexes), max($standardIndexes));
            }
        }
        $preferred = (string) $payload['offers']['standard']['preferred_scheme_key'];
        self::assertNotSame('', $preferred);
        self::assertContains($preferred, array_column($schemes, 'key'));
    }

    public function testCartPresenterEqualMonthStandardBeforePromo(): void
    {
        $shop = CalculatorTestHarness::defaultShop();
        $cart = new CartContext([
            mt_uni_credit_cart_line(1, [7], 1000, 1, 1000),
        ], 1000.0);
        $presenter = new CartCalculatorPresenter(
            new CartSchemeResolver(new Calculator()),
            new Calculator(),
            new CurrencyGate(),
            new InstallmentLabelFormatter()
        );
        $payload = $presenter->present($shop, $cart, 'BGN');
        self::assertNotNull($payload);
        self::assertArrayHasKey('standard', $payload['offers']);
        $schemes = $payload['offers']['standard']['schemes'];
        $monthsWithBoth = [];
        foreach ($schemes as $index => $scheme) {
            $m = (int) $scheme['months'];
            $monthsWithBoth[$m][(string) $scheme['scheme_type']] = $index;
        }
        foreach ($monthsWithBoth as $pair) {
            if (isset($pair['standard'], $pair['promo'])) {
                self::assertLessThan($pair['promo'], $pair['standard']);
            }
        }
        $preferred = (string) $payload['offers']['standard']['preferred_scheme_key'];
        self::assertContains($preferred, array_column($schemes, 'key'));
    }

    public function testCheckoutJsSelectsPreferredNotFirstOnly(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js');
        self::assertStringContainsString('resolvePreferredSchemeKey', $js);
        self::assertStringContainsString('offers.standard?.preferred_scheme_key', $js);
        self::assertStringContainsString('select.value = selectedSchemeKey', $js);
    }
}
