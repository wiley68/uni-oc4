<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase0ScopeGuardTest extends TestCase
{
    public function testPhase0DidNotAddStorefrontOrPersistenceImplementation(): void
    {
        $root = dirname(__DIR__);
        $forbidden = [
            $root . '/catalog/controller/payment',
            $root . '/catalog/controller/module',
            $root . '/catalog/model',
            $root . '/admin/controller',
            $root . '/admin/model',
            $root . '/system/library',
        ];

        foreach ($forbidden as $path) {
            self::assertDirectoryDoesNotExist($path, $path . ' must not exist in Phase 0');
        }

        self::assertFileDoesNotExist($root . '/catalog/view/template/payment');
        self::assertFileDoesNotExist($root . '/admin/view/template');
    }

    public function testNoDatabaseSchemaOrCpClientClasses(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/vendor/') || str_contains($path, '/stubs/') || str_contains($path, '/.phpunit.cache/')) {
                continue;
            }
            if (str_contains($path, '/tests/')) {
                continue;
            }

            $contents = (string) file_get_contents($path);
            self::assertStringNotContainsString('CREATE TABLE', $contents);
            self::assertStringNotContainsString('ControlPanelClient', $contents);
            self::assertStringNotContainsString('SmartUcf', $contents);
        }
    }
}
