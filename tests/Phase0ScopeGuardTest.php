<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards frozen Phase 0 scope boundaries that must remain true after later phases.
 */
final class Phase0ScopeGuardTest extends TestCase
{
    public function testStorefrontModuleControllerTreeExists(): void
    {
        $root = dirname(__DIR__);
        self::assertDirectoryExists($root . '/catalog/controller/module');
        // Nested bogus path must stay absent (cart is a file, not a directory).
        self::assertDirectoryDoesNotExist($root . '/catalog/controller/module/mt_uni_credit_cart');
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

            if (self::isPhase4CpProductionFile($path) || self::isBridgeAInboundProductionFile($path)
                || self::isPhase11SmartUcfProductionFile($path)) {
                continue;
            }

            $contents = (string) file_get_contents($path);
            self::assertStringNotContainsString('CREATE TABLE', $contents, $path);
            self::assertStringNotContainsString('ControlPanelClient', $contents, $path);
            self::assertStringNotContainsString('SmartUcf', $contents, $path);
        }
    }

    private static function isBridgeAInboundProductionFile(string $path): bool
    {
        return str_contains($path, '/catalog/controller/api/')
            || str_contains($path, '/system/library/module_request_')
            || str_contains($path, '/system/library/module_api_exception.php')
            || str_contains($path, '/system/library/inbound_')
            || str_contains($path, '/system/library/order_bank_status_repository.php')
            || str_contains($path, '/system/library/diagnostic_');
    }

    private static function isPhase4CpProductionFile(string $path): bool
    {
        return str_contains($path, '/system/library/control_panel_client.php')
            || str_contains($path, '/system/library/control_panel_order_')
            || str_contains($path, '/system/library/control_panel_error_class.php')
            || str_contains($path, '/system/library/financing_control_panel_completion.php')
            || str_contains($path, '/system/library/cp_')
            || str_contains($path, '/system/library/shop_configuration_service.php')
            || str_contains($path, '/system/library/shop_configuration_snapshot_validator.php')
            || str_contains($path, '/system/library/credential_change_handler.php')
            || str_contains($path, '/system/library/canonical_shop_url_provider.php')
            || str_contains($path, '/system/library/module_credentials_repository.php')
            || str_contains($path, '/system/library/module_encryption_key_provider.php')
            || str_contains($path, '/system/library/open_cart_module_setting_store.php')
            || str_contains($path, '/admin/model/module/mt_uni_credit.php');
    }

    private static function isPhase11SmartUcfProductionFile(string $path): bool
    {
        return str_contains($path, '/system/library/smart_ucf_')
            || str_contains($path, '/system/library/certificate_')
            || str_ends_with($path, '/system/library/bank_status.php')
            || str_ends_with($path, '/system/library/post_control_panel_lifecycle_service.php')
            || str_ends_with($path, '/system/library/shop_configuration_flags.php')
            || str_ends_with($path, '/system/library/financing_control_panel_completion.php');
    }
}
