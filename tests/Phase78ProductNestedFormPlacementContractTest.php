<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\StandardThemeProductPlacement;
use PHPUnit\Framework\TestCase;

/**
 * Regression: nested financing form inside #form-product is dropped by HTML5 parsers
 * → activeFormPresent=false on submit despite valid calculation/token/scheme.
 */
final class Phase78ProductNestedFormPlacementContractTest extends TestCase
{
    public function testPlacementInsertsFragmentAfterFormProductClose(): void
    {
        $html = <<<'HTML'
<div id="product">
<form id="form-product" method="post" data-oc-toggle="ajax">
  <div class="mb-3">
    <div class="input-group">
      <button type="submit" id="button-cart" class="btn btn-primary">Add to Cart</button>
    </div>
    <input type="hidden" name="product_id" value="40" id="input-product-id"/>
  </div>
</form>
</div>
HTML;
        $fragment = <<<'HTML'
<div id="mt-uni-credit-product-root">
  <div id="mt-uni-credit-product-modal">
    <div data-mtuc-step="2">
      <form id="mt-uni-credit-product-form" novalidate>
        <button type="submit" data-mtuc-submit>Send</button>
      </form>
    </div>
  </div>
</div>
HTML;
        $placed = (new StandardThemeProductPlacement())->insertAfterAddToCartBlock($html, $fragment);

        $formProductPos = strpos($placed, 'id="form-product"');
        $formClosePos = strpos($placed, '</form>', (int) $formProductPos);
        $financingFormPos = strpos($placed, 'id="mt-uni-credit-product-form"');
        $rootPos = strpos($placed, 'id="mt-uni-credit-product-root"');

        self::assertNotFalse($formProductPos);
        self::assertNotFalse($formClosePos);
        self::assertNotFalse($financingFormPos);
        self::assertNotFalse($rootPos);
        self::assertGreaterThan($formClosePos, $rootPos, 'root must follow </form> of #form-product');
        self::assertGreaterThan($formClosePos, $financingFormPos, 'financing form must not be nested in #form-product source');
    }

    public function testOc4103ProductTwigShapePlacesOutsideForm(): void
    {
        $html = <<<'HTML'
              <div id="product">
              <form id="form-product" method="post" data-oc-toggle="ajax" data-oc-load="" data-oc-target="">
              <div class="mb-3">
                <div class="input-group">
                  <div class="input-group-text">Qty</div>
                  <input type="text" name="quantity" value="1" size="2" id="input-quantity" class="form-control"/>
                  <button type="submit" id="button-cart" class="btn btn-primary btn-lg btn-block">Add to Cart</button>
                </div>
                <input type="hidden" name="product_id" value="40" id="input-product-id"/>
                <div id="error-quantity" class="form-text"></div>
              </div>
            </form>
          </div>
HTML;
        $fragment = '<div id="mt-uni-credit-product-root" class="mt-uni-credit-product"><form id="mt-uni-credit-product-form"></form></div>';
        $placed = (new StandardThemeProductPlacement())->insertAfterAddToCartBlock($html, $fragment);
        $close = strpos($placed, '</form>');
        $root = strpos($placed, 'mt-uni-credit-product-root');
        self::assertNotFalse($close);
        self::assertNotFalse($root);
        self::assertGreaterThan($close, $root);
    }

    public function testProductJsUsesLiveFinancingFormResolver(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
        self::assertStringContainsString('function activeProductFinancingForm(', $js);
        self::assertStringContainsString("modal.querySelector('#mt-uni-credit-product-form')", $js);
        self::assertStringContainsString("document.getElementById('mt-uni-credit-product-form')", $js);
        self::assertStringContainsString('const activeForm = activeProductFinancingForm()', $js);
        self::assertStringContainsString('activeFormPresent', $js);
        self::assertStringContainsString('submit_missing_form', $js);
        self::assertStringContainsString('Заявката не може да бъде обработена. Моля, опитайте отново.', $js);
        // Must not rely on a single init-time form capture for submit.
        self::assertDoesNotMatchRegularExpression(
            '/const form = document\.getElementById\(\'mt-uni-credit-product-form\'\);/',
            $js
        );
    }

    public function testSubmitDoesNotReuseSchemeMessageForMissingForm(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
        self::assertMatchesRegularExpression(
            '/!buttonPresent\s*\|\|\s*!activeFormPresent[\s\S]*?Заявката не може да бъде обработена\. Моля, опитайте отново\./',
            $js
        );
        self::assertMatchesRegularExpression(
            '/!schemePresent\s*\|\|\s*!finalHasContext[\s\S]*?изчакайте изчислението преди изпращане/',
            $js
        );
    }

    public function testProductModalKeepsFormInsideModalFragment(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_product_modal.twig');
        $modalPos = strpos($twig, 'id="mt-uni-credit-product-modal"');
        $formPos = strpos($twig, 'id="mt-uni-credit-product-form"');
        $step2Pos = strpos($twig, 'data-mtuc-step="2"');
        $submitPos = strpos($twig, 'data-mtuc-submit');
        self::assertNotFalse($modalPos);
        self::assertNotFalse($formPos);
        self::assertNotFalse($step2Pos);
        self::assertNotFalse($submitPos);
        self::assertGreaterThan($modalPos, $step2Pos);
        self::assertGreaterThan($step2Pos, $formPos);
        self::assertGreaterThan($formPos, $submitPos);
    }
}
