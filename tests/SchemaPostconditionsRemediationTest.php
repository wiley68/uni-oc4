<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\PersistenceException;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceTableNames;
use PHPUnit\Framework\TestCase;

/**
 * OC4-REAUDIT-03 / REVIEW-11 — UNICID composite index upgrade + postconditions.
 */
final class SchemaPostconditionsRemediationTest extends TestCase
{
    public function testCleanInstallDdlContainsStoreOrderUnicidIndex(): void
    {
        $sql = implode("\n", PersistenceSchemaInstaller::createTableStatements('oc_'));
        self::assertStringContainsString(
            'KEY `idx_mt_uni_credit_attempt_store_order_unicid` (`store_id`, `order_id`, `unicid`)',
            $sql
        );
    }

    public function testVerifySchemaPostconditionsPassesWhenRequiredPresent(): void
    {
        $db = new FakeSchemaDb([
            'mode' => 'complete',
        ]);
        (new PersistenceSchemaInstaller($db))->verifySchemaPostconditions();
        self::assertTrue(true);
    }

    public function testVerifySchemaPostconditionsThrowsWhenColumnMissing(): void
    {
        $db = new FakeSchemaDb(['mode' => 'missing_claim_token']);
        $this->expectException(PersistenceException::class);
        (new PersistenceSchemaInstaller($db))->verifySchemaPostconditions();
    }

    public function testExistingInstallMissingUnicidIndexIsAddedByUpgrade(): void
    {
        $db = new FakeSchemaDb([
            'mode' => 'upgrade_missing_unicid_index',
        ]);
        (new PersistenceSchemaInstaller($db))->ensureUpgrades();
        self::assertTrue($db->addedUnicidIndex);
        self::assertContains('idx_mt_uni_credit_attempt_store_order_unicid', $db->indexes);
    }

    public function testExistingCorrectUnicidIndexIsIdempotent(): void
    {
        $db = new FakeSchemaDb(['mode' => 'complete']);
        (new PersistenceSchemaInstaller($db))->ensureUpgrades();
        self::assertFalse($db->addedUnicidIndex);
    }

    public function testCreateIndexRaceThenVerificationPasses(): void
    {
        $db = new FakeSchemaDb([
            'mode' => 'race_add_unicid_index',
        ]);
        (new PersistenceSchemaInstaller($db))->ensureUpgrades();
        self::assertContains('idx_mt_uni_credit_attempt_store_order_unicid', $db->indexes);
    }

    public function testMissingUnicidIndexAfterUpgradeFailsPostcondition(): void
    {
        $db = new FakeSchemaDb([
            'mode' => 'verify_only_cp_sync_index',
        ]);
        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('idx_mt_uni_credit_attempt_store_order_unicid');
        (new PersistenceSchemaInstaller($db))->verifySchemaPostconditions();
    }

    public function testWrongUnicidIndexColumnOrderFailsPostcondition(): void
    {
        $db = new FakeSchemaDb([
            'mode' => 'wrong_unicid_order',
        ]);
        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('column order mismatch');
        (new PersistenceSchemaInstaller($db))->verifySchemaPostconditions();
    }
}

final class FakeSchemaDb implements \Opencart\System\Library\Extension\MtUniCredit\DbConnection
{
    /** @var array{mode: string} */
    private array $config;

    /** @var list<string> */
    public array $indexes = [
        'idx_mt_uni_credit_attempt_cp_status_sync',
    ];

    public bool $addedUnicidIndex = false;

    /** @param array{mode: string} $config */
    public function __construct(array $config)
    {
        $this->config = $config;
        if (($config['mode'] ?? '') === 'complete' || ($config['mode'] ?? '') === 'wrong_unicid_order') {
            $this->indexes[] = 'idx_mt_uni_credit_attempt_store_order_unicid';
        }
    }

    public function query(string $sql): object
    {
        $required = [
            'unicid',
            'process2_mail_state',
            'process2_mail_claimed_at',
            'process2_mail_claim_token',
            'cp_status_sync_state',
            'cp_status_sync_status_id',
            'cp_status_sync_status',
            'cp_status_sync_error_class',
            'cp_status_sync_updated_at',
        ];

        if (str_contains($sql, 'SHOW COLUMNS') && str_contains($sql, 'LIKE')) {
            if (preg_match("/LIKE '([^']+)'/", $sql, $m)) {
                $col = $m[1];
                $present = true;
                if (($this->config['mode'] ?? '') === 'missing_claim_token' && $col === 'process2_mail_claim_token') {
                    $present = false;
                }

                return $this->result($present ? [['Field' => $col]] : []);
            }
        }

        if (str_contains($sql, 'SHOW COLUMNS')) {
            $rows = [];
            foreach ($required as $col) {
                if (($this->config['mode'] ?? '') === 'missing_claim_token' && $col === 'process2_mail_claim_token') {
                    continue;
                }
                $rows[] = ['Field' => $col];
            }

            return $this->result($rows);
        }

        if (str_contains($sql, 'SHOW INDEX')) {
            if (preg_match("/Key_name = '([^']+)'/", $sql, $m)) {
                $name = $m[1];
                if (!in_array($name, $this->indexes, true)) {
                    return $this->result([]);
                }
                if ($name === 'idx_mt_uni_credit_attempt_cp_status_sync') {
                    return $this->result([
                        ['Key_name' => $name, 'Seq_in_index' => 1, 'Column_name' => 'cp_status_sync_state'],
                    ]);
                }
                if ($name === 'idx_mt_uni_credit_attempt_store_order_unicid') {
                    if (($this->config['mode'] ?? '') === 'wrong_unicid_order') {
                        return $this->result([
                            ['Key_name' => $name, 'Seq_in_index' => 1, 'Column_name' => 'unicid'],
                            ['Key_name' => $name, 'Seq_in_index' => 2, 'Column_name' => 'store_id'],
                            ['Key_name' => $name, 'Seq_in_index' => 3, 'Column_name' => 'order_id'],
                        ]);
                    }

                    return $this->result([
                        ['Key_name' => $name, 'Seq_in_index' => 1, 'Column_name' => 'store_id'],
                        ['Key_name' => $name, 'Seq_in_index' => 2, 'Column_name' => 'order_id'],
                        ['Key_name' => $name, 'Seq_in_index' => 3, 'Column_name' => 'unicid'],
                    ]);
                }

                return $this->result([['Key_name' => $name, 'Seq_in_index' => 1, 'Column_name' => 'x']]);
            }

            return $this->result([]);
        }

        if (str_contains($sql, 'ADD KEY `idx_mt_uni_credit_attempt_store_order_unicid`')) {
            if (($this->config['mode'] ?? '') === 'race_add_unicid_index') {
                $this->indexes[] = 'idx_mt_uni_credit_attempt_store_order_unicid';
                throw new \RuntimeException('Duplicate key name 1061');
            }
            $this->addedUnicidIndex = true;
            $this->indexes[] = 'idx_mt_uni_credit_attempt_store_order_unicid';

            return $this->result([]);
        }

        if (str_contains($sql, 'ADD KEY') || str_contains($sql, 'ADD COLUMN') || str_contains($sql, 'MODIFY COLUMN') || str_contains($sql, 'ALTER TABLE')) {
            return $this->result([]);
        }

        return $this->result([]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function result(array $rows): object
    {
        return new class ($rows) {
            public int $num_rows;
            /** @var array<string, mixed> */
            public array $row;
            /** @var list<array<string, mixed>> */
            public array $rows;

            /** @param list<array<string, mixed>> $rows */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
                $this->num_rows = count($rows);
                $this->row = $rows[0] ?? [];
            }
        };
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function countAffected(): int
    {
        return 0;
    }

    public function getLastId(): int
    {
        return 0;
    }

    public function getPrefix(): string
    {
        return 'oc_';
    }
}
