<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase3ScopeGuardTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_MARKERS = [
        'SmartUcfSession',
        'hash_hmac',
        'catalog/controller/module',
                'updateOrderStatus',
    ];

    /** @var list<string> */
    private const FORBIDDEN_TABLES = [
        'mt_uni_credit_financing_snapshot',
        'mt_uni_credit_smartucf_log',
        'mt_uni_credit_token',
        'mt_uni_credit_popup_submission',
        'mt_uni_credit_checkout_lock',
    ];

    public function testNoPhase4PlusPaymentControllersOrClients(): void
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

    public function testSchemaInstallerDoesNotCreatePhase4PlusTables(): void
    {
        $sql = implode("\n", \Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller::createTableStatements('oc_'));
        foreach (self::FORBIDDEN_TABLES as $table) {
            self::assertStringNotContainsString($table, $sql);
        }
    }

    public function testUninstallModelDoesNotDropPersistenceTables(): void
    {
        $model = (string) file_get_contents(dirname(__DIR__) . '/admin/model/module/mt_uni_credit.php');
        self::assertStringNotContainsString('DROP TABLE', strtoupper($model));
    }
}
