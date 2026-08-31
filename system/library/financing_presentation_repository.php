<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Persists / loads frozen leasing presentation JSON on financing_attempt.
 *
 * Presentation retention uses {@see SecurityConstants::PRESENTATION_RETENTION_DAYS} from
 * attempt {@see created_at} — the moment the financing attempt row was issued, not
 * {@see updated_at}, so later bank/status transitions do not extend snapshot retention.
 */
final class FinancingPresentationRepository
{
    public function __construct(
        private DbConnection $db,
        private ?PersistenceClock $clock = null
    ) {
        $this->clock ??= new PersistenceClock();
    }

    public function persist(int $attemptId, FinancingPresentationSnapshot $snapshot): void
    {
        $json = json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $table = $this->table();
        $this->db->query(
            "UPDATE `{$table}`
             SET `leasing_presentation_json` = '" . $this->db->escape($json) . "'
             WHERE `attempt_id` = " . (int) $attemptId
        );
        $this->redactExpiredPresentationBatch();
    }

    public function attachControlPanelOrderId(int $attemptId, int $controlPanelOrderId): void
    {
        $existing = $this->findByAttemptId($attemptId);
        if ($existing === null) {
            return;
        }
        $this->persist($attemptId, $existing->withControlPanelOrderId($controlPanelOrderId));
    }

    public function findByAttemptId(int $attemptId): ?FinancingPresentationSnapshot
    {
        $table = $this->table();
        $result = $this->db->query(
            "SELECT `leasing_presentation_json`
             FROM `{$table}`
             WHERE `attempt_id` = " . (int) $attemptId . "
             LIMIT 1"
        );
        if (!is_object($result) || $result->num_rows !== 1) {
            return null;
        }

        return $this->decode((string) ($result->row['leasing_presentation_json'] ?? ''));
    }

    public function findByOrderId(int $storeId, int $orderId): ?FinancingPresentationSnapshot
    {
        $attempt = (new FinancingAttemptRepository($this->db))->findByOrderId($storeId, $orderId);
        if ($attempt === null) {
            return null;
        }
        $decoded = $this->decode((string) ($attempt['leasing_presentation_json'] ?? ''));
        if ($decoded !== null) {
            return $decoded;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAttemptRowByOrderId(int $storeId, int $orderId): ?array
    {
        return (new FinancingAttemptRepository($this->db))->findByOrderId($storeId, $orderId);
    }

    /**
     * Retention: clear presentation JSON older than retention days (default ~6 months).
     * Uses attempt {@see created_at}; does not delete attempt rows or P2 ciphertext.
     */
    public function redactExpiredPresentationBatch(
        int $retentionDays = SecurityConstants::PRESENTATION_RETENTION_DAYS,
        int $limit = SecurityConstants::CLEANUP_DEFAULT_BATCH_SIZE
    ): int {
        $retentionDays = max(1, $retentionDays);
        $limit = max(1, min(500, $limit));
        $cutoff = gmdate('Y-m-d H:i:s', $this->clock->now() - ($retentionDays * 86400));
        $table = $this->table();
        $this->db->query(
            "UPDATE `{$table}`
             SET `leasing_presentation_json` = NULL
             WHERE `leasing_presentation_json` IS NOT NULL
               AND `created_at` < '" . $this->db->escape($cutoff) . "'
             LIMIT " . (int) $limit
        );

        return $this->db->countAffected();
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, string> order_id => status_label
     */
    public function batchBankStatusLabels(int $storeId, array $orderIds): array
    {
        $ids = [];
        foreach ($orderIds as $orderId) {
            $id = (int) $orderId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $table = $this->db->getPrefix() . PersistenceTableNames::ORDER_BANK_STATUS;
        $in = implode(',', $ids);
        $result = $this->db->query(
            "SELECT `order_id`, `status_label`
             FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `order_id` IN (" . $in . ")"
        );
        $map = [];
        if (is_object($result) && !empty($result->rows)) {
            foreach ($result->rows as $row) {
                $map[(int) $row['order_id']] = (string) ($row['status_label'] ?? '');
            }
        }

        return $map;
    }

    public function findBankStatusLabel(int $storeId, int $orderId): string
    {
        $map = $this->batchBankStatusLabels($storeId, [$orderId]);
        if (($map[$orderId] ?? '') !== '') {
            return $map[$orderId];
        }
        if ($storeId !== 0) {
            $map = $this->batchBankStatusLabels(0, [$orderId]);
        }

        return $map[$orderId] ?? '';
    }

    private function decode(string $json): ?FinancingPresentationSnapshot
    {
        if ($json === '') {
            return null;
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        return FinancingPresentationSnapshot::fromArray($data);
    }

    private function table(): string
    {
        return $this->db->getPrefix() . PersistenceTableNames::FINANCING_ATTEMPT;
    }
}
