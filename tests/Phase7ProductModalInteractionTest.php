<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Frontend contract: Twig selectors must match JS; script must load after fragment.
 */
final class Phase7ProductModalInteractionTest extends TestCase
{
    public function testTwigTriggersAndModalMatchJsSelectors(): void
    {
        $calc = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_product_calculator.twig');
        $modal = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_product_modal.twig');
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');

        self::assertStringContainsString('id="mt-uni-credit-product-root"', $calc);
        self::assertStringContainsString('id="mt-uni-credit-bootstrap"', $calc);
        self::assertStringContainsString('class="mt-uni-credit-product-calculator__button', $calc);
        self::assertStringNotContainsString('mt-uni-credit-open-modal', $calc);
        self::assertStringContainsString('type="button"', $calc);
        self::assertStringContainsString('data-offer-type=', $calc);
        self::assertStringContainsString('data-preferred-key=', $calc);

        self::assertStringContainsString('id="mt-uni-credit-product-modal"', $modal);
        self::assertStringContainsString('role="dialog"', $modal);
        self::assertStringContainsString('aria-modal="true"', $modal);
        self::assertStringContainsString('hidden>', $modal);
        self::assertSame(1, substr_count($modal, 'id="mt-uni-credit-product-modal"'));

        self::assertMatchesRegularExpression('/ROOT_ID\s*=\s*[\'"]mt-uni-credit-product-root[\'"]/', $js);
        self::assertMatchesRegularExpression('/MODAL_ID\s*=\s*[\'"]mt-uni-credit-product-modal[\'"]/', $js);
        self::assertMatchesRegularExpression('/BOOTSTRAP_ID\s*=\s*[\'"]mt-uni-credit-bootstrap[\'"]/', $js);
        self::assertStringContainsString('.mt-uni-credit-product-calculator__button[data-offer-type]', $js);
    }

    public function testJsUsesFooterSafeInitAndDelegatedClicks(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
        $controller = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_product_controller.php'
        );

        self::assertStringContainsString("addScript(", $controller);
        self::assertStringContainsString("'footer'", $controller);
        self::assertTrue(
            str_contains($js, 'DOMContentLoaded') || str_contains($js, 'document.readyState === "loading"'),
            'Product JS must defer init until DOM is ready'
        );
        self::assertMatchesRegularExpression('/root\.addEventListener\([\'"]click[\'"]/', $js);
        self::assertStringContainsString('event.target.closest(TRIGGER_SELECTOR)', $js);
        self::assertStringContainsString('scheduleRefreshCalculator', $js);
        self::assertStringContainsString('bindProductRecalculationListeners', $js);
        self::assertStringContainsString('[id^="input-option"]', $js);
        self::assertStringContainsString('document.body.appendChild(modal)', $js);
        self::assertMatchesRegularExpression('/modal\.removeAttribute\([\'"]inert[\'"]\)/', $js);
        self::assertMatchesRegularExpression('/event\.key\s*===\s*[\'"]Escape[\'"]/', $js);
        self::assertStringContainsString('lastTrigger.focus()', $js);
    }

    public function testNoBrowserConsoleDiagnostics(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
        $calc = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_product_calculator.twig');

        self::assertStringNotContainsString('console.log(', $js);
        self::assertStringNotContainsString('console.info(', $js);
        self::assertStringNotContainsString('console.debug(', $js);
        self::assertStringNotContainsString('console.warn(', $js);
        self::assertStringNotContainsString('console.error(', $js);
        self::assertStringNotContainsString('debugLog', $js);
        self::assertStringNotContainsString('data-mtuc-debug', $calc);
        self::assertStringNotContainsString('mtUniCreditDebug', $js);
    }

    public function testDomFixtureOpenCloseContract(): void
    {
        $fixture = <<<'HTML'
<div id="content">
  <button type="submit" id="button-cart">Add</button>
  <div id="mt-uni-credit-product-root" class="mt-uni-credit-product" data-product-id="40">
    <div class="mt-uni-credit-calculator">
      <div class="mt-uni-credit-offers">
        <button type="button" class="mt-uni-credit-product-calculator__button mt-uni-credit-product-calculator__button--standard" data-offer-type="standard" data-preferred-key="standard|KOP|12|1">
          <span class="mt-uni-credit-product-calculator__button-content">
            <span class="mt-uni-credit-product-calculator__button-title">Buy</span>
            <span class="mt-uni-credit-product-calculator__button-price">12 x 10</span>
          </span>
        </button>
        <button type="button" class="mt-uni-credit-product-calculator__button mt-uni-credit-product-calculator__button--promo" data-offer-type="promo" data-preferred-key="promo|KOP0|6|2">
          <span class="mt-uni-credit-product-calculator__button-content">
            <span class="mt-uni-credit-product-calculator__button-title">Buy</span>
            <span class="mt-uni-credit-product-calculator__button-price">6 x 0</span>
          </span>
          <span class="mt-uni-credit-product-calculator__badge">0%</span>
        </button>
    </div>
    <div id="mt-uni-credit-product-modal" class="mt-uni-credit-modal" role="dialog" aria-modal="true" aria-hidden="true" hidden>
      <div class="mt-uni-credit-modal__overlay" data-mtuc-dismiss></div>
      <div class="mt-uni-credit-modal__panel" tabindex="-1">
        <button type="button" data-mtuc-dismiss>x</button>
        <div data-mtuc-summary></div>
        <form id="mt-uni-credit-product-form"></form>
      </div>
    </div>
  </div>
  <script type="application/json" id="mt-uni-credit-bootstrap">{"product_id":40,"calculator":{"offers":{"standard":{"preferred_scheme_key":"standard|KOP|12|1","schemes":[{"key":"standard|KOP|12|1","scheme_type":"standard","kop_code":"KOP","months":12,"filter_id":1,"monthly_installment":10,"first_installment":0}],"installment_label":"12 x 10"},"promo":{"preferred_scheme_key":"promo|KOP0|6|2","schemes":[{"key":"promo|KOP0|6|2","scheme_type":"promo","kop_code":"KOP0","months":6,"filter_id":2,"monthly_installment":0,"first_installment":0}],"installment_label":"6 x 0"}},"show_installment":true},"calculate_url":"/c","issue_url":"/i","submit_url":"/s","csrf_token":"t"}</script>
</div>
HTML;

        self::assertSame(1, substr_count($fixture, 'id="mt-uni-credit-product-modal"'));
        self::assertSame(1, substr_count($fixture, 'id="mt-uni-credit-product-root"'));
        self::assertMatchesRegularExpression('/<button type="button"\s+class="mt-uni-credit-product-calculator__button/', $fixture);
        self::assertDoesNotMatchRegularExpression('/class="mt-uni-credit-product-calculator__button"[^>]*type="submit"/', $fixture);
        self::assertStringContainsString('aria-hidden="true"', $fixture);
        self::assertStringContainsString(' hidden>', $fixture);

        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
        foreach (['mt-uni-credit-product-root', 'mt-uni-credit-product-modal', 'mt-uni-credit-bootstrap', 'mt-uni-credit-product-calculator__button'] as $token) {
            self::assertStringContainsString($token, $js);
            self::assertStringContainsString($token, $fixture);
        }
        self::assertStringContainsString('dataset.offerType', $js);
        self::assertStringContainsString('dataset.preferredKey', $js);
        self::assertStringContainsString('data-offer-type', $fixture);
        self::assertStringContainsString('data-preferred-key', $fixture);
    }

    public function testIssueSubmissionIsTiedToModalOpenNotCalculate(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
        self::assertStringContainsString('recalculateSelection()', $js);
        self::assertStringContainsString('state.issue_url', $this->extractFunctionBody($js, 'recalculateSelection'));
        self::assertStringContainsString('state.calculate_url', $this->extractFunctionBody($js, 'refreshCalculator'));
        self::assertStringNotContainsString('state.issue_url', $this->extractFunctionBody($js, 'renderCalculator'));
        self::assertStringContainsString('recalculateSelection();', $this->extractFunctionBody($js, 'openModal'));
    }

    private function extractFunctionBody(string $js, string $name): string
    {
        $pattern = '/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{/';
        if (!preg_match($pattern, $js, $match, PREG_OFFSET_CAPTURE)) {
            self::fail('Function not found: ' . $name);
        }
        $start = (int) $match[0][1] + strlen($match[0][0]) - 1;
        $depth = 0;
        $length = strlen($js);
        for ($i = $start; $i < $length; $i++) {
            $char = $js[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($js, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }
}
