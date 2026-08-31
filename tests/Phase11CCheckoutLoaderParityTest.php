<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\ModuleAssetVersion;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use PHPUnit\Framework\TestCase;

/**
 * Phase 11C Remediation 02 — Checkout Process 2 loader overlay parity.
 */
final class Phase11CCheckoutLoaderParityTest extends TestCase
{
    public function testCheckoutTwigUsesSharedProcessingMarkup(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/payment/mt_uni_credit.twig');
        $cartModal = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_cart_modal.twig');

        self::assertStringContainsString('data-mtuc-processing', $twig);
        self::assertStringContainsString('mt-uni-credit-product-calculator__processing-panel', $twig);
        self::assertStringContainsString('mt-uni-credit-product-calculator__processing-spinner', $twig);
        self::assertStringContainsString('role="status"', $twig);
        self::assertStringContainsString('aria-live="polite"', $twig);
        self::assertStringContainsString('aria-busy="false"', $twig);

        // Shared contract with cart modal (working reference within OC4).
        self::assertStringContainsString('mt-uni-credit-product-calculator__processing-panel', $cartModal);
        self::assertStringContainsString('mt-uni-credit-product-calculator__processing-spinner', $cartModal);
    }

    public function testCheckoutCssDefinesViewportOverlayAndSpinner(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/stylesheet/mt_uni_credit_checkout.css');

        self::assertStringContainsString('#mt-uni-credit-checkout-root .mt-uni-credit-product-calculator__processing', $css);
        self::assertStringContainsString('position: fixed', $css);
        self::assertStringContainsString('z-index: 100050', $css);
        self::assertStringContainsString('rgba(0, 0, 0, .4)', $css);
        self::assertStringContainsString('mt-uni-credit-product-calculator__processing-spinner', $css);
        self::assertStringContainsString('mt-uni-credit-checkout--processing', $css);
        self::assertStringContainsString('mt-uni-credit-checkout-processing-active', $css);
        self::assertStringNotContainsString('fonts.googleapis.com', $css);
    }

    public function testCheckoutJsSetProcessingTogglesOverlayState(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js');

        self::assertStringContainsString('function setProcessing(active)', $js);
        self::assertStringContainsString('mt-uni-credit-checkout--processing', $js);
        self::assertStringContainsString('mt-uni-credit-checkout-processing-active', $js);
        self::assertStringContainsString('panel.setAttribute("aria-busy"', $js);
        self::assertStringContainsString('root.setAttribute("aria-busy"', $js);
        self::assertStringContainsString('setProcessing(true)', $js);
        self::assertStringContainsString('setProcessing(false)', $js);
        self::assertMatchesRegularExpression(
            '/finally\s*\{[\s\S]*?if\s*\(\s*!redirectTerminal\s*\)[\s\S]*?setProcessing\(false\)/',
            $js
        );
    }

    public function testTerminalSuccessKeepsLoaderUntilNavigation(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js');

        self::assertStringContainsString('redirectTerminal = true', $js);
        self::assertStringContainsString('window.location.assign(json.redirect)', $js);
        self::assertStringContainsString('window.location.assign(json.redirect_url || json.redirect)', $js);
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*!redirectTerminal\s*\)\s*\{[\s\S]*?setProcessing\(false\)/',
            $js
        );
    }

    public function testCheckoutPaymentEnqueuesSharedStylesheetsWithFilemtime(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php');

        self::assertStringContainsString('ModuleAssetVersion::href', $controller);
        self::assertStringContainsString('mt_uni_credit_checkout.css', $controller);
        self::assertStringContainsString('mt_uni_credit_product.css', $controller);
        self::assertStringNotContainsString("?ver=' . ModuleConstants::VERSION", $controller);

        $checkoutCssVer = ModuleAssetVersion::forRelativePath('catalog/view/stylesheet/mt_uni_credit_checkout.css');
        self::assertNotSame(ModuleConstants::VERSION, $checkoutCssVer);
    }

    public function testProcess1RedirectLoaderRegressionUnchanged(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js');
        self::assertStringContainsString('MtUniCreditRedirect', $js);
        self::assertStringContainsString('navigateIfTrusted', $js);
    }

    public function testProductCartProcessingMarkupUnchanged(): void
    {
        $productModal = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_product_modal.twig');
        $productJs = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');

        self::assertStringContainsString('mt-uni-credit-product-calculator__processing-spinner', $productModal);
        self::assertStringContainsString('function setProcessing(active)', $productJs);
    }

    public function testNoGenericGlobalLoaderSelectorsInCheckoutCss(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/stylesheet/mt_uni_credit_checkout.css');
        self::assertStringNotContainsString('.overlay {', $css);
        self::assertStringNotContainsString('.loader {', $css);
        self::assertStringContainsString('#mt-uni-credit-checkout-root', $css);
    }

    public function testModuleVersionRemainsFrozen(): void
    {
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }
}
