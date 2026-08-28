<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\CalculatorTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\CartContext;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/calculator_fixture.php';

final class Phase5CartSchemeResolverTest extends TestCase
{
    public function testCartIntersectionGoldenExpectations(): void
    {
        $expect = CalculatorTestHarness::goldenFixture()['cases'][array_search('cart_intersection', array_column(CalculatorTestHarness::goldenFixture()['cases'], 'id'), true)]['expect'];
        $resolver = CalculatorTestHarness::cartResolver();
        $shop = CalculatorTestHarness::defaultShop();

        $single = $resolver->resolve($shop, new CartContext([mt_uni_credit_cart_line(1, [7], 1000)], 1000));
        self::assertCount($expect['single_product_standard_count'], $single->standardSchemes);

        $same = $resolver->resolve($shop, new CartContext([
            mt_uni_credit_cart_line(1, [7], 1000, 1, 400),
            mt_uni_credit_cart_line(2, [8], 1000, 1, 600),
        ], 1000));
        self::assertCount($expect['same_standard_common_count'], $same->standardSchemes);
        self::assertNotNull($same->standardOffer);
        self::assertSame($expect['same_standard_preferred_months'], $same->standardOffer->months);
        self::assertCount($expect['same_promo_common_count'], $same->promoSchemes);
        self::assertNotNull($same->promoOffer);

        self::assertSame($expect['lcm_6_12'], $resolver->lcm([6, 12]));
        self::assertSame($expect['lcm_6_8'], $resolver->lcm([6, 8]));
    }

    public function testDifferentKopsOrMonthsYieldEmptyIntersection(): void
    {
        $resolver = CalculatorTestHarness::cartResolver();
        $differentKop = mt_uni_credit_calculator_fixture([
            'uni_typekop' => 1,
            'kop'         => ['by_schema' => ['filters' => [
                ['id' => 1, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_kop' => 'CAT'],
                ['id' => 2, 'product_id' => 2, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_kop' => 'PRODUCT'],
            ]]],
        ]);
        self::assertSame([], $resolver->resolve($differentKop, new CartContext([
            mt_uni_credit_cart_line(1, [], 1000),
            mt_uni_credit_cart_line(2, [], 1000),
        ], 1000))->standardSchemes);

        $differentMonths = mt_uni_credit_calculator_fixture([
            'uni_typekop' => 1,
            'kop'         => ['by_schema' => ['filters' => [
                ['id' => 3, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '6', 'uni_promo' => 0, 'uni_kop' => 'CAT'],
                ['id' => 4, 'product_id' => 2, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_kop' => 'CAT'],
            ]]],
        ]);
        self::assertSame([], $resolver->resolve($differentMonths, new CartContext([
            mt_uni_credit_cart_line(1, [], 1000),
            mt_uni_credit_cart_line(2, [], 1000),
        ], 1000))->standardSchemes);
    }

    public function testFilterIdIsMetadataLowestWins(): void
    {
        $resolver = CalculatorTestHarness::cartResolver();
        $shop = mt_uni_credit_calculator_fixture([
            'uni_typekop' => 1,
            'kop'         => ['by_schema' => ['filters' => [
                ['id' => 31, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'],
                ['id' => 32, 'product_id' => 2, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'],
            ]]],
        ]);
        $result = $resolver->resolve($shop, new CartContext([
            mt_uni_credit_cart_line(1, [], 1000),
            mt_uni_credit_cart_line(2, [], 1000),
        ], 1000));
        self::assertCount(1, $result->standardSchemes);
        self::assertSame(31, $result->standardSchemes[0]->filterId);
    }

    public function testCartTotalBoundsAndCommonZeroPromo(): void
    {
        $resolver = CalculatorTestHarness::cartResolver();
        $shop = CalculatorTestHarness::defaultShop();
        self::assertSame([], $resolver->resolve($shop, new CartContext([mt_uni_credit_cart_line(1, [], 99)], 99))->standardSchemes);
        self::assertNotSame([], $resolver->resolve($shop, new CartContext([mt_uni_credit_cart_line(1, [], 10000)], 10000))->standardSchemes);
        self::assertSame([], $resolver->resolve($shop, new CartContext([mt_uni_credit_cart_line(1, [], 10000.01)], 10000.01))->standardSchemes);

        $promoFilters = mt_uni_credit_calculator_fixture([
            'uni_typekop' => 1,
            'kop'         => ['by_schema' => ['filters' => [
                ['id' => 51, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 1, 'uni_kop' => 'ZERO'],
                ['id' => 52, 'product_id' => 2, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 1, 'uni_kop' => 'ZERO'],
            ]]],
        ]);
        self::assertCount(1, $resolver->resolve($promoFilters, new CartContext([
            mt_uni_credit_cart_line(1, [], 1000),
            mt_uni_credit_cart_line(2, [], 1000),
        ], 1000))->promoSchemes);
    }

    public function testCartUsesCartTotalNotLineTotalForPriceRules(): void
    {
        $resolver = CalculatorTestHarness::cartResolver();
        $shop = mt_uni_credit_calculator_fixture([
            'uni_typekop' => 1,
            'kop'         => ['by_schema' => ['filters' => [[
                'id'            => 40,
                'category_id'   => 7,
                'product_id'    => null,
                'uni_meseci'    => '12',
                'uni_price_from' => 900,
                'uni_price_to'  => 1100,
                'uni_promo'     => 0,
                'uni_parva'     => 0,
                'uni_kop'       => 'CAT',
            ]]]],
        ]);
        $result = $resolver->resolve($shop, new CartContext([
            mt_uni_credit_cart_line(1, [7], 1000, 2, 400),
            mt_uni_credit_cart_line(2, [7], 1000, 1, 600),
        ], 1000));
        self::assertCount(1, $result->standardSchemes);
    }
}
