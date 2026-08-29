<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\CalculatorTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\AvailableScheme;
use Opencart\System\Library\Extension\MtUniCredit\FinancialCalculator;
use Opencart\System\Library\Extension\MtUniCredit\OfferFactory;
use Opencart\System\Library\Extension\MtUniCredit\UnavailableSchemeException;
use PHPUnit\Framework\TestCase;

final class Phase5CalculatorDomainTest extends TestCase
{
    public function testAmountJustBelowMinimumIsRejected(): void
    {
        $calculator = CalculatorTestHarness::calculator();
        self::assertFalse($calculator->isAvailableForAmount(CalculatorTestHarness::defaultShop(), 99.99));
    }

    public function testAmountAtMinimumIsAccepted(): void
    {
        $calculator = CalculatorTestHarness::calculator();
        self::assertTrue($calculator->isAvailableForAmount(CalculatorTestHarness::defaultShop(), 100.0));
    }

    public function testPromoDateBoundaryIsInclusive(): void
    {
        $shop = CalculatorTestHarness::schemaShop();
        $calculator = CalculatorTestHarness::calculator();
        self::assertNotEmpty($calculator->availableSchemes($shop, CalculatorTestHarness::defaultProduct(), 'promo'));

        $before = new \Opencart\System\Library\Extension\MtUniCredit\Calculator('2026-08-16');
        self::assertSame([], $before->availableSchemes($shop, CalculatorTestHarness::defaultProduct(), 'promo'));

        $after = new \Opencart\System\Library\Extension\MtUniCredit\Calculator('2026-08-18');
        self::assertSame([], $after->availableSchemes($shop, CalculatorTestHarness::defaultProduct(), 'promo'));
    }

    public function testZeroCoefficientOfferFactoryReturnsNull(): void
    {
        $factory = new OfferFactory(new FinancialCalculator());
        self::assertNull($factory->create('standard', 'STD', 12, 1000.0, ['coeff' => 0, 'interestPercent' => 18], 0));
    }

    public function testMalformedCoefficientCalculateThrows(): void
    {
        $calculator = CalculatorTestHarness::calculator();
        $scheme = new AvailableScheme('standard', 'STD', 12, 0, null, ['coeff' => 0, 'interestPercent' => 18]);
        $this->expectException(UnavailableSchemeException::class);
        $calculator->calculateScheme(CalculatorTestHarness::defaultShop(), 1000.0, $scheme);
    }

    public function testAmbiguousFirstInstallmentSchemeThrows(): void
    {
        $calculator = CalculatorTestHarness::calculator();
        $scheme = new AvailableScheme(
            'standard',
            'CAT',
            12,
            0,
            null,
            ['coeff' => 0.09, 'interestPercent' => 10],
            true
        );
        $this->expectException(UnavailableSchemeException::class);
        $calculator->calculateScheme(CalculatorTestHarness::defaultShop(), 1000.0, $scheme);
    }

    public function testEqualMonthStandardAndPromoRemainDistinctInOrdering(): void
    {
        $shop = CalculatorTestHarness::defaultShop();
        $schemes = [
            new AvailableScheme('promo', 'PROMO', 12, 3, ['uni_promo' => 1], ['interestPercent' => 0, 'coeff' => 0.083333]),
            new AvailableScheme('standard', 'NZP', 12, 2, ['uni_promo' => 0], ['interestPercent' => 10, 'coeff' => 0.09]),
            new AvailableScheme('standard', 'STD', 12, 1, ['uni_promo' => 0], ['interestPercent' => 18, 'coeff' => 0.095]),
        ];
        $sorted = \Opencart\System\Library\Extension\MtUniCredit\SchemePresentationCategory::sort($schemes, $shop);
        self::assertSame('STD', $sorted[0]->kopCode);
        self::assertSame('NZP', $sorted[1]->kopCode);
        self::assertSame('PROMO', $sorted[2]->kopCode);
    }

    public function testCanonicalOrderMonthsAscThenStandardBeforePromo(): void
    {
        $shop = CalculatorTestHarness::defaultShop();
        // Promo listed first in input; must not win equal-month ordering.
        $schemes = [
            new AvailableScheme('promo', 'P12', 12, 4, ['uni_promo' => 1], ['interestPercent' => 0, 'coeff' => 0.08]),
            new AvailableScheme('standard', 'S6', 6, 1, ['uni_promo' => 0], ['interestPercent' => 18, 'coeff' => 0.09]),
            new AvailableScheme('standard', 'S12', 12, 2, ['uni_promo' => 0], ['interestPercent' => 18, 'coeff' => 0.09]),
            new AvailableScheme('promo', 'P6', 6, 3, ['uni_promo' => 1], ['interestPercent' => 0, 'coeff' => 0.08]),
            new AvailableScheme('standard', 'S18', 18, 5, ['uni_promo' => 0], ['interestPercent' => 18, 'coeff' => 0.09]),
        ];
        $sorted = \Opencart\System\Library\Extension\MtUniCredit\SchemePresentationCategory::sort($schemes, $shop);
        $keys = array_map(
            static fn(AvailableScheme $s): string => $s->months . ':' . $s->type,
            $sorted
        );
        self::assertSame([
            '6:standard',
            '6:promo',
            '12:standard',
            '12:promo',
            '18:standard',
        ], $keys);
    }

    /**
     * Regression: promo typed scheme that classifies as presentation "standard" must not
     * precede a standard-typed scheme at the same months.
     */
    public function testTypeBeatsMisclassifiedPresentationRank(): void
    {
        $shop = CalculatorTestHarness::defaultShop();
        $defaultKop = trim((string) ($shop['kop']['by_default']['uni_kop_default'] ?? 'STD'));
        $schemes = [
            new AvailableScheme('promo', $defaultKop, 12, 9, ['uni_promo' => 1], ['interestPercent' => 5, 'coeff' => 0.09]),
            new AvailableScheme('standard', 'OTHER', 12, 1, ['uni_promo' => 0], ['interestPercent' => 10, 'coeff' => 0.09]),
        ];
        $sorted = \Opencart\System\Library\Extension\MtUniCredit\SchemePresentationCategory::sort($schemes, $shop);
        self::assertSame('standard', $sorted[0]->type);
        self::assertSame('promo', $sorted[1]->type);
    }

    public function testQuantityDoesNotChangeCartTotalBasedEligibility(): void
    {
        $resolver = CalculatorTestHarness::cartResolver();
        $shop = CalculatorTestHarness::defaultShop();
        $single = $resolver->resolve($shop, new \Opencart\System\Library\Extension\MtUniCredit\CartContext([
            mt_uni_credit_cart_line(1, [7], 600, 1, 600),
        ], 600));
        $double = $resolver->resolve($shop, new \Opencart\System\Library\Extension\MtUniCredit\CartContext([
            mt_uni_credit_cart_line(1, [7], 600, 2, 600),
        ], 600));
        self::assertNotNull($single->standardOffer);
        self::assertNotNull($double->standardOffer);
        self::assertSame($single->standardOffer->monthlyInstallment, $double->standardOffer->monthlyInstallment);
    }
}
