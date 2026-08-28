<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\ProductFinancingTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\CurrencyDisplayLabel;
use Opencart\System\Library\Extension\MtUniCredit\InstallmentLabelFormatter;
use Opencart\System\Library\Extension\MtUniCredit\ProductOptionNormalizer;
use PHPUnit\Framework\TestCase;

final class Phase7ProductRuntimeRemediationTest extends TestCase
{
    public function testEurInstallmentLabelUsesEvroNotSymbol(): void
    {
        $formatter = new InstallmentLabelFormatter(new CurrencyDisplayLabel());
        $label = $formatter->format(12, 85.55, 3);

        self::assertSame('12 x 85.55 евро', $label);
        self::assertStringNotContainsString('€', $label);
        self::assertStringContainsString('евро', $label);
    }

    public function testBgnInstallmentLabelUsesLevSuffix(): void
    {
        $formatter = new InstallmentLabelFormatter(new CurrencyDisplayLabel());
        $label = $formatter->format(12, 97.49, 0);

        self::assertSame('12 x 97.49 лв.', $label);
    }

    public function testDualCurrencyInstallmentLabelUsesLevaAndEvro(): void
    {
        $formatter = new InstallmentLabelFormatter(new CurrencyDisplayLabel());
        $label = $formatter->format(12, 97.49, 1);

        self::assertStringContainsString('лева', $label);
        self::assertStringContainsString('евро', $label);
        self::assertStringNotContainsString('€', $label);
    }

    public function testCurrencyDisplayLabelIsoCodesRemainInternal(): void
    {
        $labels = new CurrencyDisplayLabel();
        self::assertSame('евро', $labels->forAmount('EUR'));
        self::assertSame('лв.', $labels->forAmount('BGN'));
    }

    public function testOptionChangeAffectsAuthoritativeFinancingPrice(): void
    {
        $lineBase = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 44, 1, []);
        $lineOption = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 44, 1, [10 => 501]);

        self::assertSame(1080.0, $lineBase->financingPrice);
        self::assertSame(1140.0, $lineOption->financingPrice);
    }

    public function testQuantityMultipliesLineFinancingAmount(): void
    {
        $lineOne = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 42, 1, []);
        $lineTwo = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 42, 2, []);

        self::assertSame(1200.0, $lineOne->financingPrice);
        self::assertSame(2400.0, $lineTwo->financingPrice);
    }

    public function testOptionNormalizerAcceptsFormPostedShape(): void
    {
        $normalized = ProductOptionNormalizer::normalize(['10' => '501', '12' => ['502', '503']]);
        self::assertSame(501, $normalized[10]);
        self::assertSame([502, 503], $normalized[12]);
    }

    public function testJsUsesFormProductDelegatedRecalculation(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');

        self::assertStringContainsString("document.getElementById('form-product')", $js);
        self::assertStringContainsString('scheduleRefreshCalculator', $js);
        self::assertStringContainsString('product recalculation triggered:', $js);
        self::assertStringContainsString('product recalculation completed', $js);
        self::assertStringContainsString('isRecalcControl', $js);
        self::assertStringContainsString('#input-quantity, input[name="quantity"]', $js);
        self::assertStringContainsString('[name^="option["]', $js);
        self::assertStringContainsString('syncBootstrap', $js);
        self::assertStringContainsString('submissionToken = \'\'', $js);
    }

    public function testPresenterReturnsUpdatedInstallmentLabelAfterContextChange(): void
    {
        $shop = ProductFinancingTestHarness::shop();
        $shop['uni_eur'] = 3;
        $presenter = ProductFinancingTestHarness::presenter();
        $line = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 44, 2, [10 => 501]);
        $presented = $presenter->present($shop, $line, 'EUR');

        self::assertNotNull($presented);
        self::assertStringContainsString('евро', $presented['offers']['standard']['installment_label']);
        self::assertStringNotContainsString('€', $presented['offers']['standard']['installment_label']);
    }
}
