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
        'createOrder',
        'updateOrderStatus',
        'POST /orders',
        '/orders/status',
        'catalog/controller/payment',
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
        self::assertDirectoryDoesNotExist($root . '/catalog/controller/payment');
        self::assertDirectoryDoesNotExist($root . '/admin/controller/payment');

        foreach ([$root . '/admin', $root . '/system'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
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

    public function testPhase4IncludesCpClientWithoutOrderEndpoints(): void
    {
        self::assertFileExists(dirname(__DIR__) . '/system/library/control_panel_client.php');
        self::assertFileExists(dirname(__DIR__) . '/system/library/shop_configuration_service.php');
        $client = (string) file_get_contents(dirname(__DIR__) . '/system/library/control_panel_client.php');
        self::assertStringContainsString('class ControlPanelClient', $client);
        self::assertStringNotContainsString('function createOrder', $client);
    }

    public function testSchemaInstallerDoesNotCreateForbiddenTables(): void
    {
        $sql = implode("\n", \Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller::createTableStatements('oc_'));
        foreach (self::FORBIDDEN_TABLES as $table) {
            self::assertStringNotContainsString($table, $sql);
        }
    }
}
