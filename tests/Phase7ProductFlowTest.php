<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\ProductFinancingTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\ProductOptionNormalizer;
use Opencart\System\Library\Extension\MtUniCredit\StandardThemeProductPlacement;
use PHPUnit\Framework\TestCase;

final class Phase7ProductFlowTest extends TestCase
{
    public function testBasePriceProductContext(): void
    {
        $line = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 42, 1, []);
        self::assertSame(1200.0, $line->financingPrice);
        self::assertSame([10], $line->categoryIds);
    }

    public function testSpecialPriceOverridesBase(): void
    {
        $line = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 43, 1, []);
        self::assertSame(960.0, $line->financingPrice);
    }

    public function testOptionsAndQuantityAffectFinancingPrice(): void
    {
        $line = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 44, 2, [10 => 501]);
        self::assertSame(2280.0, $line->financingPrice);
        self::assertNotEmpty($line->orderOptions);
    }

    public function testInvalidProductFails(): void
    {
        $this->expectException(ProductFinancingFlowException::class);
        ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 999, 1, []);
    }

    public function testOptionNormalizerSortsKeys(): void
    {
        $normalized = ProductOptionNormalizer::normalize(['12' => '5', '3' => '9']);
        self::assertSame([3, 12], array_keys($normalized));
    }

    public function testPreferredOfferOrderingIsServerAuthoritative(): void
    {
        $line = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 42, 1, []);
        $presented = ProductFinancingTestHarness::presenter()->present(ProductFinancingTestHarness::shop(), $line, 'BGN');
        self::assertNotNull($presented);
        self::assertArrayHasKey('standard', $presented['offers']);
        self::assertSame('standard|KOPSTD|12|0', $presented['offers']['standard']['preferred_scheme_key']);
        self::assertNotEmpty($presented['offers']['standard']['schemes']);
        self::assertSame(
            $presented['offers']['standard']['preferred_scheme_key'],
            $presented['offers']['standard']['schemes'][0]['key']
        );
    }

    public function testUnsupportedCurrencyReturnsNull(): void
    {
        $line = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 42, 1, []);
        self::assertNull(ProductFinancingTestHarness::presenter()->present(ProductFinancingTestHarness::shop(), $line, 'USD'));
    }

    public function testStandardThemeInsertionIsDeterministic(): void
    {
        $html = '<form id="form-product"><button type="submit" id="button-cart">Add</button><div></div></form>';
        $adapter = new StandardThemeProductPlacement();
        $fragment = '<div id="mt-uni-credit"></div>';
        $result = $adapter->insertAfterAddToCartBlock($html, $fragment);
        self::assertStringContainsString($fragment, $result);
        self::assertSame($result, $adapter->insertAfterAddToCartBlock($html, $fragment));
        self::assertStringEndsWith('</form>' . $fragment, $result);
        self::assertGreaterThan(
            (int) strpos($result, '</form>'),
            (int) strpos($result, 'id="mt-uni-credit"')
        );
    }

    public function testModalTwigHasDialogSemantics(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_product_modal.twig');
        self::assertStringContainsString('role="dialog"', $twig);
        self::assertStringContainsString('aria-modal="true"', $twig);
        self::assertStringContainsString('data-mtuc-step', $twig);
        self::assertStringContainsString('data-mtuc-apply', $twig);
    }

    public function testJavascriptHandlesEscapeAndFocusReturn(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
        self::assertStringContainsString("event.key === 'Escape'", $js);
        self::assertStringContainsString('lastTrigger.focus()', $js);
        self::assertStringContainsString('renderOfferButtons', $js);
        self::assertStringContainsString('scheduleRefreshCalculator', $js);
        self::assertStringContainsString('DOMContentLoaded', $js);
        self::assertStringContainsString('closest(TRIGGER_SELECTOR)', $js);
    }
}
