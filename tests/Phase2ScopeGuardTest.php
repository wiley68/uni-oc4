<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase2ScopeGuardTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_MARKERS = [
        'CertificateSynchronizer',
        'SmartUcfSession',
        'orderbankstatus',
        'shopcache',
        'financing_snapshot',
    ];

    public function testAdminEventDirOnlyHoldsPresentationHooks(): void
    {
        $root = dirname(__DIR__);
        $dir = $root . '/admin/controller/event';
        // Phase 11B presentation parity authorizes admin event hooks (Orders list + detail).
        self::assertDirectoryExists($dir);
        $files = array_values(array_filter(scandir($dir) ?: [], static fn (string $f): bool => str_ends_with($f, '.php')));
        self::assertSame(['mt_uni_credit_admin_order.php'], $files);
    }

    public function testProductionCodeHasNoCpTransportOrFinancingLifecycle(): void
    {
        $root = dirname(__DIR__);
        foreach ([$root . '/admin', $root . '/system', $root . '/config'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                if (self::isPhase11Allowed($file->getPathname())
                    || \MtUniCredit\Tests\Support\ScopeGuardAllowlist::isPhase11PlusProductionFile($file->getPathname())) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach (self::FORBIDDEN_MARKERS as $marker) {
                    self::assertStringNotContainsString(
                        $marker,
                        $contents,
                        $file->getPathname() . ' must not contain ' . $marker
                    );
                }
            }
        }
    }

    public function testCertificateSyncEndpointsStayInAllowlistedRuntimeFiles(): void
    {
        $root = dirname(__DIR__);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/system', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (self::isCertificateSyncAllowed($file->getPathname())) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            self::assertStringNotContainsString('/ssl/certificate', $contents, $file->getPathname());
            self::assertStringNotContainsString('replacePair', $contents, $file->getPathname());
        }
    }

    public function testWebProtectionCoversDeploymentDirs(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__) . '/.htaccess');
        self::assertStringContainsString('config|keys|secrets', $htaccess);
        self::assertFileExists(dirname(__DIR__) . '/keys/.htaccess');
        self::assertFileExists(dirname(__DIR__) . '/secrets/.htaccess');
    }

    private static function isPhase11Allowed(string $path): bool
    {
        return str_contains($path, '/system/library/smart_ucf_')
            || str_contains($path, '/system/library/certificate_')
            || str_ends_with($path, '/system/library/bank_status.php')
            || str_ends_with($path, '/system/library/control_panel_client.php')
            || str_ends_with($path, '/system/library/post_control_panel_lifecycle_service.php')
            || str_ends_with($path, '/system/library/financing_control_panel_completion.php')
            || str_ends_with($path, '/system/library/shop_configuration_flags.php');
    }

    private static function isCertificateSyncAllowed(string $path): bool
    {
        return str_contains($path, '/system/library/certificate_')
            || str_ends_with($path, '/system/library/control_panel_client.php');
    }
}
