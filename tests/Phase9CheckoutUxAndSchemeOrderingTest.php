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
        self::assertStringNotContainsString('name="egn"', $twig);
        self::assertStringNotContainsString('name="phone2"', $twig);
        self::assertStringContainsString('data-mtuc-schemes', $twig);
        self::assertStringContainsString('data-mtuc-submit', $twig);
        self::assertStringContainsString('{% if consents %}', $twig);
        self::assertStringContainsString('data-mtuc-consents', $twig);
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
