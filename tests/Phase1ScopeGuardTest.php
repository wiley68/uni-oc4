<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase1ScopeGuardTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_PRODUCTION_MARKERS = [
        'SmartUcfSession',
        'hash_hmac',
        'orderbankstatus',
        'shopcache',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PATH_SUFFIXES = [
    ];

    public function testPhase2PlusPathsAreAbsent(): void
    {
        $root = dirname(__DIR__);
        self::assertDirectoryExists($root . '/system/library');
        foreach (self::FORBIDDEN_PATH_SUFFIXES as $suffix) {
            self::assertDirectoryDoesNotExist($root . $suffix, $suffix);
        }
    }

    public function testProductionCodeHasNoFinancingOrCpImplementation(): void
    {
        $root = dirname(__DIR__);
        $dirs = [
            $root . '/admin',
            $root . '/system',
        ];

        foreach ($dirs as $dir) {
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
                if (str_ends_with($file->getPathname(), '/system/library/persistence_schema_installer.php')) {
                    continue;
                }
                if (self::isBridgeAAllowed($file->getPathname()) || self::isPhase11Allowed($file->getPathname())
                    || \MtUniCredit\Tests\Support\ScopeGuardAllowlist::isPhase11PlusProductionFile($file->getPathname())) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach (self::FORBIDDEN_PRODUCTION_MARKERS as $marker) {
                    self::assertStringNotContainsString(
                        $marker,
                        $contents,
                        $file->getPathname() . ' must not contain ' . $marker
                    );
                }
            }
        }
    }

    private static function isBridgeAAllowed(string $path): bool
    {
        return str_contains($path, '/system/library/module_request_')
            || str_contains($path, '/system/library/module_api_exception.php')
            || str_contains($path, '/system/library/inbound_')
            || str_contains($path, '/system/library/order_bank_status_repository.php')
            || str_contains($path, '/system/library/diagnostic_');
    }

    private static function isPhase11Allowed(string $path): bool
    {
        return str_contains($path, '/system/library/smart_ucf_')
            || str_ends_with($path, '/system/library/bank_status.php')
            || str_ends_with($path, '/system/library/post_control_panel_lifecycle_service.php')
            || str_ends_with($path, '/system/library/shop_configuration_flags.php')
            || str_ends_with($path, '/system/library/financing_control_panel_completion.php');
    }

    public function testAdminSettingsRemainLocalOnly(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__) . '/admin/view/template/module/mt_uni_credit.twig');
        self::assertStringContainsString('module_mt_uni_credit_status', $twig);
        // CP host is display-only; secret uses password field with empty value (never re-rendered).
        self::assertStringNotContainsString('name="control_panel', $twig);
        self::assertStringContainsString('name="module_mt_uni_credit_secret"', $twig);
        self::assertStringContainsString('type="password"', $twig);
        self::assertStringContainsString('value=""', $twig);
        self::assertStringNotContainsString('type="file"', $twig);
        self::assertStringNotContainsString('passphrase', strtolower($twig));
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $twig);
        self::assertStringNotContainsString('BEGIN CERTIFICATE', $twig);
    }
}
