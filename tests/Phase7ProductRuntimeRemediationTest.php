<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\ProductFinancingTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\CurrencyDisplayLabel;
use Opencart\System\Library\Extension\MtUniCredit\InstallmentLabelFormatter;
use Opencart\System\Library\Extension\MtUniCredit\ProductOptionNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Runtime recalculation must follow the proven mt_jet_credit OpenCart Product interaction pattern.
 */
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
        self::assertNotSame($lineBase->financingPrice, $lineOption->financingPrice);
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

    public function testOptionNormalizerAcceptsProduct40CheckboxPayload(): void
    {
        // Real Product 40 required checkbox: option[231][] = product_option_value_id
        $normalized = ProductOptionNormalizer::normalize(['231' => ['34']]);
        self::assertSame([34], $normalized[231]);
    }

    public function testCalculateControllerDoesNotTypeHintConcreteModelReturn(): void
    {
        // OpenCart wraps models in Engine\Proxy — concrete return types cause TypeError → HTTP 500.
        $controller = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_product.php'
        );

        self::assertMatchesRegularExpression('/function productModel\(\):\s*object/', $controller);
        self::assertStringNotContainsString('function productModel(): ProductFinancingModel', $controller);
        self::assertStringContainsString('logCalculateFailure', $controller);
    }

    public function testJsFollowsJetOpenCartProductListenerContract(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
        $jet = (string) file_get_contents(
            dirname(__DIR__, 2) . '/mt_jet_credit/catalog/view/javascript/creditjet_products.js'
        );

        // Jet proven selectors (authoritative OC4 Product interaction).
        self::assertStringContainsString('[id^="input-option"]', $jet);
        self::assertStringContainsString('[name="quantity"]', $jet);

        // UniCredit must use the same Product DOM contract.
        self::assertStringContainsString('[id^="input-option"]', $js);
        self::assertStringContainsString('#input-quantity, input[name="quantity"]', $js);
        self::assertStringContainsString('bindProductRecalculationListeners', $js);
        self::assertStringContainsString('scheduleRefreshCalculator', $js);
        self::assertStringContainsString('option change detected', $js);
        self::assertStringContainsString('quantity change detected', $js);
        self::assertStringContainsString('recalculation request started', $js);
        self::assertStringContainsString('recalculation response received', $js);
        self::assertStringContainsString('calculator DOM updated', $js);
        self::assertStringContainsString('recalculation stale response ignored', $js);
        self::assertStringContainsString('syncBootstrap', $js);
        self::assertStringContainsString("submissionToken = ''", $js);
        // Avoid brittle CSS attribute selector with unescaped "[" in name^=.
        self::assertStringNotContainsString('[name^="option["]', $js);
    }

    public function testOc41ProductTwigMatchesJetListenerSelectors(): void
    {
        $productTwig = (string) file_get_contents(
            dirname(__DIR__, 3) . '/catalog/view/template/product/product.twig'
        );

        self::assertStringContainsString('id="form-product"', $productTwig);
        self::assertStringContainsString('id="product"', $productTwig);
        self::assertStringContainsString('id="input-quantity"', $productTwig);
        self::assertStringContainsString('name="quantity"', $productTwig);
        self::assertStringContainsString('id="input-option-{{ option.product_option_id }}"', $productTwig);
        self::assertStringContainsString('name="option[{{ option.product_option_id }}]"', $productTwig);
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

    public function testOffersDifferWhenQuantityChanges(): void
    {
        $shop = ProductFinancingTestHarness::shop();
        $presenter = ProductFinancingTestHarness::presenter();
        $one = $presenter->present(
            $shop,
            ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 42, 1, []),
            'BGN'
        );
        $two = $presenter->present(
            $shop,
            ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 42, 2, []),
            'BGN'
        );

        self::assertNotNull($one);
        self::assertNotNull($two);
        self::assertNotSame(
            $one['offers']['standard']['monthly_installment'],
            $two['offers']['standard']['monthly_installment']
        );
        self::assertNotSame(
            $one['offers']['standard']['installment_label'],
            $two['offers']['standard']['installment_label']
        );
    }
}
