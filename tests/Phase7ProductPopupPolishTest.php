<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7 polish: required-option UX, first-installment scheme reset, focus CSS.
 */
final class Phase7ProductPopupPolishTest extends TestCase
{
    private function js(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
    }

    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/catalog/view/stylesheet/mt_uni_credit_product.css');
    }

    public function testRequiredOptionFrontendBlocksIssueBeforeModal(): void
    {
        $js = $this->js();
        $calc = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_product_calculator.twig');
        $lang = (string) file_get_contents(dirname(__DIR__) . '/catalog/language/bg-bg/module/mt_uni_credit_product.php');

        self::assertStringContainsString('requiredProductOptionsSatisfied', $js);
        self::assertStringContainsString('firstMissingRequiredOptionBlock', $js);
        self::assertStringContainsString('handleMissingRequiredOptions', $js);
        self::assertStringContainsString('mb-3.required', $js);
        self::assertStringContainsString("name.indexOf('option[') === 0", $js);
        self::assertStringContainsString("field.type === 'checkbox'", $js);
        self::assertStringContainsString('data-mtuc-entry-error', $calc);
        self::assertStringContainsString('Моля, изберете задължителните опции на продукта.', $lang);
        self::assertStringContainsString('requiredOptionsMessage()', $js);
        self::assertStringNotContainsString('[name^="option["]', $js);

        // Offer click path: gate before openModal — no issueSubmission when incomplete.
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*!requiredProductOptionsSatisfied\(\)\s*\)\s*\{[\s\S]*?handleMissingRequiredOptions\(\);[\s\S]*?return;[\s\S]*?\}[\s\S]*?openModal\(/',
            $js
        );
        self::assertStringContainsString('isMissingRequiredOptionError', $js);
        self::assertStringContainsString("error_code === 'missing_required_option'", $js);
    }

    public function testServerMissingRequiredOptionUsesFriendlyBulgarianMessage(): void
    {
        $model = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_product.php');
        $exception = (string) file_get_contents(dirname(__DIR__) . '/system/library/product_financing_flow_exception.php');
        $view = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_product_view.php');

        self::assertStringContainsString("'missing_required_option'", $model);
        self::assertStringContainsString('Моля, изберете задължителните опции на продукта.', $model);
        self::assertStringNotContainsString('Missing required product option.', $model);
        self::assertStringNotContainsString('Invalid product option.', $model);
        self::assertStringContainsString("'validation', 'missing_required_option', 'cart_empty'", $exception);
        self::assertStringContainsString("'missing_required_option'", $exception);
        self::assertStringContainsString('=> 422', $exception);
        self::assertStringContainsString('error_required_options', $view);
        self::assertStringContainsString("'i18n'", $view);

        $ex = new ProductFinancingFlowException(
            'missing_required_option',
            'Моля, изберете задължителните опции на продукта.'
        );
        self::assertSame(422, $ex->httpStatus());
        self::assertSame('missing_required_option', $ex->errorCode());
        self::assertStringNotContainsString('422', $ex->getMessage());
        self::assertStringNotContainsString('invalid_option', $ex->getMessage());
    }

    public function testCheckboxRequiredEmptyArrayIsRejectedByModelSource(): void
    {
        $model = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_product.php');

        // Product 40-class: checkbox option[N][] — empty selection must throw before attempt.
        self::assertStringContainsString("\$type === 'checkbox' && is_array(\$value)", $model);
        self::assertStringContainsString('$value === []', $model);
        self::assertStringContainsString('missing_required_option', $model);
    }

    public function testSchemeChangeResetsFirstInstallmentBeforeRecalculation(): void
    {
        $js = $this->js();

        self::assertStringContainsString('resetFirstInstallmentForSchemeChange', $js);
        self::assertMatchesRegularExpression(
            '/schemeSelect\(\)\?\.addEventListener\(\'change\'[\s\S]*?resetFirstInstallmentForSchemeChange\(\);[\s\S]*?recalculateSelection\(\)/',
            $js
        );
        self::assertStringContainsString("first.value = '0'", $js);
        self::assertStringContainsString("lastCalculation = null", $js);
        self::assertStringContainsString("submissionToken = ''", $js);
        self::assertStringContainsString("first.removeAttribute('readonly')", $js);
        self::assertStringContainsString("first.removeAttribute('disabled')", $js);

        // renderCalculation still applies authoritative server value + lock state (readonly only — not disabled).
        self::assertStringContainsString('calculation.first_installment_locked', $js);
        self::assertStringContainsString('setAttribute(\'readonly\'', $js);
        self::assertDoesNotMatchRegularExpression(
            '/first_installment_locked[\s\S]{0,240}setAttribute\(\'disabled\'/',
            $js
        );
    }

    public function testFocusCssRemovesGenericOutlineOnSchemeAndFirstInstallment(): void
    {
        $css = $this->css();

        // Isolate the Step 1 calc focus rule block (select + first installment only).
        self::assertSame(1, preg_match(
            '/:is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\) \.mt-uni-credit-product-calculator__popup-select:focus,\s*'
            . ':is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\) \.mt-uni-credit-product-calculator__popup-select:focus-visible,\s*'
            . ':is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\) \.mt-uni-credit-product-calculator__popup-input:focus,\s*'
            . ':is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\) \.mt-uni-credit-product-calculator__popup-input:focus-visible\s*\{([^}]+)\}/s',
            $css,
            $block
        ));
        $focusBody = $block[1];
        self::assertStringContainsString('outline: none', $focusBody);
        self::assertStringContainsString('box-shadow: none', $focusBody);
        self::assertStringNotContainsString('outline: 2px solid', $focusBody);
        self::assertStringNotContainsString('0 0 0 .25rem', $focusBody);

        // Approved normal styling remains.
        self::assertStringContainsString('border-bottom: 1px solid #b0b0b0', $css);
        self::assertStringContainsString('color: var(--mtuc-popup-red)', $css);

        // No global form-control focus override outside UniCredit modal scope.
        self::assertStringNotContainsString('.form-control:focus', $css);
        self::assertStringNotContainsString('.form-select:focus', $css);
    }

    public function testNoConsoleDiagnosticsIntroduced(): void
    {
        $js = $this->js();
        self::assertStringNotContainsString('console.log', $js);
        self::assertStringNotContainsString('console.debug', $js);
        self::assertStringNotContainsString('console.warn', $js);
        self::assertStringNotContainsString('console.error', $js);
        self::assertStringNotContainsString('debugLog', $js);
        self::assertStringNotContainsString('alert(', $js);
    }
}
