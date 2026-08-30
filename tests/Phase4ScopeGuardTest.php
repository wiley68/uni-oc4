<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase4ScopeGuardTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_MARKERS = [
        'SmartUcfSession',
        'hash_hmac',
        'financing_snapshot',
        'updateOrderStatus',
        '/orders/status',
        'PopupSubmission',
        'CheckoutPayment',
    ];

    /** @var list<string> */
    private const FORBIDDEN_TABLES = [
        'mt_uni_credit_financing_snapshot',
        'mt_uni_credit_smartucf_log',
        'mt_uni_credit_token',
        'mt_uni_credit_popup_submission',
        'mt_uni_credit_checkout_lock',
    ];

    public function testNoPhase5PlusProductionMarkersInAdminAndSystem(): void
    {
        $root = dirname(__DIR__);

        foreach ([$root . '/admin', $root . '/system'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                if (self::isBridgeAAllowed($file->getPathname()) || self::isPhase11Allowed($file->getPathname())) {
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
            || str_ends_with($path, '/system/library/control_panel_order_status_port.php')
            || str_ends_with($path, '/system/library/post_control_panel_lifecycle_service.php')
            || str_ends_with($path, '/system/library/shop_configuration_flags.php')
            || str_ends_with($path, '/system/library/financing_control_panel_completion.php')
            || str_ends_with($path, '/system/library/control_panel_client.php');
    }

    public function testPhase4IncludesCpClientAndPhase10BOrderCreate(): void
    {
        self::assertFileExists(dirname(__DIR__) . '/system/library/control_panel_client.php');
        self::assertFileExists(dirname(__DIR__) . '/system/library/shop_configuration_service.php');
        $client = (string) file_get_contents(dirname(__DIR__) . '/system/library/control_panel_client.php');
        self::assertStringContainsString('class ControlPanelClient', $client);
        self::assertStringContainsString('function createOrder', $client);
        self::assertStringContainsString("'/orders'", $client);
        self::assertStringContainsString('updateOrderStatus', $client);
        self::assertStringContainsString("'/orders/status'", $client);
        self::assertStringNotContainsString('SmartUcfSession', $client);
    }

    public function testSchemaInstallerDoesNotCreateForbiddenTables(): void
    {
        $sql = implode("\n", \Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller::createTableStatements('oc_'));
        foreach (self::FORBIDDEN_TABLES as $table) {
            self::assertStringNotContainsString($table, $sql);
        }
    }
}
