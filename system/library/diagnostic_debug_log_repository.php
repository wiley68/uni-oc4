<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Safe diagnostic journal for CP debug retrieval (Phase 11 writers populate rows).
 *
 * Bridge A: table may be empty; endpoint returns structured 404 — never secrets/PII.
 */
final class DiagnosticDebugLogRepository
{
    private DbConnection $db;

    public function __construct(DbConnection $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<string, mixed>|null Redacted diagnostic payload
     */
    public function findLatestByOrderId(int $storeId, int $orderId): ?array
    {
        OpenCartStoreScope::require($storeId);
        if ($orderId <= 0) {
            return null;
        }

        $table = $this->db->getPrefix() . PersistenceTableNames::DIAGNOSTIC_DEBUG_LOG;
        try {
            $result = $this->db->query(
                "SELECT `order_id`, `entry_point`, `event_code`, `http_status`, `summary_json`, `created_at`
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

        $row = $result->row;
        $summary = [];
        if (!empty($row['summary_json']) && is_string($row['summary_json'])) {
            try {
                $decoded = json_decode($row['summary_json'], true, 512, JSON_THROW_ON_ERROR);
                $summary = is_array($decoded) ? DiagnosticPayloadRedactor::redact($decoded) : [];
            } catch (\JsonException $exception) {
                $summary = [];
            }
        }

        return [
            'order_id' => (int) $row['order_id'],
            'entry_point' => (string) ($row['entry_point'] ?? ''),
            'event_code' => (string) ($row['event_code'] ?? ''),
            'http_status' => isset($row['http_status']) ? (int) $row['http_status'] : null,
            'summary' => $summary,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
