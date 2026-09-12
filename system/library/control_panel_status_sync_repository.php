<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * SQL CAS store for durable CP status sync columns on financing_attempt.
 */
final class ControlPanelStatusSyncRepository implements ControlPanelStatusSyncStoreInterface
{
    public function __construct(
        private DbConnection $db,
        private ?PersistenceClock $clock = null
    ) {
        $this->clock ??= new PersistenceClock();
    }

    public function findByAttempt(int $attemptId): ?array
    {
        if ($attemptId <= 0) {
            return null;
        }

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT `attempt_id`,
                    `cp_status_sync_state`,
                    `cp_status_sync_status_id`,
                    `cp_status_sync_status`,
                    `cp_status_sync_error_class`,
                    `cp_status_sync_updated_at`
             FROM `{$table}`
             WHERE `attempt_id` = " . (int) $attemptId . ' LIMIT 1'
        );

        return is_object($result) && $result->num_rows === 1 ? $result->row : null;
    }

    public function compareAndSetPendingTarget(
        int $attemptId,
        string $expectedState,
        ?string $expectedStatusId,
        ?string $expectedStatus,
        string $newStatusId,
        string $newStatus
    ): bool {
        $now = $this->now();
        $table = $this->tableName();
        $this->db->query(
            "UPDATE `{$table}` SET
                `cp_status_sync_state` = '" . $this->db->escape(ControlPanelStatusSyncStates::PENDING) . "',
                `cp_status_sync_status_id` = '" . $this->db->escape($newStatusId) . "',
                `cp_status_sync_status` = '" . $this->db->escape($newStatus) . "',
                `cp_status_sync_error_class` = NULL,
                `cp_status_sync_updated_at` = '" . $this->db->escape($now) . "',
                `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND `cp_status_sync_state` = '" . $this->db->escape($expectedState) . "'
               AND " . $this->nullSafeEqualsSql('cp_status_sync_status_id', $expectedStatusId) . '
               AND ' . $this->nullSafeEqualsSql('cp_status_sync_status', $expectedStatus)
        );

        return $this->db->countAffected() > 0;
    }

    public function compareAndSetConfirmed(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus
    ): bool {
        $now = $this->now();
        $table = $this->tableName();
        $this->db->query(
            "UPDATE `{$table}` SET
                `cp_status_sync_state` = '" . $this->db->escape(ControlPanelStatusSyncStates::CONFIRMED) . "',
                `cp_status_sync_status_id` = '" . $this->db->escape($expectedStatusId) . "',
                `cp_status_sync_status` = '" . $this->db->escape($expectedStatus) . "',
                `cp_status_sync_error_class` = NULL,
                `cp_status_sync_updated_at` = '" . $this->db->escape($now) . "',
                `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND `cp_status_sync_state` = '" . $this->db->escape(ControlPanelStatusSyncStates::PENDING) . "'
               AND `cp_status_sync_status_id` = '" . $this->db->escape($expectedStatusId) . "'
               AND `cp_status_sync_status` = '" . $this->db->escape($expectedStatus) . "'"
        );

        return $this->db->countAffected() > 0;
    }

    public function compareAndSetFailure(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus,
        string $newState,
        string $errorClass
    ): bool {
        if (!in_array($newState, [ControlPanelStatusSyncStates::PENDING, ControlPanelStatusSyncStates::TERMINAL_FAILED], true)) {
            return false;
        }

        $now = $this->now();
        $table = $this->tableName();
        $this->db->query(
            "UPDATE `{$table}` SET
                `cp_status_sync_state` = '" . $this->db->escape($newState) . "',
                `cp_status_sync_error_class` = '" . $this->db->escape($errorClass) . "',
                `cp_status_sync_updated_at` = '" . $this->db->escape($now) . "',
                `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND `cp_status_sync_state` = '" . $this->db->escape(ControlPanelStatusSyncStates::PENDING) . "'
               AND `cp_status_sync_status_id` = '" . $this->db->escape($expectedStatusId) . "'
               AND `cp_status_sync_status` = '" . $this->db->escape($expectedStatus) . "'"
        );

        return $this->db->countAffected() > 0;
    }

    private function nullSafeEqualsSql(string $column, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '(`' . $column . '` IS NULL OR `' . $column . "` = '')";
        }

        return '`' . $column . "` <=> '" . $this->db->escape($value) . "'";
    }

    private function now(): string
    {
        return $this->clock->formatUtc($this->clock->now());
    }

    private function tableName(): string
    {
        return $this->db->getPrefix() . PersistenceTableNames::FINANCING_ATTEMPT;
    }
}
