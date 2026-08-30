<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class ProcessTwoLifecycleRepository
{
    public function __construct(private DbConnection $db, private ?PersistenceClock $clock = null)
    {
        $this->clock ??= new PersistenceClock();
    }

    public function persistSensitiveEncrypted(int $attemptId, string $encryptedPayload): void
    {
        if ($attemptId <= 0 || $encryptedPayload === '') {
            throw new PersistenceValidationException('Process 2 sensitive payload could not be stored.');
        }
        $now = $this->now();
        $this->db->query(
            "UPDATE `{$this->tableName()}`
             SET `process2_sensitive_enc` = '" . $this->db->escape($encryptedPayload) . "',
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId
        );
        if ($this->db->countAffected() !== 1) {
            throw new PersistenceException('Process 2 sensitive payload update failed.');
        }
    }

    /** @return array<string, mixed>|null */
    public function findByAttempt(int $attemptId): ?array
    {
        if ($attemptId <= 0) {
            return null;
        }
        $result = $this->db->query(
            "SELECT `attempt_id`, `process2_state`, `process2_sensitive_enc`, `process2_mail_sent`,
                    `store_id`, `order_id`, `control_panel_order_id`, `state`
             FROM `{$this->tableName()}`
             WHERE `attempt_id` = " . (int) $attemptId . ' LIMIT 1'
        );

        return is_object($result) && $result->num_rows === 1 ? $result->row : null;
    }

    public function claimPreparing(int $attemptId): bool
    {
        $now = $this->now();
        $this->db->query(
            "UPDATE `{$this->tableName()}`
             SET `process2_state` = '" . ProcessTwoLifecycleStates::PREPARING . "',
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND `process2_state` IN (
                    '" . ProcessTwoLifecycleStates::NOT_STARTED . "',
                    '" . ProcessTwoLifecycleStates::FAILED . "'
               )"
        );

        return $this->db->countAffected() === 1;
    }

    public function markPrepared(int $attemptId): void
    {
        $now = $this->now();
        $this->db->query(
            "UPDATE `{$this->tableName()}`
             SET `process2_state` = '" . ProcessTwoLifecycleStates::PREPARED . "',
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND `process2_state` IN (
                    '" . ProcessTwoLifecycleStates::PREPARING . "',
                    '" . ProcessTwoLifecycleStates::PREPARED . "'
               )"
        );
        if ($this->db->countAffected() < 1) {
            $row = $this->findByAttempt($attemptId);
            if ($row !== null && (string) ($row['process2_state'] ?? '') === ProcessTwoLifecycleStates::PREPARED) {
                return;
            }
            throw new PersistenceException('Process 2 prepared transition failed.');
        }
    }

    public function markFailed(int $attemptId): void
    {
        $now = $this->now();
        $this->db->query(
            "UPDATE `{$this->tableName()}`
             SET `process2_state` = '" . ProcessTwoLifecycleStates::FAILED . "',
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND `process2_state` = '" . ProcessTwoLifecycleStates::PREPARING . "'"
        );
    }

    public function isMailSent(int $attemptId): bool
    {
        $row = $this->findByAttempt($attemptId);

        return $row !== null && !empty($row['process2_mail_sent']);
    }

    public function markMailSent(int $attemptId): void
    {
        $now = $this->now();
        $this->db->query(
            "UPDATE `{$this->tableName()}`
             SET `process2_mail_sent` = 1,
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId
        );
    }

    /**
     * Retention: clear encrypted Process 2 PII older than retention days (default 180).
     */
    public function redactExpiredSensitiveBatch(int $retentionDays = 180, int $limit = 100): int
    {
        $retentionDays = max(1, $retentionDays);
        $limit = max(1, min(500, $limit));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));
        $this->db->query(
            "UPDATE `{$this->tableName()}`
             SET `process2_sensitive_enc` = NULL,
                 `updated_at` = '" . $this->db->escape($this->now()) . "'
             WHERE `process2_sensitive_enc` IS NOT NULL
               AND `updated_at` < '" . $this->db->escape($cutoff) . "'
             LIMIT " . (int) $limit
        );

        return $this->db->countAffected();
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
