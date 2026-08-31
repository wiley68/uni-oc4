<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Safe diagnostic journal for CP debug retrieval and local Admin export.
 */
class DiagnosticDebugLogRepository
{
    public const RETENTION_MONTHS = 3;

    private DbConnection $db;

    private PersistenceClock $clock;

    public function __construct(DbConnection $db, ?PersistenceClock $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?? new PersistenceClock();
    }

    /**
     * @param array<string, mixed> $summary Already sanitized summary payload.
     */
    public function insert(
        int $storeId,
        int $orderId,
        string $entryPoint,
        string $eventCode,
        int $httpStatus,
        array $summary
    ): bool {
        OpenCartStoreScope::require($storeId);
        if ($orderId <= 0) {
            return false;
        }

        $this->pruneOld();

        $table = $this->db->getPrefix() . PersistenceTableNames::DIAGNOSTIC_DEBUG_LOG;
        $createdAt = $this->clock->formatUtc($this->clock->now());
        try {
            $summaryJson = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return false;
        }

        try {
            $this->db->query(
                "INSERT INTO `{$table}`
                    (`store_id`, `order_id`, `entry_point`, `event_code`, `http_status`, `summary_json`, `created_at`)
                 VALUES (
                    " . (int) $storeId . ",
                    " . (int) $orderId . ",
                    '" . $this->db->escape(trim($entryPoint)) . "',
                    '" . $this->db->escape(trim($eventCode)) . "',
                    " . ($httpStatus > 0 ? (int) $httpStatus : 'NULL') . ",
                    '" . $this->db->escape($summaryJson) . "',
                    '" . $this->db->escape($createdAt) . "'
                 )"
            );
        } catch (\Throwable $exception) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null Redacted diagnostic payload for CP bridge.
     */
    public function findLatestByOrderId(int $storeId, int $orderId): ?array
    {
        OpenCartStoreScope::require($storeId);
        if ($orderId <= 0) {
            return null;
        }

        $row = $this->fetchLatestRow($storeId, $orderId);
        if ($row === null) {
            return null;
        }

        return $this->formatCpPayload($row);
    }

    /** @return list<array<string, mixed>> */
    public function findAllForStore(int $storeId): array
    {
        OpenCartStoreScope::require($storeId);
        $this->pruneOld();

        $table = $this->db->getPrefix() . PersistenceTableNames::DIAGNOSTIC_DEBUG_LOG;
        try {
            $result = $this->db->query(
                "SELECT `diagnostic_debug_log_id`, `store_id`, `order_id`, `entry_point`, `event_code`,
                        `http_status`, `summary_json`, `created_at`
                 FROM `{$table}`
                 WHERE `store_id` = " . (int) $storeId . "
                 ORDER BY `diagnostic_debug_log_id` ASC"
            );
        } catch (\Throwable $exception) {
            return [];
        }

        if (!is_object($result) || $result->num_rows < 1) {
            return [];
        }

        $entries = [];
        foreach ($result->rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entries[] = $this->formatExportEntry($row);
        }

        return $entries;
    }

    public function countForStore(int $storeId): int
    {
        OpenCartStoreScope::require($storeId);
        $table = $this->db->getPrefix() . PersistenceTableNames::DIAGNOSTIC_DEBUG_LOG;
        try {
            $result = $this->db->query(
                "SELECT COUNT(*) AS `total`
                 FROM `{$table}`
                 WHERE `store_id` = " . (int) $storeId
            );
        } catch (\Throwable $exception) {
            return 0;
        }

        if (!is_object($result) || $result->num_rows !== 1) {
            return 0;
        }

        return (int) ($result->row['total'] ?? 0);
    }

    public function pruneOld(?\DateTimeImmutable $now = null): bool
    {
        $cutoff = self::retentionCutoff($now);
        $table = $this->db->getPrefix() . PersistenceTableNames::DIAGNOSTIC_DEBUG_LOG;
        try {
            $this->db->query(
                "DELETE FROM `{$table}` WHERE `created_at` < '" . $this->db->escape($cutoff) . "'"
            );
        } catch (\Throwable $exception) {
            return false;
        }

        return true;
    }

    public static function retentionCutoff(?\DateTimeImmutable $now = null): string
    {
        return ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . self::RETENTION_MONTHS . ' months')
            ->format('Y-m-d H:i:s');
    }

    /** @return array<string, mixed>|null */
    private function fetchLatestRow(int $storeId, int $orderId): ?array
    {
        $this->pruneOld();
        $table = $this->db->getPrefix() . PersistenceTableNames::DIAGNOSTIC_DEBUG_LOG;
        try {
            $result = $this->db->query(
                "SELECT `diagnostic_debug_log_id`, `order_id`, `entry_point`, `event_code`, `http_status`,
                        `summary_json`, `created_at`
                 FROM `{$table}`
                 WHERE `store_id` = " . (int) $storeId . "
                   AND `order_id` = " . (int) $orderId . "
                 ORDER BY `diagnostic_debug_log_id` DESC
                 LIMIT 1"
            );
        } catch (\Throwable $exception) {
            return null;
        }

        if (!is_object($result) || $result->num_rows !== 1) {
            return null;
        }

        return is_array($result->row) ? $result->row : null;
    }

    /** @param array<string, mixed> $row */
    private function formatCpPayload(array $row): array
    {
        $summary = $this->decodeSummary((string) ($row['summary_json'] ?? ''));
        $httpStatus = isset($row['http_status']) ? (int) $row['http_status'] : 0;
        $createdAt = (string) ($row['created_at'] ?? '');

        return [
            'order_id' => (int) ($row['order_id'] ?? 0),
            'entry_point' => (string) ($row['entry_point'] ?? ''),
            'event_code' => (string) ($row['event_code'] ?? ''),
            'http_status' => $httpStatus > 0 ? $httpStatus : null,
            'http_code' => $httpStatus > 0 ? $httpStatus : null,
            'operation' => (string) ($summary['operation'] ?? SmartUcfDiagnosticJournal::OPERATION_SESSION_START),
            'endpoint' => $summary['endpoint'] ?? null,
            'outcome' => (string) ($summary['outcome'] ?? ($row['event_code'] ?? '')),
            'request' => $summary['request'] ?? null,
            'response' => $summary['response'] ?? null,
            'transport_error' => $summary['transport_error'] ?? null,
            'summary' => $summary,
            'created_at' => $createdAt,
            'created_at_gmt' => $createdAt,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatExportEntry(array $row): array
    {
        $summary = $this->decodeSummary((string) ($row['summary_json'] ?? ''));
        $httpStatus = isset($row['http_status']) ? (int) $row['http_status'] : 0;

        return [
            'id' => (int) ($row['diagnostic_debug_log_id'] ?? 0),
            'store_id' => (int) ($row['store_id'] ?? 0),
            'order_id' => (int) ($row['order_id'] ?? 0),
            'entry_point' => (string) ($row['entry_point'] ?? ''),
            'event_code' => (string) ($row['event_code'] ?? ''),
            'http_code' => $httpStatus,
            'operation' => (string) ($summary['operation'] ?? SmartUcfDiagnosticJournal::OPERATION_SESSION_START),
            'endpoint' => $summary['endpoint'] ?? null,
            'outcome' => (string) ($summary['outcome'] ?? ($row['event_code'] ?? '')),
            'request' => $summary['request'] ?? null,
            'response' => $summary['response'] ?? null,
            'transport_error' => $summary['transport_error'] ?? null,
            'created_at_gmt' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function decodeSummary(string $json): array
    {
        if ($json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? DiagnosticPayloadRedactor::redact($decoded) : [];
        } catch (\JsonException $exception) {
            return [];
        }
    }
}
