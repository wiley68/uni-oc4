<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\ModuleAssetVersion;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use PHPUnit\Framework\TestCase;

/**
 * Phase 8 Cart asset loading — registration, filemtime URLs, CSS/JS root parity.
 *
 * Runtime defect: shared Product CSS was scoped only to #mt-uni-credit-product-* while
 * Cart Twig/JS use #mt-uni-credit-cart-* — assets registered but UI appeared unstyled.
 */
final class Phase8CartAssetRuntimeContractTest extends TestCase
{
    public function testCartBeforeControllerRegistersFontsProductCssCartCssAndFooterJs(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_cart_controller.php'
        );

        self::assertStringContainsString('ModuleAssetVersion::href', $controller);
        self::assertStringContainsString('mt_uni_credit_fonts.css', $controller);
        self::assertStringContainsString('mt_uni_credit_product.css', $controller);
        self::assertStringContainsString('mt_uni_credit_cart.css', $controller);
        self::assertStringContainsString('mt_uni_credit_cart.js', $controller);
        self::assertStringContainsString("'footer'", $controller);
        self::assertStringContainsString("\$route !== 'checkout/cart'", $controller);
    }

    public function testCartAssetHrefsUseExtensionPathAndFilemtime(): void
    {
        $assets = [
            'catalog/view/stylesheet/mt_uni_credit_fonts.css',
            'catalog/view/stylesheet/mt_uni_credit_product.css',
            'catalog/view/stylesheet/mt_uni_credit_cart.css',
            'catalog/view/javascript/mt_uni_credit_cart.js',
        ];
        foreach ($assets as $relative) {
            self::assertFileExists(ModuleAssetVersion::absolutePath($relative), $relative);
            $ver = ModuleAssetVersion::forRelativePath($relative);
            $href = ModuleAssetVersion::href($relative);
            self::assertStringStartsWith('extension/' . ModuleConstants::EXTENSION_CODE . '/', $href);
            self::assertStringContainsString('?ver=' . $ver, $href);
            self::assertNotSame(ModuleConstants::VERSION, $ver);
            self::assertDoesNotMatchRegularExpression('/\?ver=' . preg_quote(ModuleConstants::VERSION, '/') . '$/', $href);
        }
    }

    public function testCartJsRootSelectorsMatchTwig(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_cart.js');
        $calc = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_cart_calculator.twig'
        );
        $modal = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_cart_modal.twig'
        );

        self::assertMatchesRegularExpression('/ROOT_ID\s*=\s*[\'"]mt-uni-credit-cart-root[\'"]/', $js);
        self::assertMatchesRegularExpression('/MODAL_ID\s*=\s*[\'"]mt-uni-credit-cart-modal[\'"]/', $js);
        self::assertMatchesRegularExpression('/BOOTSTRAP_ID\s*=\s*[\'"]mt-uni-credit-cart-bootstrap[\'"]/', $js);
        self::assertStringContainsString('id="mt-uni-credit-cart-root"', $calc);
        self::assertStringContainsString('id="mt-uni-credit-cart-bootstrap"', $calc);
        self::assertStringContainsString('id="mt-uni-credit-cart-modal"', $modal);
        self::assertStringContainsString('DOMContentLoaded', $js);
        self::assertStringNotContainsString('console.log(', $js);
    }

    public function testSharedVisualCssDualScopesProductAndCartRoots(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/stylesheet/mt_uni_credit_product.css'
        );

        self::assertStringContainsString(
            ':is(#mt-uni-credit-product-root, #mt-uni-credit-cart-root)',
            $css
        );
        self::assertStringContainsString(
            ':is(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal)',
            $css
        );
        // Still deliberately scoped — not body/theme global.
        self::assertDoesNotMatchRegularExpression('/^\s*body\s*\{/m', $css);
        self::assertStringNotContainsString('fonts.googleapis.com', $css);
    }

    public function testCartCssIsLayoutOnlyAndProductPipelineUnchanged(): void
    {
        $cartCss = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/stylesheet/mt_uni_credit_cart.css'
        );
        $productController = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_product_controller.php'
        );

        self::assertStringContainsString('.mt-uni-credit-cart-calculator', $cartCss);
        self::assertStringContainsString('mt_uni_credit_product.js', $productController);
        self::assertStringNotContainsString('mt_uni_credit_cart.js', $productController);
        self::assertStringContainsString("'footer'", $productController);
    }

    public function testCartPageIsSelfSufficientWithoutVisitingProduct(): void
    {
        $cartController = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_cart_controller.php'
        );
        // Fonts + shared visual CSS + cart JS registered on cart before-controller alone.
        self::assertStringContainsString('mt_uni_credit_fonts.css', $cartController);
        self::assertStringContainsString('mt_uni_credit_product.css', $cartController);
        self::assertStringContainsString('mt_uni_credit_cart.js', $cartController);
    }
}
