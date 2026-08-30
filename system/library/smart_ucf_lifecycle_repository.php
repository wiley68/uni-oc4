<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class SmartUcfLifecycleRepository
{
    public const STALE_SUBMITTING_SECONDS = 45;

    public function __construct(private DbConnection $db, private ?PersistenceClock $clock = null)
    {
        $this->clock ??= new PersistenceClock();
    }

    /** @return array<string, mixed>|null */
    public function findByAttempt(int $attemptId): ?array
    {
        if ($attemptId <= 0) {
            return null;
        }
        $result = $this->db->query(
            "SELECT * FROM `{$this->tableName()}` WHERE `attempt_id` = " . (int) $attemptId . ' LIMIT 1'
        );

        return is_object($result) && $result->num_rows === 1 ? $result->row : null;
    }

    /** @return array<string, mixed>|null */
    public function readAndNormalize(int $attemptId): ?array
    {
        $row = $this->findByAttempt($attemptId);
        if ($row !== null && $this->isStaleSubmitting($row)) {
            try {
                $this->markOutcomeUnknown(
                    $attemptId,
                    SmartUcfFailureClassification::CLASS_TRANSPORT_AMBIGUOUS,
                    (int) ($row['smartucf_http_code'] ?? 0)
                );
            } catch (\Throwable $exception) {
                // Never authorize another remote create when the stale-state write races.
            }
            $row = $this->findByAttempt($attemptId) ?? $row;
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    public function claimForSubmitting(int $attemptId): ?array
    {
        $now = $this->now();
        $table = $this->tableName();
        $this->db->query(
            "UPDATE `{$table}`
             SET `smartucf_state` = '" . SmartUcfLifecycleStates::SUBMITTING . "',
                 `smartucf_claimed_at` = '" . $this->db->escape($now) . "',
                 `smartucf_error_class` = NULL,
                 `smartucf_http_code` = NULL,
                 `smartucf_retryable` = 0,
                 `smartucf_completed_at` = NULL,
                 `updated_at` = '" . $this->db->escape($now) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND (
                    `smartucf_state` = '" . SmartUcfLifecycleStates::NOT_STARTED . "'
                    OR (`smartucf_state` = '" . SmartUcfLifecycleStates::FAILED . "' AND `smartucf_retryable` = 1)
               )"
        );

        return $this->db->countAffected() === 1 ? $this->findByAttempt($attemptId) : null;
    }

    public function markCreated(int $attemptId, string $sessionId, string $redirectUrl, int $httpCode): void
    {
        $this->transitionTerminal($attemptId, SmartUcfLifecycleStates::CREATED, [
            'smartucf_session_id' => substr($sessionId, 0, 128),
            'smartucf_redirect_url' => substr($redirectUrl, 0, 768),
            'smartucf_http_code' => $httpCode > 0 ? $httpCode : null,
            'smartucf_error_class' => null,
            'smartucf_retryable' => 0,
        ]);
    }

    public function markOutcomeUnknown(int $attemptId, string $errorClass, int $httpCode = 0): void
    {
        $this->transitionTerminal($attemptId, SmartUcfLifecycleStates::OUTCOME_UNKNOWN, [
            'smartucf_error_class' => substr($errorClass, 0, 64),
            'smartucf_http_code' => $httpCode > 0 ? $httpCode : null,
            'smartucf_retryable' => 0,
        ], [SmartUcfLifecycleStates::SUBMITTING, SmartUcfLifecycleStates::NOT_STARTED]);
    }

    public function markFailed(int $attemptId, string $errorClass, bool $retryable, int $httpCode = 0): void
    {
        $this->transitionTerminal($attemptId, SmartUcfLifecycleStates::FAILED, [
            'smartucf_error_class' => substr($errorClass, 0, 64),
            'smartucf_http_code' => $httpCode > 0 ? $httpCode : null,
            'smartucf_retryable' => $retryable ? 1 : 0,
        ], [SmartUcfLifecycleStates::SUBMITTING, SmartUcfLifecycleStates::NOT_STARTED]);
    }

    /** @param array<string, mixed> $row */
    public function isStaleSubmitting(array $row): bool
    {
        if ((string) ($row['smartucf_state'] ?? '') !== SmartUcfLifecycleStates::SUBMITTING) {
            return false;
        }
        $timestamp = strtotime((string) ($row['smartucf_claimed_at'] ?? '') . ' UTC');

        return $timestamp === false || time() - $timestamp >= self::STALE_SUBMITTING_SECONDS;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $fromStates
     */
    private function transitionTerminal(
        int $attemptId,
        string $state,
        array $values,
        array $fromStates = [SmartUcfLifecycleStates::SUBMITTING]
    ): void {
        $now = $this->now();
        $assignments = [
            "`smartucf_state` = '" . $this->db->escape($state) . "'",
            "`smartucf_completed_at` = '" . $this->db->escape($now) . "'",
            "`updated_at` = '" . $this->db->escape($now) . "'",
        ];
        foreach ($values as $column => $value) {
            $assignments[] = '`' . $column . '` = ' . ($value === null
                ? 'NULL'
                : (is_int($value) ? (string) $value : "'" . $this->db->escape((string) $value) . "'"));
        }
        $states = implode(', ', array_map(
            fn(string $value): string => "'" . $this->db->escape($value) . "'",
            $fromStates
        ));
        $this->db->query(
            "UPDATE `{$this->tableName()}` SET " . implode(', ', $assignments)
            . " WHERE `attempt_id` = " . (int) $attemptId . " AND `smartucf_state` IN ({$states})"
        );
        if ($this->db->countAffected() !== 1) {
            throw new PersistenceException('SmartUCF lifecycle transition did not update exactly one row.');
        }
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
