<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the single-source Control Panel URL rule in production code.
 */
final class Phase2CpEndpointSingleSourceTest extends TestCase
{
    public function testNoFallbackControlPanelUrlOutsideEnvironmentFile(): void
    {
        $root = dirname(__DIR__);
        $allowed = [
            realpath($root . '/config/environment.php'),
            realpath($root . '/system/library/module_deployment_environment.php'),
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/vendor/') || str_contains($path, '/tests/') || str_contains($path, '/stubs/')) {
                continue;
            }

            $real = realpath($path);
            if ($real !== false && in_array($real, $allowed, true)) {
                continue;
            }

            $contents = (string) file_get_contents($path);
            self::assertStringNotContainsString(
                'uni.avalonbg.com',
                $contents,
                $path . ' must not hard-code the Control Panel host'
            );
            self::assertDoesNotMatchRegularExpression(
                '#https?://[^\s\'"]*control[^\s\'"]*#i',
                $contents,
                $path . ' must not embed a Control Panel URL'
            );
        }
    }

    public function testEnvironmentFileIsTheOnlyTrackedControlPanelUrlKeyAssignment(): void
    {
        $root = dirname(__DIR__);
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/vendor/') || str_contains($path, '/tests/') || str_contains($path, '/stubs/')) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if (preg_match("/['\"]control_panel_url['\"]\\s*=>/", $contents)) {
                $hits[] = substr($path, strlen($root) + 1);
            }
        }

        self::assertSame(['config/environment.php'], $hits);
    }
}
