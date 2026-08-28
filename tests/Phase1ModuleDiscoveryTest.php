<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\OpenCartExtensionDiscovery;
use PHPUnit\Framework\TestCase;

final class Phase1ModuleDiscoveryTest extends TestCase
{
    public function testModuleControllerDirectoryContainsOnlyRealModuleFile(): void
    {
        $root = dirname(__DIR__);
        $dir = $root . '/admin/controller/module';
        self::assertDirectoryExists($dir);

        $phpFiles = glob($dir . '/*.php') ?: [];
        $basenames = array_map(static fn (string $path): string => basename($path), $phpFiles);
        sort($basenames);

        self::assertSame(['mt_uni_credit.php'], $basenames);
    }

    public function testOpenCartModuleDiscoverySetContainsMtUniCreditOnly(): void
    {
        $codes = OpenCartExtensionDiscovery::discoveredModuleCodes(dirname(__DIR__));
        self::assertSame(['mt_uni_credit'], $codes);
        self::assertNotContains('index', $codes);
    }

    public function testNoGenericIndexPhpInScannedAdminControllerDirectories(): void
    {
        self::assertSame(
            [],
            OpenCartExtensionDiscovery::genericIndexPhpInScannedControllerDirs(dirname(__DIR__)),
            'Generic index.php must not exist under admin/controller/{type}/ — OpenCart glob treats every .php as a component code.'
        );
    }

    public function testNoIndexPhpStubInModuleModelOrLanguagePaths(): void
    {
        $root = dirname(__DIR__);
        foreach ([
            'admin/model/module/index.php',
            'admin/language/en-gb/module/index.php',
            'admin/language/bg-bg/module/index.php',
            'admin/controller/module/index.php',
        ] as $relative) {
            self::assertFileDoesNotExist($root . '/' . $relative, $relative);
        }
    }
}
