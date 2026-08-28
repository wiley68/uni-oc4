<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceTableNames;
use PHPUnit\Framework\TestCase;

/**
 * Integration harness must isolate and eventually drop only mtuni_it_* tables.
 */
final class Phase7IntegrationTableCleanupTest extends TestCase
{
    public function testIntegrationPrefixDiffersFromProductionAndContainsMarker(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required (MT_UNI_CREDIT_INTEGRATION=1).');
        }

        $names = PersistenceIntegrationHarness::expectedIntegrationTableNames();
        self::assertCount(5, $names);
        foreach ($names as $name) {
            self::assertStringContainsString(PersistenceIntegrationHarness::INTEGRATION_PREFIX_MARKER, $name);
            self::assertFalse(str_starts_with($name, 'oc_mt_uni_credit_'));
        }
        self::assertContains('oc_mtuni_it_mt_uni_credit_shop_cache', $names);
    }

    public function testUnsafeProductionPrefixIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        PersistenceIntegrationHarness::assertSafeIntegrationPrefix('oc_');
    }

    public function testDropOwnTablesRemovesIntegrationTablesOnly(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required (MT_UNI_CREDIT_INTEGRATION=1).');
        }

        $db = PersistenceIntegrationHarness::connection();
        $prefix = $db->getPrefix();
        PersistenceIntegrationHarness::assertSafeIntegrationPrefix($prefix);
        (new PersistenceSchemaInstaller($db))->installAll();

        foreach (PersistenceTableNames::allPersistenceTables() as $table) {
            $check = $db->query("SHOW TABLES LIKE '" . $db->escape($prefix . $table) . "'");
            self::assertNotEmpty($check->rows);
        }

        // Production tables use OpenCart DB_PREFIX (already loaded by the harness).
        $prodPrefix = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';
        self::assertNotSame($prodPrefix, $prefix);
        foreach (PersistenceTableNames::allPersistenceTables() as $table) {
            $full = $prodPrefix . $table;
            $check = $db->query("SHOW TABLES LIKE '" . $db->escape($full) . "'");
            self::assertNotEmpty($check->rows, 'production table missing: ' . $full);
        }

        PersistenceIntegrationHarness::dropOwnTables();

        foreach (PersistenceTableNames::allPersistenceTables() as $table) {
            $check = $db->query("SHOW TABLES LIKE '" . $db->escape($prefix . $table) . "'");
            self::assertSame([], $check->rows);
        }

        // Production must still exist after integration teardown.
        foreach (PersistenceTableNames::allPersistenceTables() as $table) {
            $full = $prodPrefix . $table;
            $check = $db->query("SHOW TABLES LIKE '" . $db->escape($full) . "'");
            self::assertNotEmpty($check->rows, 'production table was dropped: ' . $full);
        }

        // Re-create for later tests / shutdown idempotency.
        (new PersistenceSchemaInstaller($db))->installAll();
    }
}
