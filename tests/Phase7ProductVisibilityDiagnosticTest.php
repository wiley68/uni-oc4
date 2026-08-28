<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\CurrencyGate;
use Opencart\System\Library\Extension\MtUniCredit\ProductContext;
use Opencart\System\Library\Extension\MtUniCredit\ProductVisibilityDebugLog;
use Opencart\System\Library\Extension\MtUniCredit\StandardThemeProductPlacement;
use PHPUnit\Framework\TestCase;

/**
 * Regression: Product 40-class failure — required options must not hide calculator on page load.
 */
final class Phase7ProductVisibilityDiagnosticTest extends TestCase
{
    public function testProductFortyFilterShapeMatchesBasePriceContext(): void
    {
        $months = new \Opencart\System\Library\Extension\MtUniCredit\MonthResolver();
        $matcher = new \Opencart\System\Library\Extension\MtUniCredit\SchemaFilterMatcher($months, '2026-08-28');
        $product = new ProductContext(40, [20, 24], 999.996);

        $filters = [
            [
                'id' => 10,
                'uni_promo' => 0,
                'category_id' => '20',
                'product_id' => null,
                'uni_date_from' => '2026-08-01',
                'uni_date_to' => '2026-12-31',
            ],
            [
                'id' => 12,
                'uni_promo' => 1,
                'category_id' => null,
                'product_id' => '40',
                'uni_date_from' => '2026-08-01',
            ],
        ];

        self::assertTrue($matcher->matches($filters[0], $product));
        self::assertTrue($matcher->matches($filters[1], $product));

        $gate = new CurrencyGate();
        $shop = ['uni_eur' => 3];
        self::assertTrue($gate->supports($shop, 'EUR'));
        self::assertFalse($gate->supports($shop, 'BGN'));
    }

    public function testOc4103StandardThemeButtonCartPlacementInjectsFragment(): void
    {
        $html = <<<'HTML'
              <div class="mb-3">
                <div class="input-group">
                  <div class="input-group-text">Qty</div>
                  <input type="text" name="quantity" value="1" size="2" id="input-quantity" class="form-control"/>
                  <button type="submit" id="button-cart" class="btn btn-primary btn-lg btn-block">Add to Cart</button>
                </div>
                <input type="hidden" name="product_id" value="40" id="input-product-id"/>
                <div id="error-quantity" class="form-text"></div>
              </div>
HTML;
        $fragment = '<div id="mt-uni-credit-product-root" class="mt-uni-credit-product"></div>';
        $placed = (new StandardThemeProductPlacement())->insertAfterAddToCartBlock($html, $fragment);
        self::assertStringContainsString('id="button-cart"', $placed);
        self::assertStringContainsString('mt-uni-credit-product-root', $placed);
        self::assertTrue(
            strpos($placed, 'id="button-cart"') < strpos($placed, 'mt-uni-credit-product-root')
        );
    }

    public function testDebugLogIsQuietWhenDisabled(): void
    {
        $writes = [];
        $log = new class ($writes) {
            /** @var list<string> */
            public array $writes;

            public function __construct(array &$writes)
            {
                $this->writes = &$writes;
            }

            public function write(string $message): void
            {
                $this->writes[] = $message;
            }
        };

        ProductVisibilityDebugLog::write($log, false, 'Product calculator hidden: no eligible schemes');
        self::assertSame([], $writes);

        ProductVisibilityDebugLog::write($log, true, 'Product calculator hidden: no eligible schemes');
        self::assertCount(1, $writes);
        self::assertStringContainsString('no eligible schemes', $writes[0]);
        self::assertStringNotContainsString('secret', strtolower($writes[0]));
    }

    public function testDisplayFactorySourceAllowsMissingRequiredOptions(): void
    {
        $model = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_product.php');
        $view = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_product_view.php');
        $ajax = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_product.php');

        self::assertStringContainsString('createDisplayProductContextFactory', $model);
        self::assertStringContainsString('bool $requireSelectedOptions = true', $model);
        self::assertStringContainsString('createDisplayProductContextFactory()', $view);
        self::assertStringContainsString('createDisplayProductContextFactory()', $ajax);
        self::assertStringContainsString('createProductContextFactory()', $ajax);
        self::assertStringContainsString('ProductVisibilityDebugLog', $view);
    }

    public function testAvailabilityRejectsUnsupportedCurrencyExplicitly(): void
    {
        $shop = ['uni_status' => 1, 'uni_eur' => 3, 'uni_minstojnost' => 1, 'uni_maxstojnost' => 99999, 'uni_typekop' => 0];
        $gate = new CurrencyGate();
        self::assertFalse($gate->supports($shop, 'USD'));
        self::assertSame('EUR', $gate->expectedIso($shop));
    }
}
