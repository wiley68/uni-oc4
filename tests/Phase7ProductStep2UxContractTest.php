<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 7 Step 2 UX parity — fields, consent, Изпрати readiness (Woo/PS authority).
 */
final class Phase7ProductStep2UxContractTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/catalog/view/stylesheet/mt_uni_credit_product.css');
    }

    private function js(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
    }

    private function modal(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_product_modal.twig');
    }

    public function testStep2CustomerInputsUseBottomBorderUniCreditContract(): void
    {
        $css = $this->css();
        $modal = $this->modal();

        self::assertStringContainsString('mt-uni-credit-product-calculator__customer-input', $modal);
        self::assertStringContainsString('name="firstname"', $modal);
        self::assertStringContainsString('name="lastname"', $modal);
        self::assertStringContainsString('name="address"', $modal);
        self::assertStringContainsString('name="phone"', $modal);
        self::assertStringContainsString('name="email"', $modal);
        self::assertStringNotContainsString('name="egn"', $modal);

        self::assertSame(1, preg_match(
            '/:is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\) \.mt-uni-credit-product-calculator__customer-input \{([^}]+)\}/s',
            $css,
            $m
        ));
        $body = $m[1];
        self::assertStringContainsString('border: 0', $body);
        self::assertStringContainsString('border-bottom: 1px solid #b0b0b0', $body);
        self::assertStringContainsString('border-radius: 0', $body);
        self::assertStringContainsString('font-size: 20px', $body);
        self::assertStringContainsString('color: #000', $body);
        self::assertStringContainsString('padding: 0 0 0 16px', $body);
        self::assertStringNotContainsString('border: 1px solid', $body);
        self::assertStringNotContainsString('border-radius: 4px', $body);

        // No global form-control leakage.
        self::assertStringNotContainsString('.form-control {', $css);
        self::assertStringNotContainsString('.form-control:focus', $css);
    }

    public function testStep2LabelTypographyMatchesReference(): void
    {
        $css = $this->css();
        $modal = $this->modal();

        self::assertStringContainsString('mt-uni-credit-product-calculator__customer-label', $modal);
        self::assertStringContainsString('mt-uni-credit-product-calculator__required', $modal);

        self::assertSame(1, preg_match(
            '/:is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\) \.mt-uni-credit-product-calculator__customer-label \{([^}]+)\}/s',
            $css,
            $m
        ));
        $body = $m[1];
        self::assertStringContainsString('font-size: 16px', $body);
        self::assertStringContainsString('font-weight: 400', $body);
        self::assertStringContainsString('line-height: 1.2', $body);
        self::assertStringContainsString('color: #000', $body);
        self::assertStringContainsString('margin: 0 0 4px', $body);
        self::assertStringContainsString('var(--mtuc-font-family)', $body);
    }

    public function testStep2FocusAndInvalidKeepBottomBorderLanguage(): void
    {
        $css = $this->css();

        self::assertSame(1, preg_match(
            '/:is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\) \.mt-uni-credit-product-calculator__customer-input:focus,\s*'
            . ':is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\) \.mt-uni-credit-product-calculator__customer-input:focus-visible \{([^}]+)\}/s',
            $css,
            $m
        ));
        $focus = $m[1];
        self::assertStringContainsString('outline: none', $focus);
        self::assertStringContainsString('box-shadow: none', $focus);
        self::assertStringNotContainsString('outline: 2px solid', $focus);
        self::assertStringNotContainsString('0 0 0 .25rem', $focus);

        self::assertStringContainsString(
            '.mt-uni-credit-product-calculator__customer-input[aria-invalid="true"]',
            $css
        );
        self::assertStringContainsString('border-bottom-color: var(--mtuc-popup-red)', $css);
    }

    public function testConsentCheckboxAndLinkMatchWooPs(): void
    {
        $css = $this->css();
        $modal = $this->modal();

        self::assertStringContainsString('mt-uni-credit-product-calculator__consent-checkbox', $modal);
        self::assertStringContainsString('data-mtuc-consent-checkbox', $modal);
        self::assertStringContainsString('mt-uni-credit-product-calculator__consent-label', $modal);

        self::assertSame(1, preg_match(
            '/:is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\) \.mt-uni-credit-product-calculator__consent-checkbox \{([^}]+)\}/s',
            $css,
            $m
        ));
        $box = $m[1];
        self::assertStringContainsString('width: 18px', $box);
        self::assertStringContainsString('height: 18px', $box);
        self::assertStringContainsString('accent-color: var(--mtuc-popup-red', $box);

        self::assertSame(1, preg_match(
            '/:is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\) \.mt-uni-credit-product-calculator__consent-label,\s*'
            . ':is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\) \.mt-uni-credit-product-calculator__consent-text \{([^}]+)\}/s',
            $css,
            $m2
        ));
        $label = $m2[1];
        self::assertStringContainsString('font-size: 14px', $label);
        self::assertStringContainsString('line-height: 1.4', $label);
        self::assertStringContainsString('color: #000', $label);

        self::assertStringContainsString('text-decoration: underline', $css);
        self::assertStringContainsString(
            '.mt-uni-credit-product-calculator__consent-label a',
            $css
        );
    }

    public function testDisabledSubmitLabelRemainsReadable(): void
    {
        $css = $this->css();
        $modal = $this->modal();
        $lang = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/language/bg-bg/module/mt_uni_credit_product.php'
        );

        self::assertStringContainsString('{{ text_send }}', $modal);
        self::assertStringContainsString('text_send', $lang);
        self::assertStringContainsString('Изпрати', $lang);
        self::assertMatchesRegularExpression(
            '/data-mtuc-submit[^>]*>[\s\S]*?<b>\s*\{\{\s*text_send\s*\}\}\s*<\/b>/',
            $modal
        );

        self::assertSame(1, preg_match(
            '/:is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\) button\.mt-uni-credit-product-calculator__popup-button--primary:disabled b,[\s\S]*?'
            . '\{([^}]+)\}/s',
            $css,
            $m
        ));
        $disabledLabel = $m[1];
        self::assertStringContainsString('background: var(--mtuc-btn-disabled-bg)', $disabledLabel);
        self::assertStringContainsString('color: var(--mtuc-btn-disabled-text)', $disabledLabel);
        self::assertStringContainsString('opacity: 1', $disabledLabel);
        self::assertStringContainsString('visibility: visible', $disabledLabel);
        self::assertStringNotContainsString('color: transparent', $disabledLabel);
        self::assertStringNotContainsString('opacity: 0', $disabledLabel);

        // Disabled text must not reuse the enabled face grey (#a1a1aa on #a1a1aa).
        self::assertStringContainsString('--mtuc-btn-disabled-text: #52525b', $css);
        self::assertStringContainsString('--mtuc-btn-disabled-bg: #d4d4d8', $css);
    }

    public function testSubmitReadinessAlgorithmInJs(): void
    {
        $js = $this->js();
        $modal = $this->modal();

        self::assertStringContainsString('updateSubmitState', $js);
        self::assertStringContainsString('isStep2FormValid', $js);
        self::assertStringContainsString('getStep2FieldErrors', $js);
        self::assertStringContainsString('areMandatoryConsentsChecked', $js);
        self::assertStringContainsString('hasAuthoritativeCalculation', $js);
        self::assertStringContainsString('bindStep2ReadinessListeners', $js);
        self::assertStringContainsString('PHONE_VALID_PATTERN', $js);
        self::assertStringContainsString('EMAIL_VALID_PATTERN', $js);
        self::assertStringContainsString('sanitizePhoneValue', $js);

        // Gate before POST — UX disabled is not the security boundary.
        self::assertStringContainsString('if (!updateSubmitState(true))', $js);
        self::assertStringContainsString('!lastCalculation', $js);

        // Transition to Step 2 requires calculation then evaluates readiness.
        self::assertMatchesRegularExpression(
            '/data-mtuc-apply[\s\S]*?!apply\.disabled && lastCalculation[\s\S]*?setStep\(2\);[\s\S]*?updateSubmitState\(false\)/',
            $js
        );

        // Must not blindly enable submit when lastCalculation exists.
        self::assertDoesNotMatchRegularExpression(
            '/setStep\(2\);[\s\S]{0,120}submit\.disabled\s*=\s*false/',
            $js
        );

        // Consent absence does not block (empty NodeList → true).
        self::assertStringContainsString('if (!boxes.length)', $js);

        // Process-aware: EGN/phone2 only when rendered.
        self::assertStringContainsString("customerField('egn')", $js);
        self::assertStringContainsString("customerField('phone2')", $js);
        self::assertStringContainsString('if (egnField)', $js);
        self::assertStringContainsString('if (phone2Field)', $js);

        // Process 1 modal does not render Process 2 fields.
        self::assertStringNotContainsString('name="egn"', $modal);
        self::assertStringNotContainsString('name="phone2"', $modal);

        // Initial markup: submit disabled.
        self::assertMatchesRegularExpression(
            '/data-mtuc-submit[^>]*\sdisabled/',
            $modal
        );
        self::assertStringContainsString('aria-disabled="true"', $modal);
        self::assertStringContainsString('is-disabled', $modal);

        // Live listeners on input/change including consent (DOM-wide Step 2 scope).
        self::assertStringContainsString('[data-mtuc-consent-checkbox]', $js);
        self::assertStringContainsString('updateSubmitState(false)', $js);

        // Clearing / invalid state toggles disabled again via updateSubmitState.
        self::assertStringContainsString('submit.disabled = !valid', $js);
        self::assertStringContainsString("classList.toggle('is-disabled', !valid)", $js);

        // Phone/email patterns match Woo/PS (BG numbers accepted).
        self::assertStringContainsString('/^[-0-9+() ]+$/', $js);
        self::assertStringContainsString('/^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/', $js);
    }

    public function testServerProcess1DoesNotRequireEgnOrPhone2(): void
    {
        $validator = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/product_customer_validator.php'
        );
        $customer = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/financing_customer_data.php'
        );

        self::assertStringNotContainsString('egn', strtolower($validator));
        self::assertStringNotContainsString('phone2', strtolower($validator));
        self::assertStringContainsString('EGN is intentionally excluded', $customer);
    }

    public function testStep1ApprovedContractsUntouchedByStep2CssScope(): void
    {
        $css = $this->css();

        // Step 1 frame + calc controls remain.
        self::assertStringContainsString('border-radius: 14.5px 14.5px 80px 14.5px', $css);
        self::assertStringContainsString(
            '.mt-uni-credit-product-calculator__popup-select:focus',
            $css
        );
        // Customer input rules stay under modal id — no bare form-control.
        self::assertStringContainsString(
            ':is(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal) .mt-uni-credit-product-calculator__customer-input',
            $css
        );
    }

    public function testNoConsoleDiagnostics(): void
    {
        $js = $this->js();
        self::assertStringNotContainsString('console.log', $js);
        self::assertStringNotContainsString('console.debug', $js);
        self::assertStringNotContainsString('console.warn', $js);
        self::assertStringNotContainsString('console.error', $js);
        self::assertStringNotContainsString('alert(', $js);
    }
}
