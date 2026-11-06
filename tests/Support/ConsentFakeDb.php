<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\DbConnection;

/** Deterministic SQL/CAS fixture. It has no network or database driver. */
final class ConsentFakeDb implements DbConnection
{
    public array $rows = [];
    public array $queries = [];
    public array $orders = [];
    public array $orderLines = [];
    public array $externalCommittedRows = [];
    public array $locks = [];
    public ?\Closure $beforeUpdate = null;
    public ?\Closure $beforeDelete = null;
    public bool $loseCommitAck = false;
    public int $evidenceWrites = 0;
    public array $evidenceCasAffected = [];
    public int $lastId = 0;
    private int $affected = 0;
    private ?array $snapshot = null;

    public function query(string $sql): object
    {
        $this->queries[] = $sql;
        $this->affected = 0;
        $sql = trim($sql);
        if ($sql === 'START TRANSACTION') $this->snapshot = $this->rows;
        if ($sql === 'ROLLBACK' && $this->snapshot !== null) { $this->rows = $this->externalCommittedRows + $this->snapshot; $this->snapshot = null; }
        if ($sql === 'COMMIT') {
            $this->snapshot = null;
            if ($this->loseCommitAck) { $this->loseCommitAck = false; throw new \RuntimeException('Lost COMMIT acknowledgement'); }
        }
        if (str_starts_with($sql, 'SHOW COLUMNS')) return $this->result([['Field' => 'cp_origin', 'Type' => 'mediumtext', 'Null' => 'YES']]);
        if (str_contains($sql, 'operation_lock')) {
            if (str_starts_with($sql, 'INSERT IGNORE')) {
                preg_match('/VALUES\s*\(\s*(\d+),\s*\x27([^\x27]+)\x27,\s*\x27([^\x27]+)\x27,\s*\x27([^\x27]+)\x27/s', $sql, $m);
                $key = implode('|', array_slice($m, 1, 3));
                if (!isset($this->locks[$key])) { $this->locks[$key] = $m[4]; $this->affected = 1; }
            } elseif (str_starts_with($sql, 'DELETE') && preg_match('/`owner_token` = \'([^\']+)\'/', $sql, $m)) {
                foreach ($this->locks as $key => $owner) if ($owner === $m[1]) { unset($this->locks[$key]); $this->affected = 1; }
            }
            return $this->result([]);
        }
        if (str_starts_with($sql, 'INSERT INTO') && str_contains($sql, 'financing_attempt')) {
            preg_match('/\((.*?)\)\s*VALUES\s*\((.*)\)$/s', $sql, $m);
            preg_match_all('/`([^`]+)`/', $m[1], $columns);
            preg_match_all("/'((?:\\\\.|[^'\\\\])*)'|\\bNULL\\b|\\b[0-9]+\\b/s", $m[2], $values);
            $row = [];
            foreach ($columns[1] as $i => $column) {
                $value = $values[0][$i];
                $row[$column] = $value === 'NULL' ? null : ($value[0] === "'" ? stripcslashes(substr($value, 1, -1)) : (int) $value);
            }
            $row['attempt_id'] = ++$this->lastId;
            $row['order_id'] = null;
            $this->rows[$this->lastId] = $row;
            $this->affected = 1;
            return $this->result([]);
        }
        if (preg_match('/FROM `consent_order`.*?WHERE `order_id` = (\d+)/s', $sql, $m)) return $this->result(isset($this->orders[(int)$m[1]]) ? [$this->orders[(int)$m[1]]] : []);
        if (preg_match('/FROM `consent_order_option`.*?WHERE `order_id` = (\d+) AND `order_product_id` = (\d+)/s', $sql, $m)) {
            foreach ($this->orderLines[(int)$m[1]] ?? [] as $line) if ((int)($line['order_product_id'] ?? 0) === (int)$m[2]) return $this->result($line['option'] ?? []);
            return $this->result([]);
        }
        if (str_contains($sql, 'FROM `consent_currency`')) return $this->result([['code'=>'EUR']]);
        if (preg_match('/FROM `consent_order_product`.*?WHERE `order_id` = (\d+)/s', $sql, $m)) return $this->result($this->orderLines[(int)$m[1]] ?? []);
        if (str_starts_with($sql, 'SELECT') && str_contains($sql, 'financing_attempt')) {
            if (preg_match('/WHERE `attempt_id` = (\d+)/', $sql, $m)) return $this->result(isset($this->rows[(int) $m[1]]) ? [$this->rows[(int) $m[1]]] : []);
            $rows = array_values($this->rows);
            foreach (['store_id','entry_point','operation_key_hash','actor_binding_hash','state','submission_token'] as $column) {
                if (preg_match('/`' . $column . '` = (?:\x27([^\x27]*)\x27|(\d+))/', $sql, $m)) {
                    $value = $m[1] !== '' ? $m[1] : $m[2];
                    $rows = array_values(array_filter($rows, static fn(array $r): bool => (string) ($r[$column] ?? '') === $value));
                }
            }
            if (str_contains($sql, '`expires_at` <=')) {
                preg_match("/`expires_at` <= '([^']+)'/", $sql, $match);
                $rows = array_values(array_filter($rows, static fn(array $r): bool => empty($r['order_id']) && !empty($r['expires_at']) && $r['expires_at'] <= $match[1]));
            }
            return $this->result($rows);
        }
        if (str_starts_with($sql, 'UPDATE') && str_contains($sql, 'SET `consent_context_json`')) {
            if ($this->beforeUpdate !== null) { $callback = $this->beforeUpdate; $this->beforeUpdate = null; $callback($this); }
            preg_match('/WHERE `attempt_id` = (\d+)/', $sql, $m);
            $id = (int) $m[1];
            preg_match_all("/`consent_context_json`\\s*=\\s*(?:BINARY\\s*)?'((?:\\\\.|[^'\\\\])*)'/s", $sql, $m);
            [$new, $expected] = array_map('stripcslashes', $m[1]);
            $row = $this->rows[$id] ?? null;
            $matches = $row !== null && (int) $row['attempt_id'] === $id
                && $row['consent_context_json'] === $expected && $row['order_id'] === null;
            if (!preg_match('/AND `store_id` = (\d+)/', $sql, $store)
                || !str_contains($sql, "AND `state` IN ('issued', 'validating')")
                || !str_contains($sql, 'AND `order_id` IS NULL')) throw new \LogicException('Incomplete consent CAS predicates.');
            $matches = $matches && (int) $row['store_id'] === (int) $store[1] && in_array($row['state'], ['issued','validating'], true);
            foreach (['submission_token','actor_binding_hash','operation_key_hash','selection_hash','entry_point','unicid','created_at','expires_at'] as $key) {
                if (!preg_match("/AND BINARY `{$key}` = BINARY '((?:\\\\.|[^'\\\\])*)'/s", $sql, $binding)) throw new \LogicException('Missing consent binding predicate: ' . $key);
                $matches = $matches && ($row[$key] ?? null) === stripcslashes($binding[1]);
            }
            if ($matches) {
                $this->rows[$id]['consent_context_json'] = $new;
                ++$this->evidenceWrites;
                $this->affected = 1;
            }
            $this->evidenceCasAffected[] = $this->affected;
        }
        if (str_starts_with($sql, 'DELETE FROM') && str_contains($sql, 'financing_attempt')) {
            if ($this->beforeDelete !== null) { $callback = $this->beforeDelete; $this->beforeDelete = null; $callback($this); }
            preg_match('/`attempt_id` = (\d+)/', $sql, $m); $id = (int) $m[1];
            $expected = null;
            if (preg_match("/BINARY `consent_context_json` = BINARY '((?:\\\\.|[^'\\\\])*)'/s", $sql, $m)) $expected = stripcslashes($m[1]);
            if (isset($this->rows[$id]) && $this->rows[$id]['order_id'] === null && $this->rows[$id]['consent_context_json'] === $expected) { unset($this->rows[$id]); $this->affected = 1; }
        }
        return $this->result([]);
    }

    public function effectQueries(): array
    {
        return array_values(array_filter($this->queries, static fn(string $q): bool => preg_match('/^\s*(UPDATE|INSERT|DELETE)/i', $q) === 1 && !str_contains($q, 'operation_lock')));
    }
    private function result(array $rows): object { return (object) ['row' => $rows[0] ?? [], 'rows' => $rows, 'num_rows' => count($rows)]; }
    public function escape(string $v): string { return addslashes($v); }
    public function countAffected(): int { return $this->affected; }
    public function getLastId(): int { return $this->lastId; }
    public function getPrefix(): string { return 'consent_'; }
}
