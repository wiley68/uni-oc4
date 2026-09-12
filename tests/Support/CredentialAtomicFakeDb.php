<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\DbConnection;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceTableNames;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfCredentialRepository;

/**
 * In-process MySQL-like boundary: GET_LOCK, START TRANSACTION/COMMIT/ROLLBACK, setting + shop_cache.
 *
 * Models committed vs working state so concurrent-reader assertions can observe only committed rows.
 */
final class CredentialAtomicFakeDb implements DbConnection
{
    /** @var array<int, array<string, string>> */
    private array $committedSettings = [];

    /** @var array<int, array<string, string>> */
    private array $workingSettings = [];

    /** @var array<string, array<string, mixed>> */
    private array $committedCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $workingCache = [];

    public int $openTransactions = 0;

    /** @var array<string, true> */
    public array $heldLocks = [];

    /** @var list<string> */
    public array $lockAcquireAttempts = [];

    public int $settingWriteCount = 0;

    public int $failOnSettingWriteNumber = 0;

    public bool $failNextCacheWrite = false;

    public bool $failNextLock = false;

    public bool $wroteShopCache = false;

    public bool $sqlContainedPlaintextCredential = false;

    /** @var list<array{user: ?string, password: ?string}> */
    public array $committedObservations = [];

    /** @var (callable(): void)|null */
    public $afterEachSettingWrite = null;

    /** @var (callable(string): void)|null */
    public $onLockAcquired = null;

    /** @var (callable(string): void)|null */
    public $onLockReleased = null;

    public function query(string $sql): object
    {
        if (preg_match('/SELECT GET_LOCK\(\'([^\']+)\'/', $sql, $m)) {
            $this->lockAcquireAttempts[] = $m[1];
            if ($this->failNextLock) {
                $this->failNextLock = false;

                return $this->selectRow(['locked' => 0]);
            }
            if (isset($this->heldLocks[$m[1]])) {
                return $this->selectRow(['locked' => 0]);
            }
            $this->heldLocks[$m[1]] = true;
            if ($this->onLockAcquired !== null) {
                ($this->onLockAcquired)($m[1]);
            }

            return $this->selectRow(['locked' => 1]);
        }

        if (preg_match('/SELECT RELEASE_LOCK\(\'([^\']+)\'/', $sql, $m)) {
            unset($this->heldLocks[$m[1]]);
            if ($this->onLockReleased !== null) {
                ($this->onLockReleased)($m[1]);
            }

            return $this->selectRow(['released' => 1]);
        }

        if (preg_match('/^\s*START TRANSACTION/i', $sql)) {
            $this->workingSettings = $this->cloneSettings($this->committedSettings);
            $this->workingCache = $this->cloneCache($this->committedCache);
            $this->openTransactions++;

            return $this->emptyResult();
        }

        if (preg_match('/^\s*COMMIT/i', $sql)) {
            if ($this->openTransactions < 1) {
                throw new \RuntimeException('COMMIT without transaction.');
            }
            $this->committedSettings = $this->cloneSettings($this->workingSettings);
            $this->committedCache = $this->cloneCache($this->workingCache);
            $this->openTransactions--;

            return $this->emptyResult();
        }

        if (preg_match('/^\s*ROLLBACK/i', $sql)) {
            if ($this->openTransactions < 1) {
                throw new \RuntimeException('ROLLBACK without transaction.');
            }
            $this->workingSettings = $this->cloneSettings($this->committedSettings);
            $this->workingCache = $this->cloneCache($this->committedCache);
            $this->openTransactions--;

            return $this->emptyResult();
        }

        if (preg_match('/FROM `[^`]+setting`[\s\S]*`store_id` = (\d+)[\s\S]*`key` IN \((.+)\)/s', $sql, $m)
            && preg_match('/^\s*SELECT/i', $sql)
        ) {
            $storeId = (int) $m[1];
            preg_match_all("/'([^']+)'/", $m[2], $keys);
            $rows = [];
            $source = $this->settingsSource();
            foreach ($keys[1] as $key) {
                if (isset($source[$storeId][$key])) {
                    $rows[] = ['key' => $key, 'value' => $source[$storeId][$key]];
                }
            }

            return $this->selectRows($rows);
        }

        if (preg_match('/FROM `[^`]+setting`[\s\S]*`store_id` = (\d+)[\s\S]*`key` = \'([^\']+)\'/s', $sql, $m)
            && preg_match('/^\s*SELECT/i', $sql)
        ) {
            $storeId = (int) $m[1];
            $key = $m[2];
            $source = $this->settingsSource();
            if (!isset($source[$storeId][$key])) {
                return $this->selectRows([]);
            }

            return $this->selectRows([['value' => $source[$storeId][$key]]]);
        }

        if (preg_match(
            '/INSERT INTO `[^`]+setting`[\s\S]*VALUES \(\s*(\d+)\s*,\s*\'[^\']*\'\s*,\s*\'([^\']+)\'\s*,\s*\'((?:\\\\\'|[^\'])*)\'\s*,\s*0\s*\)/s',
            $sql,
            $m
        )) {
            $this->writeSetting((int) $m[1], $m[2], stripcslashes($m[3]));

            return $this->emptyResult();
        }

        if (preg_match(
            '/UPDATE `[^`]+setting`[\s\S]*SET `value` = \'((?:\\\\\'|[^\'])*)\'[\s\S]*`store_id` = (\d+)[\s\S]*`key` = \'([^\']+)\'/s',
            $sql,
            $m
        )) {
            $this->writeSetting((int) $m[2], $m[3], stripcslashes($m[1]));

            return $this->emptyResult();
        }

        if (preg_match('/DELETE FROM `[^`]+setting`[\s\S]*`store_id` = (\d+)[\s\S]*`key` = \'([^\']+)\'/s', $sql, $m)) {
            $storeId = (int) $m[1];
            $key = $m[2];
            $target =& $this->settingsSourceRef();
            unset($target[$storeId][$key]);

            return $this->emptyResult();
        }

        if (str_contains($sql, 'INSERT INTO') && str_contains($sql, PersistenceTableNames::SHOP_CACHE)) {
            if (str_contains($sql, 'demo-user') || str_contains($sql, 'demo-secret-password')) {
                $this->sqlContainedPlaintextCredential = true;
            }
            if ($this->failNextCacheWrite) {
                $this->failNextCacheWrite = false;
                throw new \RuntimeException('Forced cache persistence failure.');
            }
            if (!preg_match(
                '/VALUES \(\s*(\d+)\s*,\s*\'([^\']+)\'\s*,\s*\'((?:\\\\\'|[^\'])*)\'\s*,\s*\'/s',
                $sql,
                $m
            )) {
                throw new \RuntimeException('Unable to parse shop cache insert.');
            }
            $storeId = (int) $m[1];
            $unicid = $m[2];
            $json = stripcslashes($m[3]);
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Invalid shop cache JSON.');
            }
            $cache =& $this->cacheSourceRef();
            $cache[$storeId . ':' . $unicid] = $decoded;
            $this->wroteShopCache = true;

            return $this->emptyResult();
        }

        if (str_contains($sql, PersistenceTableNames::SHOP_CACHE) && preg_match('/^\s*SELECT/i', $sql)) {
            if (!preg_match('/`store_id` = (\d+)[\s\S]*`unicid` = \'([^\']+)\'/s', $sql, $m)) {
                return $this->selectRows([]);
            }
            $key = $m[1] . ':' . $m[2];
            $cache = $this->cacheSource();
            if (!isset($cache[$key])) {
                return $this->selectRows([]);
            }
            $encoded = json_encode($cache[$key], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return $this->selectRows([[
                'shop_data' => $encoded,
                'fetched_at' => '2026-01-01 00:00:00',
                'expires_at' => '2099-01-01 00:00:00',
            ]]);
        }

        if (str_contains($sql, 'DELETE FROM') && str_contains($sql, PersistenceTableNames::SHOP_CACHE)) {
            return $this->emptyResult();
        }

        return $this->emptyResult();
    }

    /**
     * @return array{user: ?string, password: ?string}
     */
    public function committedCredentialRawPair(int $storeId): array
    {
        return [
            'user' => $this->committedSettings[$storeId][SmartUcfCredentialRepository::USER_SETTING] ?? null,
            'password' => $this->committedSettings[$storeId][SmartUcfCredentialRepository::PASSWORD_SETTING] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function committedShopData(int $storeId, string $unicid): ?array
    {
        return $this->committedCache[$storeId . ':' . $unicid] ?? null;
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function countAffected(): int
    {
        return 1;
    }

    public function getLastId(): int
    {
        return 0;
    }

    public function getPrefix(): string
    {
        return 'oc_';
    }

    private function writeSetting(int $storeId, string $key, string $value): void
    {
        $this->settingWriteCount++;
        if ($this->failOnSettingWriteNumber > 0 && $this->settingWriteCount === $this->failOnSettingWriteNumber) {
            throw new \RuntimeException('Forced setting write failure.');
        }
        $target =& $this->settingsSourceRef();
        $target[$storeId][$key] = $value;
        if ($this->afterEachSettingWrite !== null) {
            ($this->afterEachSettingWrite)();
        }
    }

    /** @return array<int, array<string, string>> */
    private function settingsSource(): array
    {
        return $this->openTransactions > 0 ? $this->workingSettings : $this->committedSettings;
    }

    /** @return array<int, array<string, string>> */
    private function &settingsSourceRef(): array
    {
        if ($this->openTransactions > 0) {
            return $this->workingSettings;
        }

        return $this->committedSettings;
    }

    /** @return array<string, array<string, mixed>> */
    private function cacheSource(): array
    {
        return $this->openTransactions > 0 ? $this->workingCache : $this->committedCache;
    }

    /** @return array<string, array<string, mixed>> */
    private function &cacheSourceRef(): array
    {
        if ($this->openTransactions > 0) {
            return $this->workingCache;
        }

        return $this->committedCache;
    }

    /**
     * @param array<int, array<string, string>> $source
     * @return array<int, array<string, string>>
     */
    private function cloneSettings(array $source): array
    {
        $copy = [];
        foreach ($source as $storeId => $rows) {
            $copy[$storeId] = $rows;
        }

        return $copy;
    }

    /**
     * @param array<string, array<string, mixed>> $source
     * @return array<string, array<string, mixed>>
     */
    private function cloneCache(array $source): array
    {
        $copy = [];
        foreach ($source as $key => $row) {
            $copy[$key] = $row;
        }

        return $copy;
    }

    /** @param array<string, mixed> $row */
    private function selectRow(array $row): object
    {
        return $this->selectRows([$row]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function selectRows(array $rows): object
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

    private function emptyResult(): object
    {
        return $this->selectRows([]);
    }
}
