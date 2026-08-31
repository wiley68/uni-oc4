<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\ModuleAssetVersion;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use PHPUnit\Framework\TestCase;

/**
 * Permanent cross-phase contracts: no storefront console diagnostics; local static assets.
 */
final class CrossPhaseStorefrontContractTest extends TestCase
{
    /** @return list<string> */
    private function moduleJsFiles(): array
    {
        $root = dirname(__DIR__) . '/catalog/view/javascript';
        if (!is_dir($root)) {
            return [];
        }
        $files = glob($root . '/*.js') ?: [];
        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function moduleCssFiles(): array
    {
        $root = dirname(__DIR__) . '/catalog/view/stylesheet';
        $files = glob($root . '/*.css') ?: [];
        sort($files);

        return $files;
    }

    public function testProductionJsHasNoConsoleDiagnostics(): void
    {
        $files = $this->moduleJsFiles();
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $js = (string) file_get_contents($file);
            foreach (['console.log(', 'console.info(', 'console.debug(', 'console.warn(', 'console.error('] as $call) {
                self::assertStringNotContainsString(
                    $call,
                    $js,
                    basename($file) . ' must not call ' . $call
                );
            }
        }
    }

    public function testLocalRobotoCondensedFontPackageExists(): void
    {
        $fontDir = dirname(__DIR__) . '/catalog/view/fonts/roboto-condensed';
        self::assertDirectoryExists($fontDir);
        self::assertFileExists($fontDir . '/OFL.txt');
        self::assertFileExists($fontDir . '/LICENSE.txt');
        self::assertFileExists($fontDir . '/roboto-condensed-cyrillic-400.woff2');
        self::assertFileExists($fontDir . '/roboto-condensed-cyrillic-700.woff2');
        self::assertFileExists($fontDir . '/roboto-condensed-latin-400.woff2');
        self::assertFileExists($fontDir . '/roboto-condensed-latin-700.woff2');

        $ofl = (string) file_get_contents($fontDir . '/OFL.txt');
        self::assertStringContainsString('SIL Open Font License', $ofl);
        self::assertSame($ofl, (string) file_get_contents($fontDir . '/LICENSE.txt'));
    }

    public function testLocalFontFaceAndScopedUsage(): void
    {
        $fontsCss = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/stylesheet/mt_uni_credit_fonts.css'
        );
        $productCss = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/stylesheet/mt_uni_credit_product.css'
        );

        self::assertStringContainsString('@font-face', $fontsCss);
        self::assertStringContainsString('font-family: "Roboto Condensed"', $fontsCss);
        self::assertStringContainsString('font-weight: 400', $fontsCss);
        self::assertStringContainsString('font-weight: 700', $fontsCss);
        self::assertStringContainsString('roboto-condensed-cyrillic-400.woff2', $fontsCss);
        self::assertStringContainsString('roboto-condensed-latin-700.woff2', $fontsCss);
        self::assertStringNotContainsString('fonts.googleapis.com', $fontsCss);
        self::assertStringNotContainsString('fonts.gstatic.com', $fontsCss);
        self::assertStringNotContainsString('http://', $fontsCss);
        self::assertStringNotContainsString('https://', $fontsCss);

        self::assertStringContainsString('#mt-uni-credit-product-root', $productCss);
        self::assertStringContainsString('#mt-uni-credit-product-modal', $productCss);
        self::assertStringContainsString('"Roboto Condensed"', $productCss);
        self::assertDoesNotMatchRegularExpression('/^\s*body\s*\{[^}]*font-family/m', $productCss);
        self::assertDoesNotMatchRegularExpression('/^\s*body\s*\{[^}]*font-family/m', $fontsCss);
    }

    public function testNoForbiddenRemoteStaticDependenciesInFrontendSources(): void
    {
        $forbidden = [
            'fonts.googleapis.com',
            'fonts.gstatic.com',
            'cdnjs.cloudflare.com',
            'cdn.jsdelivr.net',
            'unpkg.com',
            '//fonts.',
            '@import url(http',
            '@import url(https',
            '@import url("//',
        ];

        $scanRoots = [
            dirname(__DIR__) . '/catalog/view/javascript',
            dirname(__DIR__) . '/catalog/view/stylesheet',
            dirname(__DIR__) . '/catalog/view/template',
            dirname(__DIR__) . '/admin/view',
        ];

        foreach ($scanRoots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, ['js', 'css', 'twig', 'html'], true)) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach ($forbidden as $needle) {
                    self::assertStringNotContainsString(
                        $needle,
                        $contents,
                        $file->getPathname() . ' must not reference ' . $needle
                    );
                }
            }
        }
    }

    public function testCpAdvertisingRemoteExceptionRemainsDocumentedInContracts(): void
    {
        $contracts = (string) file_get_contents(dirname(__DIR__) . '/docs/CONTRACTS.md');
        self::assertStringContainsString('Only CP-provided advertising images', $contracts);
        self::assertStringContainsString('Storefront production JS emits no intentional', $contracts);
        self::assertStringContainsString('Roboto Condensed is bundled locally', $contracts);
    }

    public function testFontsStylesheetUsesFilemtimeAssetVersioning(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_product_controller.php'
        );
        self::assertStringContainsString('mt_uni_credit_fonts.css', $controller);
        self::assertStringContainsString('ModuleAssetVersion::href', $controller);

        $href = ModuleAssetVersion::href('catalog/view/stylesheet/mt_uni_credit_fonts.css');
        $mtime = (string) filemtime(ModuleAssetVersion::absolutePath('catalog/view/stylesheet/mt_uni_credit_fonts.css'));
        self::assertStringContainsString('?ver=' . $mtime, $href);
        self::assertStringNotContainsString('?ver=' . ModuleConstants::VERSION, $href);
    }

    public function testDebugModeRemainsServerSideOnly(): void
    {
        $view = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_product_view.php'
        );
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
        $helpBg = (string) file_get_contents(dirname(__DIR__) . '/admin/language/bg-bg/module/mt_uni_credit.php');

        self::assertStringContainsString('ProductVisibilityDebugLog', $view);
        self::assertStringContainsString('DEBUG_ENABLED', $view);
        self::assertStringNotContainsString('debug_enabled', $js);
        self::assertStringContainsString('сървърни SmartUCF диагностични', $helpBg);
        self::assertStringContainsString('не се показват на клиента', $helpBg);
    }
}
