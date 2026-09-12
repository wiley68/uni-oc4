<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Persists CP → module bank status callbacks scoped by store + local order.
 */
final class OrderBankStatusRepository
{
    private DbConnection $db;

    private PersistenceClock $clock;

    private FinancingOrderResolver $resolver;

    public function __construct(
        DbConnection $db,
        ?PersistenceClock $clock = null,
        ?FinancingOrderResolver $resolver = null
    ) {
        $this->db = $db;
        $this->clock = $clock ?? new PersistenceClock();
        $this->resolver = $resolver ?? new FinancingOrderResolver($db, $this->clock);
    }

    /**
     * @return array{order_id: string, oc_order_id: int, status: string, status_id: string, oc_order_state_changed: bool}|null
     */
    public function updateByOrderIdentifier(
        int $storeId,
        string $unicid,
        string $orderReference,
        string $statusId,
        string $statusLabel
    ): ?array {
        OpenCartStoreScope::require($storeId);
        $orderReference = trim($orderReference);
        $statusId = trim($statusId);
        $statusLabel = trim($statusLabel);
        if ($orderReference === '' || $statusId === '' || $statusLabel === '') {
            return null;
        }

        $resolved = $this->resolver->resolve($storeId, $unicid, $orderReference);
        if ($resolved === null) {
            return null;
        }

        $orderId = $resolved['order_id'];
        $current = $this->findCurrentStatus($storeId, $orderId);
        $currentStatusId = is_array($current) ? (string) ($current['status_id'] ?? '') : '';

        // Same-status replay is idempotent — still refresh label/timestamp when compatible.
        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();
        $p1 = $this->db->escape(BankStatus::SENT_PROCESS1);
        $p2 = $this->db->escape(BankStatus::SENT_PROCESS2);
        $conflictGuard = sprintf(
            "((`status_id` = '%s' AND VALUES(`status_id`) = '%s') OR (`status_id` = '%s' AND VALUES(`status_id`) = '%s'))",
            $p1,
            $p2,
            $p2,
            $p1
        );

        $this->db->query(
            "INSERT INTO `{$table}`
                (`store_id`, `order_id`, `order_reference`, `status_id`, `status_label`, `updated_at`)
             VALUES (
                " . (int) $storeId . ",
                " . (int) $orderId . ",
                '" . $this->db->escape($orderReference) . "',
                '" . $this->db->escape($statusId) . "',
                '" . $this->db->escape($statusLabel) . "',
                '" . $this->db->escape($updatedAt) . "'
             )
             ON DUPLICATE KEY UPDATE
                `order_reference` = IF({$conflictGuard}, `order_reference`, VALUES(`order_reference`)),
                `status_label` = IF({$conflictGuard}, `status_label`, VALUES(`status_label`)),
                `updated_at` = IF({$conflictGuard}, `updated_at`, VALUES(`updated_at`)),
                `status_id` = IF({$conflictGuard}, `status_id`, VALUES(`status_id`))"
        );

        $persisted = $this->findCurrentStatus($storeId, $orderId);
        if ($persisted === null) {
            throw new PersistenceException('The bank status could not be reloaded after write.');
        }

        $persistedStatusId = (string) ($persisted['status_id'] ?? '');
        if (
            $this->isIncompatibleTerminalSentPair($persistedStatusId, $statusId)
            && $persistedStatusId !== $statusId
        ) {
            throw new OrderBankStatusSemanticConflictException(
                'Incompatible terminal bank status progression.'
            );
        }

        // Detect conflict when pre-existing incompatible pair caused IF-guard to keep old row
        // and requested differs — covered by persisted check above.
        unset($currentStatusId);

        return [
            'order_id' => $orderReference,
            'oc_order_id' => $orderId,
            'status' => (string) ($persisted['status_label'] ?? $statusLabel),
            'status_id' => $persistedStatusId !== '' ? $persistedStatusId : $statusId,
            'oc_order_state_changed' => false,
        ];
    }

    /**
     * Local-only bank status write for proven module handoffs (already authorized by attempt id).
     *
     * @return array{order_id: string, oc_order_id: int, status: string, status_id: string}|null
     */
    public function upsertAuthorizedLocal(
        int $storeId,
        int $orderId,
        string $statusId,
        string $statusLabel
    ): ?array {
        OpenCartStoreScope::require($storeId);
        if ($orderId <= 0 || $statusId === '' || $statusLabel === '') {
            return null;
        }

        $orderReference = substr((string) $orderId, 0, 13);
        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();
        $p1 = $this->db->escape(BankStatus::SENT_PROCESS1);
        $p2 = $this->db->escape(BankStatus::SENT_PROCESS2);
        $conflictGuard = sprintf(
            "((`status_id` = '%s' AND VALUES(`status_id`) = '%s') OR (`status_id` = '%s' AND VALUES(`status_id`) = '%s'))",
            $p1,
            $p2,
            $p2,
            $p1
        );

        $this->db->query(
            "INSERT INTO `{$table}`
                (`store_id`, `order_id`, `order_reference`, `status_id`, `status_label`, `updated_at`)
             VALUES (
                " . (int) $storeId . ",
                " . (int) $orderId . ",
                '" . $this->db->escape($orderReference) . "',
                '" . $this->db->escape($statusId) . "',
                '" . $this->db->escape($statusLabel) . "',
                '" . $this->db->escape($updatedAt) . "'
             )
             ON DUPLICATE KEY UPDATE
                `order_reference` = IF({$conflictGuard}, `order_reference`, VALUES(`order_reference`)),
                `status_label` = IF({$conflictGuard}, `status_label`, VALUES(`status_label`)),
                `updated_at` = IF({$conflictGuard}, `updated_at`, VALUES(`updated_at`)),
                `status_id` = IF({$conflictGuard}, `status_id`, VALUES(`status_id`))"
        );

        $persisted = $this->findCurrentStatus($storeId, $orderId);
        if ($persisted === null) {
            return null;
        }

        $persistedStatusId = (string) ($persisted['status_id'] ?? '');
        if (
            $this->isIncompatibleTerminalSentPair($persistedStatusId, $statusId)
            && $persistedStatusId !== $statusId
        ) {
            throw new OrderBankStatusSemanticConflictException(
                'Incompatible terminal bank status progression.'
            );
        }

        return [
            'order_id' => $orderReference,
            'oc_order_id' => $orderId,
            'status' => (string) ($persisted['status_label'] ?? $statusLabel),
            'status_id' => $persistedStatusId !== '' ? $persistedStatusId : $statusId,
        ];
    }

    /** @return array<string, mixed>|null */
    public function findCurrentStatus(int $storeId, int $orderId): ?array
    {
        if (!OpenCartStoreScope::isValid($storeId) || $orderId <= 0) {
            return null;
        }

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT `order_id`, `order_reference`, `status_id`, `status_label`, `updated_at`
             FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `order_id` = " . (int) $orderId . '
             LIMIT 1'
        );

        return is_object($result) && $result->num_rows === 1 ? $result->row : null;
    }

    private function isIncompatibleTerminalSentPair(string $currentStatusId, string $newStatusId): bool
    {
        if ($currentStatusId === '') {
            return false;
        }

        $terminals = [BankStatus::SENT_PROCESS1, BankStatus::SENT_PROCESS2];

        return in_array($currentStatusId, $terminals, true)
            && in_array($newStatusId, $terminals, true)
            && $currentStatusId !== $newStatusId;
    }

    private function tableName(): string
    {
        return $this->db->getPrefix() . PersistenceTableNames::ORDER_BANK_STATUS;
    }
}
