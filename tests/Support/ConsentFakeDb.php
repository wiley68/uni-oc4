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
    public array $lockExpiry = [];
    public ?\Closure $beforeUpdate = null;
    public ?\Closure $beforeDelete = null;
    public bool $loseCommitAck = false;
    public int $evidenceWrites = 0;
    public array $evidenceCasAffected = [];
    public int $lastId = 0;
    public int $payloadWrites = 0;
    public ?\Closure $beforePayloadWrite = null;
    public bool $losePayloadAck = false;
    public bool $simulateCpLifecycle = false;
    public bool $simulateSmartUcfLifecycle = false;
    public array $bankStatuses = [];
    public ?\Closure $beforeCpClaim = null;
    public ?\Closure $beforeCpClaimConfirmation = null;
    public bool $failCpClaim = false;
    public string $cpClaimWriteFault = '';
    public string $cpDispositionWriteFault = '';
    public string $authorizationWriteFault = '';
    public string $holdWriteFault = '';
    public int $authorizationWrites = 0;
    public int $holdWrites = 0;
    public bool $failPayloadReadBack = false;
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
        if ($this->simulateCpLifecycle && str_contains($sql, 'AND EXISTS (SELECT 1 FROM `consent_mt_uni_credit_operation_lock`')) {
            return $this->checkCpSnapshot($sql);
        }
        if ($this->simulateCpLifecycle && str_starts_with($sql, 'UPDATE')
            && str_contains($sql, 'AND BINARY `last_error_class` <=>')
            && str_contains($sql, 'SET `state` = ')) {
            return $this->checkCpSnapshot($sql, true);
        }
        if ($this->simulateSmartUcfLifecycle && str_contains($sql, '`consent_mt_uni_credit_order_bank_status`')) {
            if (preg_match("/VALUES\\s*\\(\\s*(\\d+),\\s*(\\d+),\\s*'([^']*)',\\s*'([^']*)',\\s*'([^']*)'/s", $sql, $m)) {
                [, $store, $order, $reference, $statusId, $label] = $m;
                $this->bankStatuses[$store][$order] = ['order_id' => (int) $order, 'order_reference' => $reference, 'status_id' => $statusId, 'status_label' => $label];
                $this->affected = 1;
            } elseif (preg_match('/WHERE `store_id` = (\d+)\s+AND `order_id` = (\d+)/', $sql, $m)) {
                $row = $this->bankStatuses[$m[1]][$m[2]] ?? null;

                return $this->result($row === null ? [] : [$row]);
            }
            return $this->result([]);
        }
        if (str_contains($sql, 'operation_lock')) {
            if (str_starts_with($sql, 'INSERT IGNORE')) {
                preg_match('/VALUES\s*\(\s*(\d+),\s*\x27([^\x27]+)\x27,\s*\x27([^\x27]+)\x27,\s*\x27([^\x27]+)\x27/s', $sql, $m);
                $key = implode('|', array_slice($m, 1, 3));
                if (!isset($this->locks[$key])) {
                    $this->locks[$key] = $m[4];
                    preg_match("~'([^']+)'\\s*,\\s*'[^']+'\\s*,\\s*'[^']+'\\s*\\)\\s*$~", $sql, $expiry);
                    $this->lockExpiry[$key] = $expiry[1];
                    $this->affected = 1;
                }
            } elseif (str_starts_with($sql, 'DELETE') && preg_match('/`owner_token` = \'([^\']+)\'/', $sql, $m)) {
                foreach ($this->locks as $key => $owner) if ($owner === $m[1]) { unset($this->locks[$key], $this->lockExpiry[$key]); $this->affected = 1; }
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
            if ($this->failPayloadReadBack && $this->payloadWrites > 0) {
                $this->failPayloadReadBack = false;
                throw new \RuntimeException('CP payload read-back failed');
            }
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
        if (preg_match('/WHERE `attempt_id` = (\d+)/', $sql, $attempt) && str_starts_with($sql, 'UPDATE')) {
            $id = (int) $attempt[1];
            if (str_contains($sql, 'SET `cp_payload`')) {
                ++$this->payloadWrites;
                if ($this->beforePayloadWrite !== null) {
                    ($this->beforePayloadWrite)($this, $id);
                }
                if (!str_contains($sql, 'AND `cp_payload` IS NULL')
                    || !str_contains($sql, "AND `state` = 'order_created'")
                    || !str_contains($sql, 'AND `control_panel_order_id` IS NULL')) {
                    throw new \LogicException('Missing CP payload first-write predicate');
                }
                $authorization = self::sqlString($sql, 'AND BINARY `last_error_class` = ');
                $row = $this->rows[$id] ?? [];
                if (($row['state'] ?? null) === 'order_created' && ($row['cp_payload'] ?? null) === null
                    && ($row['control_panel_order_id'] ?? null) === null && ($row['last_error_class'] ?? null) === $authorization) {
                    preg_match("~SET `cp_payload` = '(.*)',~", $sql, $m);
                    $this->rows[$id]['cp_payload'] = stripcslashes($m[1]);
                    $this->affected = 1;
                }
                if ($this->losePayloadAck) {
                    throw new \RuntimeException('Payload write acknowledgement lost');
                }
                return $this->result([]);
            }
            if ($this->simulateCpLifecycle && isset($this->rows[$id])) {
                if (str_contains($sql, "`last_error_class` = 'cp_conflict',") && str_contains($sql, "SET `state` = 'cp_outcome_unknown'")) {
                    ++$this->holdWrites;
                    if ($this->holdWriteFault === 'throw') {
                        throw new \RuntimeException('CP hold write failed');
                    }
                    if ($this->holdWriteFault === 'zero') {
                        return $this->result([]);
                    }
                    $row = $this->rows[$id];
                    if (!preg_match('/AND `store_id` = (\d+) AND `order_id` = (\d+)/', $sql, $identity)
                        || !str_contains($sql, 'AND `control_panel_order_id` IS NULL')) {
                        throw new \LogicException('Missing conflict identity predicates');
                    }
                    if ((int) $row['store_id'] === (int) $identity[1] && (int) $row['order_id'] === (int) $identity[2]
                        && ($row['control_panel_order_id'] ?? null) === null
                        && in_array($row['state'], ['order_created', 'cp_failed_retryable'], true)) {
                        $this->rows[$id]['state'] = 'cp_outcome_unknown';
                        $this->rows[$id]['last_error_class'] = 'cp_conflict';
                        $this->affected = 1;
                        if ($this->holdWriteFault === 'lost-ack') {
                            throw new \RuntimeException('CP hold acknowledgement lost');
                        }
                    }
                    return $this->result([]);
                }
                if (preg_match("/SET `state` = '([^']+)'/", $sql, $m)
                    && str_contains($sql, "'" . $this->rows[$id]['state'] . "'")) {
                    $this->rows[$id]['state'] = $m[1];
                    $this->affected = 1;
                } elseif (preg_match('/SET `control_panel_order_id` = (\d+)/', $sql, $m)) {
                    $this->rows[$id]['control_panel_order_id'] = (int) $m[1];
                    $this->affected = 1;
                }
            }
            if ($this->simulateSmartUcfLifecycle && isset($this->rows[$id])) {
                $this->updateSmartUcfLifecycle($id, $sql);
            }
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

    private function updateSmartUcfLifecycle(int $id, string $sql): void
    {
        [$assignments, $conditions] = explode(' WHERE ', preg_replace('/\s+WHERE /', ' WHERE ', $sql, 1), 2);
        $row = &$this->rows[$id];
        if (str_contains($assignments, "`smartucf_state` = 'submitting'")) {
            if ($row['smartucf_state'] === self::sqlString($conditions, "`smartucf_state` = ")) {
                $row['smartucf_state'] = 'submitting';
                $row['smartucf_claimed_at'] = self::sqlString($assignments, "`smartucf_claimed_at` = ");
                $this->affected = 1;
            }
        } elseif (str_contains($assignments, "`smartucf_state` = 'created'")) {
            if (str_contains($conditions, "`smartucf_state` IN ('" . $row['smartucf_state'] . "')")) {
                $row['smartucf_state'] = 'created';
                $row['smartucf_session_id'] = self::sqlString($assignments, "`smartucf_session_id` = ");
                $row['smartucf_redirect_url'] = self::sqlString($assignments, "`smartucf_redirect_url` = ");
                $this->affected = 1;
            }
        } elseif (str_contains($assignments, '`cp_status_sync_state` = ')) {
            $expected = self::sqlString($conditions, "`cp_status_sync_state` = ");
            if ($row['cp_status_sync_state'] === $expected) {
                $row['cp_status_sync_state'] = self::sqlString($assignments, "`cp_status_sync_state` = ");
                $row['cp_status_sync_status_id'] = self::sqlString($assignments, "`cp_status_sync_status_id` = ");
                $row['cp_status_sync_status'] = self::sqlString($assignments, "`cp_status_sync_status` = ");
                $this->affected = 1;
            }
        }
    }

    private function checkCpSnapshot(string $sql, bool $disposition = false): object
    {
        preg_match('/WHERE `attempt_id` = (\d+) AND `store_id` = (\d+) AND `order_id` = (\d+)/', $sql, $identity);
        [, $id, $store, $order] = $identity;
        $write = str_starts_with($sql, 'UPDATE');
        $reserve = $write && str_contains($sql, 'SET `last_error_class` = ');
        if ($write && !$reserve && !$disposition && $this->beforeCpClaim !== null) {
            ($this->beforeCpClaim)($this, (int) $id);
        }
        if (!$write && $this->beforeCpClaimConfirmation !== null) {
            ($this->beforeCpClaimConfirmation)($this, (int) $id);
        }
        if ($write && !$reserve && !$disposition && $this->failCpClaim) {
            return $this->result([]);
        }
        $row = $this->rows[$id] ?? [];
        $matches = (int) ($row['store_id'] ?? -1) === (int) $store && (int) ($row['order_id'] ?? -1) === (int) $order
            && ($row['control_panel_order_id'] ?? null) === null;
        if (!str_contains($sql, 'AND `control_panel_order_id` IS NULL')
            || !preg_match("~AND BINARY `last_error_class` <=> (NULL|'[^']*')~", $sql, $error)) {
            throw new \LogicException('Missing CP identity or error condition');
        }
        $matches = $matches && ($row['last_error_class'] ?? null) === ($error[1] === 'NULL' ? null : trim($error[1], "'"));
        foreach (['entry_point', 'operation_key_hash', 'cp_payload', 'cp_origin', 'consent_context_json', 'state'] as $key) {
            $binary = in_array($key, ['cp_payload', 'cp_origin', 'consent_context_json'], true) ? 'BINARY ' : '';
            $expected = $key === 'cp_payload' && str_contains($sql, 'AND BINARY `cp_payload` IS NULL')
                ? null : self::sqlString(substr($sql, strpos($sql, 'WHERE')), $binary . "`{$key}` = ");
            $matches = $matches && ($row[$key] ?? null) === $expected;
        }
        if (!$disposition) {
            $lease = implode('|', [$store, $row['entry_point'] ?? '', $row['operation_key_hash'] ?? '']);
            $owner = self::sqlString($sql, '`owner_token` = ');
            $at = self::sqlString($sql, '`expires_at` > ');
            $matches = $matches && ($this->locks[$lease] ?? null) === $owner && ($this->lockExpiry[$lease] ?? '') > $at;
        }
        if (!$matches) {
            return $this->result([]);
        }
        if ($disposition) {
            return $this->writeCpDisposition((int) $id, substr($sql, 0, strpos($sql, 'WHERE')));
        }
        if ($reserve) {
            ++$this->authorizationWrites;
            if ($this->authorizationWriteFault === 'throw') {
                throw new \RuntimeException('CP authorization write failed');
            }
            if ($this->authorizationWriteFault !== 'zero') {
                $this->rows[$id]['last_error_class'] = self::sqlString($sql, 'SET `last_error_class` = ');
                $this->affected = 1;
            }
            if ($this->authorizationWriteFault === 'lost-ack') {
                throw new \RuntimeException('CP authorization acknowledgement lost');
            }
            return $this->result([]);
        }
        if ($write) {
            if ($this->cpClaimWriteFault === 'throw') {
                throw new \RuntimeException('CP dispatch claim write failed');
            }
            $this->rows[$id]['state'] = self::sqlString($sql, 'SET `state` = ');
            $this->affected = 1;
            if ($this->cpClaimWriteFault === 'lost-ack') {
                throw new \RuntimeException('CP dispatch claim acknowledgement lost');
            }

            return $this->result([]);
        }

        return $this->result([$row]);
    }

    private function writeCpDisposition(int $id, string $assignments): object
    {
        if ($this->cpDispositionWriteFault === 'throw') {
            throw new \RuntimeException('CP disposition write failed');
        }
        if ($this->cpDispositionWriteFault === 'zero') {
            return $this->result([]);
        }

        if (!preg_match('/`control_panel_order_id` = (NULL|\d+),/', $assignments, $order)) {
            throw new \LogicException('Missing CP disposition order identity assignment');
        }
        $this->rows[$id]['state'] = self::sqlString($assignments, 'SET `state` = ');
        $this->rows[$id]['last_error_class'] = str_contains($assignments, '`last_error_class` = NULL,')
            ? null : self::sqlString($assignments, '`last_error_class` = ');
        $this->rows[$id]['control_panel_order_id'] = $order[1] === 'NULL' ? null : (int) $order[1];
        $this->affected = 1;
        if ($this->cpDispositionWriteFault === 'lost-ack') {
            throw new \RuntimeException('CP disposition acknowledgement lost');
        }

        return $this->result([]);
    }

    private static function sqlString(string $sql, string $prefix): string
    {
        if (!preg_match("~" . preg_quote($prefix, '~') . "'((?:\\\\.|[^'\\\\])*)'~s", $sql, $match)) {
            throw new \LogicException('Missing SQL predicate ' . $prefix);
        }

        return stripcslashes($match[1]);
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
