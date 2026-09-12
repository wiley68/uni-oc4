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
                    `process2_mail_state`, `process2_mail_claimed_at`, `process2_mail_claim_token`,
                    `leasing_presentation_json`,
                    `store_id`, `order_id`, `control_panel_order_id`, `state`, `updated_at`
             FROM `{$this->tableName()}`
             WHERE `attempt_id` = " . (int) $attemptId . ' LIMIT 1'
        );

        return is_object($result) && $result->num_rows === 1 ? $result->row : null;
    }

    /** @return array<string, mixed>|null */
    public function findStatusSyncSnapshot(int $attemptId): ?array
    {
        if ($attemptId <= 0) {
            return null;
        }
        $result = $this->db->query(
            "SELECT `cp_status_sync_state`, `cp_status_sync_status_id`, `cp_status_sync_status`
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

    /**
     * Reclaim a stale preparing lease when no durable Process 2 target exists yet.
     * Resets to failed so a later claimPreparing from failed/not_started can retry.
     */
    public function reclaimStalePreparing(int $attemptId, int $staleSeconds): bool
    {
        if ($attemptId <= 0 || $staleSeconds <= 0) {
            return false;
        }

        $staleBefore = gmdate('Y-m-d H:i:s', $this->clock->now() - $staleSeconds);
        $now = $this->now();
        $this->db->query(
            "UPDATE `{$this->tableName()}`
             SET `process2_state` = '" . ProcessTwoLifecycleStates::FAILED . "',
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND `process2_state` = '" . ProcessTwoLifecycleStates::PREPARING . "'
               AND `updated_at` < '" . $this->db->escape($staleBefore) . "'
               AND (
                    `cp_status_sync_status_id` IS NULL
                    OR `cp_status_sync_status_id` = ''
                    OR `cp_status_sync_status_id` <> '" . $this->db->escape(BankStatus::SENT_PROCESS2) . "'
                    OR `cp_status_sync_state` = '" . ControlPanelStatusSyncStates::NOT_NEEDED . "'
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
        if ($row === null) {
            return false;
        }

        $state = (string) ($row['process2_mail_state'] ?? '');
        if ($state === ProcessTwoMailStates::SENT) {
            return true;
        }

        // Legacy installs may only have the boolean marker.
        return !empty($row['process2_mail_sent']);
    }

    /**
     * Atomic claim: not_sent → sending, or stale sending reclaim with a NEW claim token.
     * Old tokens cannot mutate after a stale reclaim.
     *
     * Residual SMTP crash window: if the provider accepted mail before markSent and the process
     * dies, a later reclaim may resend. Prefer at-least-once delivery over silent loss.
     *
     * @return non-empty-string|null Claim token on success, null when claim lost.
     */
    public function claimSending(int $attemptId): ?string
    {
        if ($attemptId <= 0) {
            return null;
        }

        $now = $this->now();
        $staleBefore = gmdate(
            'Y-m-d H:i:s',
            $this->clock->now() - ProcessTwoMailStates::SENDING_STALE_SECONDS
        );
        $token = bin2hex(random_bytes(32));

        $this->db->query(
            "UPDATE `{$this->tableName()}`
             SET `process2_mail_state` = '" . ProcessTwoMailStates::SENDING . "',
                 `process2_mail_claimed_at` = '" . $this->db->escape($now) . "',
                 `process2_mail_claim_token` = '" . $this->db->escape($token) . "',
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND (
                    `process2_mail_state` = '" . ProcessTwoMailStates::NOT_SENT . "'
                    OR (
                        `process2_mail_state` = '" . ProcessTwoMailStates::SENDING . "'
                        AND (
                            `process2_mail_claimed_at` IS NULL
                            OR `process2_mail_claimed_at` < '" . $this->db->escape($staleBefore) . "'
                        )
                    )
               )
               AND COALESCE(`process2_mail_sent`, 0) = 0
               AND `process2_mail_state` <> '" . ProcessTwoMailStates::SENT . "'"
        );

        return $this->db->countAffected() === 1 ? $token : null;
    }

    public function markSent(int $attemptId, string $claimToken): void
    {
        $claimToken = trim($claimToken);
        if ($attemptId <= 0 || $claimToken === '' || strlen($claimToken) !== 64) {
            return;
        }

        $now = $this->now();
        $this->db->query(
            "UPDATE `{$this->tableName()}`
             SET `process2_mail_state` = '" . ProcessTwoMailStates::SENT . "',
                 `process2_mail_sent` = 1,
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND `process2_mail_state` = '" . ProcessTwoMailStates::SENDING . "'
               AND `process2_mail_claim_token` = '" . $this->db->escape($claimToken) . "'"
        );
    }

    /**
     * Release a claimed send after definitive pre/during-send failure so a later retry may claim.
     * Requires matching claim token — stale reclaim owners cannot release with an old token.
     */
    public function releaseSendingOnFailure(int $attemptId, string $claimToken): void
    {
        $claimToken = trim($claimToken);
        if ($attemptId <= 0 || $claimToken === '' || strlen($claimToken) !== 64) {
            return;
        }

        $now = $this->now();
        $this->db->query(
            "UPDATE `{$this->tableName()}`
             SET `process2_mail_state` = '" . ProcessTwoMailStates::NOT_SENT . "',
                 `process2_mail_claimed_at` = NULL,
                 `process2_mail_claim_token` = NULL,
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND `process2_mail_state` = '" . ProcessTwoMailStates::SENDING . "'
               AND `process2_mail_claim_token` = '" . $this->db->escape($claimToken) . "'"
        );
    }

    /** @deprecated Prefer claimSending/markSent/releaseSendingOnFailure */
    public function markMailSent(int $attemptId): void
    {
        $now = $this->now();
        $this->db->query(
            "UPDATE `{$this->tableName()}`
             SET `process2_mail_sent` = 1,
                 `process2_mail_state` = '" . ProcessTwoMailStates::SENT . "',
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
