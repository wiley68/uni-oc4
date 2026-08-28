<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\CalculatorTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\CartContext;
use Opencart\System\Library\Extension\MtUniCredit\ProductContext;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/calculator_fixture.php';

final class Phase5EntryPointConsistencyTest extends TestCase
{
    public function testProductAndCartPreferredStandardOfferUseSameFinancialMath(): void
    {
        $calculator = CalculatorTestHarness::calculator();
        $shop = CalculatorTestHarness::defaultShop();
        $product = CalculatorTestHarness::defaultProduct(1000.0);

        $productOffer = $calculator->resolvePreferredOffer($shop, $product, 'standard');
        $cartOffer = CalculatorTestHarness::cartResolver()->resolve(
            $shop,
            new CartContext([mt_uni_credit_cart_line(42, [7, 9], 1000.0)], 1000.0)
        )->standardOffer;

        self::assertNotNull($productOffer);
        self::assertNotNull($cartOffer);
        self::assertSame($productOffer->monthlyInstallment, $cartOffer->monthlyInstallment);
        self::assertSame($productOffer->gpr, $cartOffer->gpr);
        self::assertSame($productOffer->glp, $cartOffer->glp);
        self::assertSame($productOffer->coefficient, $cartOffer->coefficient);
    }

    public function testCalculateSchemeMatchesOfferFactoryForSameFinancedAmount(): void
    {
        $calculator = CalculatorTestHarness::calculator();
        $shop = CalculatorTestHarness::defaultShop();
        $product = CalculatorTestHarness::defaultProduct();
        $calculation = $calculator->calculate($shop, $product, 12, 'standard');
        $button = $calculator->createButtonOffer($calculation->scheme, $calculation->financedAmount, 'standard');

        self::assertNotNull($button);
        self::assertSame($calculation->monthlyInstallment, $button->monthlyInstallment);
        self::assertSame($calculation->financedAmount, $button->financedAmount);
    }

    public function testEquivalentNormalizedContextsProduceSameCalculation(): void
    {
        $calculator = CalculatorTestHarness::calculator();
        $shop = CalculatorTestHarness::schemaShop();
        $contextA = new ProductContext(42, [7, 9], 1000.0);
        $contextB = new ProductContext(42, [7, 9], 1000.0);

        $resultA = $calculator->calculate($shop, $contextA, 24, 'standard', 0.0, 11);
        $resultB = $calculator->calculate($shop, $contextB, 24, 'standard', 0.0, 11);

        self::assertSame($resultA->monthlyInstallment, $resultB->monthlyInstallment);
        self::assertSame($resultA->totalPayable, $resultB->totalPayable);
        self::assertSame($resultA->gpr, $resultB->gpr);
    }
}
