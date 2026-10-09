<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\DbConnection;

/** Small stateful SQL fixture for origin boundaries; never opens a database connection. */
final class CpOriginFixtureDb implements DbConnection
{
    public ?array $cacheRow = null;
    public ?array $attemptRow = null;
    public array $queries = [];
    public bool $originColumn = false;
    public int $alters = 0;
    private int $affected = 0;

    public function query(string $sql): object
    {
        $this->queries[] = $sql;
        $this->affected = 0;
        if (str_starts_with($sql, 'SHOW COLUMNS')) {
            return $this->result($this->originColumn ? ['Field' => 'cp_origin'] : null);
        }
        if (str_starts_with($sql, 'ALTER TABLE')) {
            $this->originColumn = true;
            ++$this->alters;
        }
        if (str_contains($sql, 'GET_LOCK')) return $this->result(['locked' => 1, 'acquired' => 1]);
        if (str_contains($sql, 'RELEASE_LOCK')) return $this->result(['released' => 1]);
        if (str_starts_with(ltrim($sql), 'SELECT') && str_contains($sql, 'shop_cache')) {
            $row = $this->cacheRow;
            if ($row !== null && preg_match("/`expires_at` > '([^']+)'/", $sql, $match) && $row['expires_at'] <= $match[1]) $row = null;
            return $this->result($row);
        }
        if (str_starts_with(ltrim($sql), 'SELECT') && str_contains($sql, 'financing_attempt')) return $this->result($this->attemptRow);
        if (str_starts_with(ltrim($sql), 'INSERT') && str_contains($sql, 'shop_cache')) {
            if (!preg_match("/VALUES \\(\\s*\\d+\\s*,\\s*'[^']*'\\s*,\\s*'((?:\\\\.|[^'])*)'\\s*,\\s*'([^']+)'\\s*,\\s*'([^']+)'/s", $sql, $match)) {
                throw new \RuntimeException('Cache fixture could not parse stored JSON.');
            }
            $this->cacheRow = ['shop_data' => stripcslashes($match[1]), 'fetched_at' => $match[2], 'expires_at' => $match[3]];
            $this->affected = 1;
        }
        return $this->result(null);
    }

    private function result(?array $row): object
    {
        return (object) ['num_rows' => $row === null ? 0 : 1, 'row' => $row ?? [], 'rows' => $row === null ? [] : [$row]];
    }

    public function escape(string $value): string { return addslashes($value); }
    public function countAffected(): int { return $this->affected; }
    public function getLastId(): int { return 1; }
    public function getPrefix(): string { return 'fixture_'; }
}
