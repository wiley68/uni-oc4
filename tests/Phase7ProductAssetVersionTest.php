<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\ModuleAssetVersion;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use PHPUnit\Framework\TestCase;

final class Phase7ProductAssetVersionTest extends TestCase
{
    private const JS = 'catalog/view/javascript/mt_uni_credit_product.js';

    private const CSS = 'catalog/view/stylesheet/mt_uni_credit_product.css';

    public function testAssetVersionComesFromFileMtime(): void
    {
        $jsPath = ModuleAssetVersion::absolutePath(self::JS);
        self::assertFileExists($jsPath);

        $expected = (string) filemtime($jsPath);
        self::assertSame($expected, ModuleAssetVersion::forRelativePath(self::JS));
        self::assertNotSame(ModuleConstants::VERSION, ModuleAssetVersion::forRelativePath(self::JS));
    }

    public function testJsAndCssAreIndependentlyVersioned(): void
    {
        $jsVer = ModuleAssetVersion::forRelativePath(self::JS);
        $cssVer = ModuleAssetVersion::forRelativePath(self::CSS);
        $fonts = 'catalog/view/stylesheet/mt_uni_credit_fonts.css';
        $fontsVer = ModuleAssetVersion::forRelativePath($fonts);

        self::assertMatchesRegularExpression('/^\d+$/', $jsVer);
        self::assertMatchesRegularExpression('/^\d+$/', $cssVer);
        self::assertMatchesRegularExpression('/^\d+$/', $fontsVer);

        $jsHref = ModuleAssetVersion::href(self::JS);
        $cssHref = ModuleAssetVersion::href(self::CSS);
        $fontsHref = ModuleAssetVersion::href($fonts);

        self::assertStringContainsString('mt_uni_credit_product.js?ver=' . $jsVer, $jsHref);
        self::assertStringContainsString('mt_uni_credit_product.css?ver=' . $cssVer, $cssHref);
        self::assertStringContainsString('mt_uni_credit_fonts.css?ver=' . $fontsVer, $fontsHref);
        self::assertStringNotContainsString('?ver=' . ModuleConstants::VERSION, $jsHref);
        self::assertStringNotContainsString('?ver=' . ModuleConstants::VERSION, $cssHref);
        self::assertStringNotContainsString('?ver=' . ModuleConstants::VERSION, $fontsHref);
    }

    public function testDifferentAssetMtimesProduceDifferentVersions(): void
    {
        $jsPath = ModuleAssetVersion::absolutePath(self::JS);
        $cssPath = ModuleAssetVersion::absolutePath(self::CSS);
        $originalJs = (int) filemtime($jsPath);
        $originalCss = (int) filemtime($cssPath);

        try {
            touch($jsPath, $originalJs - 200);
            touch($cssPath, $originalCss - 50);
            clearstatcache(true, $jsPath);
            clearstatcache(true, $cssPath);

            $jsVer = ModuleAssetVersion::forRelativePath(self::JS);
            $cssVer = ModuleAssetVersion::forRelativePath(self::CSS);

            self::assertNotSame($jsVer, $cssVer);
            self::assertSame((string) filemtime($jsPath), $jsVer);
            self::assertSame((string) filemtime($cssPath), $cssVer);
        } finally {
            touch($jsPath, $originalJs);
            touch($cssPath, $originalCss);
            clearstatcache(true, $jsPath);
            clearstatcache(true, $cssPath);
        }
    }

    public function testMissingFileUsesSafeModuleVersionFallback(): void
    {
        $missing = 'catalog/view/javascript/does_not_exist_' . bin2hex(random_bytes(4)) . '.js';
        self::assertSame(ModuleConstants::VERSION, ModuleAssetVersion::forRelativePath($missing));
        self::assertStringContainsString(
            '?ver=' . ModuleConstants::VERSION,
            ModuleAssetVersion::href($missing)
        );
    }

    public function testModuleVersionIsNotRequiredToChangeWhenAssetChanges(): void
    {
        $releaseBefore = ModuleConstants::VERSION;
        $jsPath = ModuleAssetVersion::absolutePath(self::JS);
        $original = (int) filemtime($jsPath);
        $verBefore = ModuleAssetVersion::forRelativePath(self::JS);

        try {
            touch($jsPath, $original + 15);
            clearstatcache(true, $jsPath);
            $verAfter = ModuleAssetVersion::forRelativePath(self::JS);

            self::assertSame($releaseBefore, ModuleConstants::VERSION);
            self::assertNotSame($verBefore, $verAfter);
        } finally {
            touch($jsPath, $original);
            clearstatcache(true, $jsPath);
        }
    }

    public function testProductAssetRegistrationUsesModuleAssetVersion(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_product_controller.php'
        );

        self::assertStringContainsString('ModuleAssetVersion', $controller);
        self::assertStringContainsString('ModuleAssetVersion::href', $controller);
        self::assertStringContainsString('mt_uni_credit_fonts.css', $controller);
        self::assertStringNotContainsString('ModuleConstants::VERSION', $controller);
        self::assertStringNotContainsString("?ver=' . \$version", $controller);

        $jsHref = ModuleAssetVersion::href(self::JS);
        $cssHref = ModuleAssetVersion::href(self::CSS);
        self::assertStringStartsWith('extension/mt_uni_credit/catalog/view/javascript/', $jsHref);
        self::assertStringStartsWith('extension/mt_uni_credit/catalog/view/stylesheet/', $cssHref);
    }

    public function testPathTraversalRejected(): void
    {
        self::assertSame('', ModuleAssetVersion::absolutePath('../config.php'));
        self::assertSame(ModuleConstants::VERSION, ModuleAssetVersion::forRelativePath('../config.php'));
    }
}
