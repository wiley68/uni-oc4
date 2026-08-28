<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards frozen Phase 0 scope boundaries that must remain true after later phases.
 */
final class Phase0ScopeGuardTest extends TestCase
{
    public function testStorefrontFinancingPathsStillAbsent(): void
    {
        $root = dirname(__DIR__);
        foreach (
            [
                $root . '/catalog/controller/payment',
                $root . '/catalog/controller/module',
                $root . '/catalog/model',
                $root . '/catalog/view/template/payment',
            ] as $path
        ) {
            self::assertDirectoryDoesNotExist($path, $path);
        }
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
            if (str_ends_with($path, '/system/library/persistence_schema_installer.php')) {
                $contents = (string) file_get_contents($path);
                self::assertStringNotContainsString('ControlPanelClient', $contents, $path);
                self::assertStringNotContainsString('SmartUcf', $contents, $path);
                continue;
            }

            $contents = (string) file_get_contents($path);
            self::assertStringNotContainsString('CREATE TABLE', $contents, $path);
            self::assertStringNotContainsString('ControlPanelClient', $contents, $path);
            self::assertStringNotContainsString('SmartUcf', $contents, $path);
        }
    }
}
