<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FixtureLoader;
use PHPUnit\Framework\TestCase;

final class CalculatorGoldenFixturesTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $fixture;

    /** @var array<string, array<string, mixed>> */
    private array $cases = [];

    protected function setUp(): void
    {
        $this->fixture = FixtureLoader::load('calculator_golden.json');
        foreach ($this->fixture['cases'] as $case) {
            $this->cases[$case['id']] = $case;
        }
    }

    public function testRequiredCategoriesArePresent(): void
    {
        $categories = array_column($this->fixture['cases'], 'category');
        foreach (
            [
                'standard_scheme',
                'promotional_scheme',
                'first_installment',
                'price_boundary',
                'month_selection',
                'preferred_offer',
                'scheme_ordering',
                'filter_eligibility',
                'cart_intersection',
                'currency',
            ] as $required
        ) {
            self::assertContains($required, $categories, $required . ' missing from golden fixtures');
        }
    }

    public function testStandardPreferredOfferNumbers(): void
    {
        $expect = $this->cases['standard_preferred']['expect'];
        $price = 1000.0;
        $monthly = round($price * $expect['coeff'], 2);
        self::assertSame(95.00, $monthly);
        self::assertSame(95.00, $expect['monthly_installment']);
        self::assertSame('STD', $expect['kop_code']);
        self::assertSame(12, $expect['months']);
        self::assertSame(27.96, $expect['gpr_offerFactory']);
    }

    public function testZeroPercentPromoHasOfferFactoryVersusCalculateSchemeGprSplit(): void
    {
        $expect = $this->cases['promo_0_percent']['expect'];
        self::assertSame(83.33, round(1000.0 * $expect['coeff'], 2));
        self::assertSame(83.33, $expect['monthly_installment']);
        self::assertSame(0.01, $expect['gpr_offerFactory']);
        self::assertSame(0.0, $expect['gpr_calculateScheme']);
        self::assertFalse($this->cases['nonzero_interest_promo_rejected']['expect']['promo_present']);
    }

    public function testLockedFirstInstallmentVector(): void
    {
        $case = $this->cases['first_installment_locked_uni_parva'];
        $price = $case['input']['price'];
        $months = $case['input']['months'];
        $first = round($price / $months, 2);
        $financed = round($price - $first, 2);
        $monthly = round($financed * $case['input']['coeff'], 2);
        $total = round($monthly * $months, 2);

        self::assertSame(41.67, $first);
        self::assertSame(41.67, $case['expect']['first_installment']);
        self::assertSame(958.33, $financed);
        self::assertSame(958.33, $case['expect']['financed_amount']);
        self::assertSame(47.92, $monthly);
        self::assertSame(47.92, $case['expect']['monthly_installment']);
        self::assertSame(1150.08, $total);
        self::assertSame(1150.08, $case['expect']['total_payable']);
        self::assertTrue($case['expect']['locked']);
    }

    public function testUserFirstInstallmentVector(): void
    {
        $case = $this->cases['first_installment_user_requested'];
        $financed = round($case['input']['price'] - $case['expect']['first_installment'], 2);
        $monthly = round($financed * $case['input']['coeff'], 2);
        self::assertSame(800.0, $financed);
        self::assertSame(76.0, $monthly);
        self::assertSame(912.0, round($monthly * $case['input']['months'], 2));
        self::assertFalse($case['expect']['locked']);
    }

    public function testPriceBoundariesAreInclusive(): void
    {
        $shop = $this->fixture['shop'];
        $boundary = $this->cases['price_boundary_shop']['expect'];
        self::assertTrue($boundary['price_100_inclusive']);
        self::assertTrue($boundary['price_10000_inclusive']);
        self::assertTrue($boundary['price_10000_01_rejected']);
        self::assertSame(100, $shop['uni_minstojnost']);
        self::assertSame(10000, $shop['uni_maxstojnost']);
        $promo = $this->cases['price_boundary_promo_min']['expect'];
        self::assertTrue($promo['price_499_99_promo_null']);
        self::assertTrue($promo['price_500_promo_present']);
    }

    public function testMonthSelectionAndPreferredFallback(): void
    {
        $expect = $this->cases['month_selection']['expect'];
        self::assertSame([6, 12, 24], $expect['enabled_shop_months']);
        self::assertSame(3, $expect['range_min']);
        self::assertSame(36, $expect['range_max']);
        self::assertSame([12, 24], $expect['promo_greateq_12']);
        self::assertSame(24, $expect['preferred_18_missing_falls_back_to_highest_promo_month']);
        self::assertTrue($expect['invalid_preferred_promo_coeff_does_not_fallback']);
    }

    public function testPreferredOfferTieBreakAndFallback(): void
    {
        $tie = $this->cases['preferred_offer_tie_break'];
        self::assertSame('B', $tie['expect']['kop']);
        self::assertSame(12, $tie['expect']['months']);
        $fallback = $this->cases['preferred_offer_fallback_highest_months'];
        self::assertSame('C', $fallback['expect']['kop']);
        self::assertSame(24, $fallback['expect']['months']);
    }

    public function testSchemeOrderingLabels(): void
    {
        $case = $this->cases['scheme_ordering'];
        self::assertSame([
            '4:standard',
            '6:standard',
            '12:standard',
            '12:nonzero_promo',
            '12:zero_promo',
        ], $case['expect_labels']);
        self::assertCount(5, $case['input']);
    }

    public function testFilterAndCartIntersectionContracts(): void
    {
        $filter = $this->cases['filter_eligibility']['expect'];
        self::assertSame(3, $filter['product_42_cat_7_9_standard_scheme_count']);
        self::assertSame(10, $filter['first_filter_id']);
        self::assertSame(11, $filter['last_filter_id']);
        self::assertSame(1, $filter['promo_scheme_count']);
        self::assertTrue($filter['category_and_product_together_rejected']);

        $cart = $this->cases['cart_intersection']['expect'];
        self::assertSame('type|kopCode|months', $this->cases['cart_intersection']['identity_key']);
        self::assertSame(12, $cart['lcm_6_12']);
        self::assertSame(24, $cart['lcm_6_8']);
        self::assertTrue($cart['different_kops_empty']);
        self::assertSame(31, $cart['filter_id_is_metadata_lowest_wins']);
    }

    public function testCurrencyGate(): void
    {
        $expect = $this->cases['currency_gate']['expect'];
        self::assertSame('BGN', $expect['uni_eur_0_or_1_expected_iso']);
        self::assertSame('EUR', $expect['uni_eur_2_or_3_expected_iso']);
        self::assertSame(['BGN', 'EUR'], $expect['supported_iso']);
        self::assertSame(1.95583, $expect['display_rate']);
    }

    public function testShopFixtureHasRequiredCoefficientRows(): void
    {
        $codes = [];
        foreach ($this->fixture['shop']['coeff_list'] as $row) {
            $codes[] = $row['onlineProductCode'] . ':' . $row['installmentCount'];
        }
        self::assertContains('STD:12', $codes);
        self::assertContains('PROMO:12', $codes);
        self::assertContains('PRODUCT:24', $codes);
        self::assertContains('ZERO:12', $codes);
        self::assertCount(3, $this->fixture['schema_filters']);
    }
}
