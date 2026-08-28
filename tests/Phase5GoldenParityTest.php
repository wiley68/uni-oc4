<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\CalculatorTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\AvailableScheme;
use Opencart\System\Library\Extension\MtUniCredit\Calculator;
use Opencart\System\Library\Extension\MtUniCredit\CartContext;
use Opencart\System\Library\Extension\MtUniCredit\CurrencyGate;
use Opencart\System\Library\Extension\MtUniCredit\MonthResolver;
use Opencart\System\Library\Extension\MtUniCredit\Offer;
use Opencart\System\Library\Extension\MtUniCredit\PreferredOfferSelector;
use Opencart\System\Library\Extension\MtUniCredit\ProductContext;
use Opencart\System\Library\Extension\MtUniCredit\SchemePresentationCategory;
use Opencart\System\Library\Extension\MtUniCredit\UnavailableSchemeException;
use PHPUnit\Framework\TestCase;

final class Phase5GoldenParityTest extends TestCase
{
    private Calculator $calculator;

    /** @var array<string, mixed> */
    private array $golden;

    /** @var array<string, array<string, mixed>> */
    private array $cases = [];

    protected function setUp(): void
    {
        $this->calculator = CalculatorTestHarness::calculator();
        $this->golden = CalculatorTestHarness::goldenFixture();
        foreach ($this->golden['cases'] as $case) {
            $this->cases[$case['id']] = $case;
        }
    }

    public function testStandardPreferredOfferGoldenVector(): void
    {
        $expect = $this->cases['standard_preferred']['expect'];
        $product = CalculatorTestHarness::defaultProduct(1000.0);
        $offer = $this->calculator->resolvePreferredOffer(CalculatorTestHarness::defaultShop(), $product, 'standard');

        self::assertNotNull($offer);
        self::assertSame('STD', $offer->kopCode);
        self::assertSame(12, $offer->months);
        self::assertSame($expect['monthly_installment'], $offer->monthlyInstallment);
        self::assertSame($expect['coeff'], $offer->coefficient);
        self::assertSame($expect['glp'], $offer->glp);
        self::assertSame($expect['gpr_offerFactory'], $offer->gpr);
        self::assertSame($expect['financed_amount'], $offer->financedAmount);
    }

    public function testZeroPercentPromoGprSplitIsPreserved(): void
    {
        $expect = $this->cases['promo_0_percent']['expect'];
        $product = CalculatorTestHarness::defaultProduct(1000.0);
        $shop = CalculatorTestHarness::defaultShop();
        $offer = $this->calculator->resolvePreferredOffer($shop, $product, 'promo');
        self::assertNotNull($offer);
        self::assertSame($expect['monthly_installment'], $offer->monthlyInstallment);
        self::assertSame($expect['gpr_offerFactory'], $offer->gpr);

        $calculation = $this->calculator->calculate($shop, $product, 12, 'promo');
        self::assertSame($expect['gpr_calculateScheme'], $calculation->gpr);
    }

    public function testNonZeroInterestPromoIsRejected(): void
    {
        $shop = CalculatorTestHarness::defaultShop();
        $shop['coeff_list'][3]['interestPercent'] = 1;
        $offer = $this->calculator->resolvePreferredOffer(
            $shop,
            CalculatorTestHarness::defaultProduct(),
            'promo'
        );
        self::assertNull($offer);
    }

    public function testLockedFirstInstallmentGoldenVector(): void
    {
        $case = $this->cases['first_installment_locked_uni_parva'];
        $shop = CalculatorTestHarness::schemaShop();
        $product = CalculatorTestHarness::defaultProduct($case['input']['price']);
        $result = $this->calculator->calculate(
            $shop,
            $product,
            $case['input']['months'],
            $case['input']['type'],
            $case['input']['requested_first'],
            $case['input']['filter_id']
        );

        self::assertSame($case['expect']['first_installment'], $result->firstInstallment->amount);
        self::assertTrue($result->firstInstallment->locked);
        self::assertTrue($result->firstInstallment->visible);
        self::assertSame($case['expect']['financed_amount'], $result->financedAmount);
        self::assertSame($case['expect']['monthly_installment'], $result->monthlyInstallment);
        self::assertSame($case['expect']['total_payable'], $result->totalPayable);
        self::assertSame($case['expect']['gpr_calculateScheme'], $result->gpr);
    }

    public function testUserRequestedFirstInstallmentGoldenVector(): void
    {
        $case = $this->cases['first_installment_user_requested'];
        $shop = CalculatorTestHarness::defaultShop();
        $product = CalculatorTestHarness::defaultProduct($case['input']['price']);
        $result = $this->calculator->calculate(
            $shop,
            $product,
            $case['input']['months'],
            $case['input']['type'],
            $case['input']['requested_first']
        );

        self::assertSame($case['expect']['first_installment'], $result->firstInstallment->amount);
        self::assertFalse($result->firstInstallment->locked);
        self::assertSame($case['expect']['financed_amount'], $result->financedAmount);
        self::assertSame($case['expect']['monthly_installment'], $result->monthlyInstallment);
        self::assertSame($case['expect']['total_payable'], $result->totalPayable);
    }

    public function testPriceBoundariesGoldenVector(): void
    {
        $shop = CalculatorTestHarness::defaultShop();
        $productLow = CalculatorTestHarness::defaultProduct(100.0);
        $productHigh = CalculatorTestHarness::defaultProduct(10000.0);
        $productOver = CalculatorTestHarness::defaultProduct(10000.01);

        self::assertTrue($this->calculator->isAvailableForAmount($shop, $productLow->price));
        self::assertTrue($this->calculator->isAvailableForAmount($shop, $productHigh->price));
        self::assertFalse($this->calculator->isAvailableForAmount($shop, $productOver->price));
        self::assertNull($this->calculator->resolvePreferredOffers($shop, $productOver)['standard']);
        self::assertNull($this->calculator->resolvePreferredOffer($shop, CalculatorTestHarness::defaultProduct(499.99), 'promo'));
        self::assertNotNull($this->calculator->resolvePreferredOffer($shop, CalculatorTestHarness::defaultProduct(500.0), 'promo'));
    }

    public function testMonthSelectionGoldenVector(): void
    {
        $expect = $this->cases['month_selection']['expect'];
        $months = new MonthResolver();
        $shop = CalculatorTestHarness::defaultShop();

        self::assertSame($expect['enabled_shop_months'], $months->enabledMonths($shop));
        self::assertSame($expect['range_min'], MonthResolver::MIN);
        self::assertSame($expect['range_max'], MonthResolver::MAX);

        $greateqShop = CalculatorTestHarness::defaultShop([
            'kop' => [
                'by_default' => [
                    'uni_promo_meseci_znak' => 'greateq',
                    'uni_promo_meseci'      => '12',
                ],
            ],
        ]);
        self::assertSame(
            $expect['promo_greateq_12'],
            $months->defaultPromoMonths(
                $greateqShop['kop']['by_default'],
                1000.0,
                $months->enabledMonths($greateqShop)
            )
        );

        $fallbackShop = CalculatorTestHarness::defaultShop(['uni_shema_current' => 18]);
        $promo = $this->calculator->resolvePreferredOffer(
            $fallbackShop,
            CalculatorTestHarness::defaultProduct(),
            'promo'
        );
        self::assertNotNull($promo);
        self::assertSame($expect['preferred_18_missing_falls_back_to_highest_promo_month'], $promo->months);

        $invalidPreferredShop = CalculatorTestHarness::defaultShop([
            'coeff_list' => [
                ['onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 18],
                ['onlineProductCode' => 'PROMO', 'installmentCount' => 12, 'coeff' => 0, 'interestPercent' => 0],
                ['onlineProductCode' => 'PROMO', 'installmentCount' => 24, 'coeff' => 0.041667, 'interestPercent' => 0],
            ],
        ]);
        self::assertNull($this->calculator->resolvePreferredOffer(
            $invalidPreferredShop,
            CalculatorTestHarness::defaultProduct(),
            'promo'
        ));
    }

    public function testPreferredOfferSelectorGoldenVectors(): void
    {
        $selector = new PreferredOfferSelector();

        $tie = $this->cases['preferred_offer_tie_break'];
        $tieOffers = array_map(
            static fn(array $row): Offer => new Offer(
                $row['type'],
                $row['kop'],
                $row['months'],
                $row['monthly'],
                10.0,
                11.0,
                1000.0,
                0.09,
                0
            ),
            $tie['input']['candidates']
        );
        $picked = $selector->select($tieOffers, $tie['input']['preferred_months']);
        self::assertNotNull($picked);
        self::assertSame($tie['expect']['kop'], $picked->kopCode);
        self::assertSame($tie['expect']['months'], $picked->months);

        $fallback = $this->cases['preferred_offer_fallback_highest_months'];
        $fallbackOffers = array_map(
            static fn(array $row): Offer => new Offer(
                $row['type'],
                $row['kop'],
                $row['months'],
                $row['monthly'],
                10.0,
                11.0,
                1000.0,
                0.09,
                0
            ),
            $fallback['input']['candidates']
        );
        $fallbackPicked = $selector->select($fallbackOffers, $fallback['input']['preferred_months']);
        self::assertNotNull($fallbackPicked);
        self::assertSame($fallback['expect']['kop'], $fallbackPicked->kopCode);
        self::assertSame($fallback['expect']['months'], $fallbackPicked->months);
    }

    public function testSchemeOrderingGoldenVector(): void
    {
        $case = $this->cases['scheme_ordering'];
        $shop = CalculatorTestHarness::defaultShop();
        $schemes = array_map(
            static function (array $row): AvailableScheme {
                return new AvailableScheme(
                    $row['type'] === 'promo' ? 'promo' : 'standard',
                    $row['kop'],
                    $row['months'],
                    $row['filterId'],
                    ['uni_promo' => $row['uni_promo']],
                    ['interestPercent' => $row['interestPercent'], 'coeff' => 0.1]
                );
            },
            $case['input']
        );
        $sorted = SchemePresentationCategory::sort($schemes, $shop);
        $labels = array_map(
            static fn(AvailableScheme $scheme): string => SchemePresentationCategory::presentationLabel($scheme, $shop),
            $sorted
        );
        self::assertSame($case['expect_labels'], $labels);
    }

    public function testFilterEligibilityGoldenVector(): void
    {
        $expect = $this->cases['filter_eligibility']['expect'];
        $shop = CalculatorTestHarness::schemaShop();
        $product = CalculatorTestHarness::defaultProduct();
        $standards = $this->calculator->availableSchemes($shop, $product, 'standard');
        $promos = $this->calculator->availableSchemes($shop, $product, 'promo');

        self::assertCount($expect['product_42_cat_7_9_standard_scheme_count'], $standards);
        self::assertSame($expect['first_filter_id'], $standards[0]->filterId);
        self::assertSame($expect['last_filter_id'], $standards[count($standards) - 1]->filterId);
        self::assertCount($expect['promo_scheme_count'], $promos);
        self::assertSame($expect['promo_filter_id'], $promos[0]->filterId);

        $bothFilterShop = CalculatorTestHarness::schemaShop([
            'kop' => ['by_schema' => ['filters' => [[
                'id' => 99,
                'category_id' => 7,
                'product_id' => 42,
                'uni_meseci' => '12',
                'uni_promo' => 0,
                'uni_kop' => 'CAT',
            ]]]],
        ]);
        self::assertSame([], $this->calculator->availableSchemes($bothFilterShop, $product, 'standard'));

        self::assertSame([], $this->calculator->availableSchemes($shop, new ProductContext(50, [8], 1000.0), 'standard'));
        self::assertSame([], $this->calculator->availableSchemes($shop, new ProductContext(50, [7], 499.99), 'standard'));
        self::assertCount(
            $expect['price_500_standard_count'],
            $this->calculator->availableSchemes($shop, new ProductContext(50, [7], 500.0), 'standard')
        );

        $futureCalculator = new Calculator('2026-09-01');
        self::assertSame(
            [],
            $futureCalculator->availableSchemes($shop, new ProductContext(50, [7], 1000.0), 'standard')
        );
    }

    public function testCurrencyGateGoldenVector(): void
    {
        $expect = $this->cases['currency_gate']['expect'];
        $gate = new CurrencyGate();
        foreach ([0, 1] as $mode) {
            $shop = CalculatorTestHarness::defaultShop(['uni_eur' => $mode]);
            self::assertSame($expect['uni_eur_0_or_1_expected_iso'], $gate->expectedIso($shop));
            self::assertTrue($gate->supports($shop, 'BGN'));
            self::assertFalse($gate->supports($shop, 'EUR'));
        }
        foreach ([2, 3] as $mode) {
            $shop = CalculatorTestHarness::defaultShop(['uni_eur' => $mode]);
            self::assertSame($expect['uni_eur_2_or_3_expected_iso'], $gate->expectedIso($shop));
            self::assertTrue($gate->supports($shop, 'EUR'));
            self::assertFalse($gate->supports($shop, 'BGN'));
        }
        self::assertSame($expect['display_rate'], \Opencart\System\Library\Extension\MtUniCredit\AmountDisplayFormatter::DISPLAY_RATE);
    }

    public function testDisabledMonthCalculateThrows(): void
    {
        $shop = CalculatorTestHarness::defaultShop(['uni_meseci_12' => 0]);
        $this->expectException(UnavailableSchemeException::class);
        $this->calculator->calculate($shop, CalculatorTestHarness::defaultProduct(), 12, 'standard');
    }
}
