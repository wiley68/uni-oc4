<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Durable, idempotent CP status PATCH synchronization after proven P1/P2 handoffs.
 *
 * Local bank_sent_* = business handoff proven.
 * cp_status_sync_* = CP confirmation state with concurrency-safe CAS transitions.
 *
 * bank_sent_process1 and bank_sent_process2 are mutually incompatible terminal
 * targets, not sequential stages.
 */
final class ControlPanelStatusSyncService
{
    public const ADMIT = 'admit';
    public const SAME = 'same';
    public const CONFLICT = 'conflict';
    public const REJECT = 'reject';

    /** Positive allowlist of definitive non-retryable CP machine codes. */
    private const TERMINAL_ERROR_CODES = [
        'invalid_payload',
        'semantic_conflict',
        'unsupported_status',
        'order_not_found',
    ];

    /** Mutually incompatible CP terminal sent statuses (same lifecycle rank). */
    private const TERMINAL_SENT = [
        BankStatus::SENT_PROCESS1,
        BankStatus::SENT_PROCESS2,
    ];

    public function __construct(
        private ControlPanelStatusSyncStoreInterface $store,
        private ControlPanelOrderStatusPort $cpClient
    ) {
    }

    /**
     * Persist pending sync for a proven business handoff, then attempt PATCH.
     *
     * @param array{status_id: string, status_label: string} $status
     */
    public function synchronizeAfterHandoff(int $attemptId, string $orderReference, array $status): string
    {
        if ($attemptId <= 0 || $orderReference === '') {
            return ControlPanelStatusSyncStates::NOT_NEEDED;
        }

        $statusId = (string) ($status['status_id'] ?? '');
        $statusLabel = (string) ($status['status_label'] ?? '');
        if ($statusId === '' || $statusLabel === '') {
            return ControlPanelStatusSyncStates::NOT_NEEDED;
        }

        $decision = $this->admitTarget($attemptId, $statusId, $statusLabel);
        if ($decision === self::CONFLICT || $decision === self::REJECT) {
            return $this->currentStateOr($attemptId, ControlPanelStatusSyncStates::NOT_NEEDED);
        }

        return $this->attemptPending($attemptId, $orderReference);
    }

    /**
     * Admit a durable pending CP target without PATCHing.
     *
     * Callers that must write local bank fact before PATCH use admitTarget → local → retryPending.
     *
     * @return self::ADMIT|self::SAME|self::CONFLICT|self::REJECT
     */
    public function admitTarget(int $attemptId, string $statusId, string $statusLabel = ''): string
    {
        if ($attemptId <= 0 || $statusId === '') {
            return self::REJECT;
        }

        return $this->admitPendingTarget($attemptId, $statusId, $statusLabel);
    }

    /**
     * Retry a previously persisted pending sync.
     * Persistence remains authority for the target that is sent.
     */
    public function retryPending(int $attemptId, string $orderReference): string
    {
        if ($attemptId <= 0 || $orderReference === '') {
            return ControlPanelStatusSyncStates::NOT_NEEDED;
        }

        return $this->attemptPending($attemptId, $orderReference);
    }

    /**
     * Read the durable CP sync target authority for crash recovery / replay.
     *
     * Missing/empty fields are returned as null — never defaulted to canonical labels.
     *
     * @return array{
     *     state: string,
     *     status_id: ?string,
     *     status: ?string,
     *     error_class: ?string
     * }|null
     */
    public function readPersistedTarget(int $attemptId): ?array
    {
        if ($attemptId <= 0) {
            return null;
        }

        $snapshot = $this->store->findByAttempt($attemptId);
        if ($snapshot === null) {
            return null;
        }

        return [
            'state' => (string) ($snapshot['cp_status_sync_state'] ?? ControlPanelStatusSyncStates::NOT_NEEDED),
            'status_id' => $this->nullableString($snapshot['cp_status_sync_status_id'] ?? null),
            'status' => $this->nullableString($snapshot['cp_status_sync_status'] ?? null),
            'error_class' => $this->nullableString($snapshot['cp_status_sync_error_class'] ?? null),
        ];
    }

    /** @return self::ADMIT|self::SAME|self::CONFLICT|self::REJECT */
    private function admitPendingTarget(int $attemptId, string $statusId, string $statusLabel): string
    {
        $snapshot = $this->store->findByAttempt($attemptId);
        if ($snapshot === null) {
            return self::REJECT;
        }

        $currentState = (string) ($snapshot['cp_status_sync_state'] ?? ControlPanelStatusSyncStates::NOT_NEEDED);
        $currentStatusId = $this->nullableString($snapshot['cp_status_sync_status_id'] ?? null);
        $currentStatus = $this->nullableString($snapshot['cp_status_sync_status'] ?? null);

        $decision = $this->decideAdmission($currentState, $currentStatusId, $statusId);
        if ($decision !== self::ADMIT) {
            if ($decision === self::CONFLICT) {
                $this->log(
                    'CP status sync conflict: incompatible terminal targets '
                    . (string) $currentStatusId . ' vs ' . $statusId
                );
            }

            return $decision;
        }

        $updated = $this->store->compareAndSetPendingTarget(
            $attemptId,
            $currentState,
            $currentStatusId,
            $currentStatus,
            $statusId,
            $statusLabel
        );
        if ($updated) {
            return self::ADMIT;
        }

        $latest = $this->store->findByAttempt($attemptId);
        if ($latest === null) {
            return self::REJECT;
        }
        $latestState = (string) ($latest['cp_status_sync_state'] ?? ControlPanelStatusSyncStates::NOT_NEEDED);
        $latestStatusId = $this->nullableString($latest['cp_status_sync_status_id'] ?? null);
        $retryDecision = $this->decideAdmission($latestState, $latestStatusId, $statusId);
        if ($retryDecision !== self::ADMIT) {
            if ($retryDecision === self::CONFLICT) {
                $this->log(
                    'CP status sync conflict after CAS miss: incompatible terminal targets '
                    . (string) $latestStatusId . ' vs ' . $statusId
                );
            }

            return $retryDecision;
        }

        $second = $this->store->compareAndSetPendingTarget(
            $attemptId,
            $latestState,
            $latestStatusId,
            $this->nullableString($latest['cp_status_sync_status'] ?? null),
            $statusId,
            $statusLabel
        );
        if ($second) {
            return self::ADMIT;
        }

        // Second CAS miss: never claim ADMIT — reload and classify authoritative state.
        $authoritative = $this->store->findByAttempt($attemptId);
        if ($authoritative === null) {
            return self::REJECT;
        }

        $authDecision = $this->decideAdmission(
            (string) ($authoritative['cp_status_sync_state'] ?? ControlPanelStatusSyncStates::NOT_NEEDED),
            $this->nullableString($authoritative['cp_status_sync_status_id'] ?? null),
            $statusId
        );
        if ($authDecision === self::CONFLICT) {
            $this->log(
                'CP status sync conflict after second CAS miss: incompatible terminal targets '
                . (string) ($authoritative['cp_status_sync_status_id'] ?? '') . ' vs ' . $statusId
            );
        }

        return $authDecision === self::ADMIT ? self::REJECT : $authDecision;
    }

    private function attemptPending(int $attemptId, string $orderReference): string
    {
        $snapshot = $this->store->findByAttempt($attemptId);
        if ($snapshot === null) {
            return ControlPanelStatusSyncStates::NOT_NEEDED;
        }

        $state = (string) ($snapshot['cp_status_sync_state'] ?? ControlPanelStatusSyncStates::NOT_NEEDED);
        if ($state === ControlPanelStatusSyncStates::CONFIRMED) {
            return ControlPanelStatusSyncStates::CONFIRMED;
        }
        if ($state === ControlPanelStatusSyncStates::TERMINAL_FAILED) {
            return ControlPanelStatusSyncStates::TERMINAL_FAILED;
        }
        if ($state !== ControlPanelStatusSyncStates::PENDING) {
            return $state !== '' ? $state : ControlPanelStatusSyncStates::NOT_NEEDED;
        }

        $snapshot = $this->store->findByAttempt($attemptId);
        if ($snapshot === null) {
            return ControlPanelStatusSyncStates::NOT_NEEDED;
        }
        $state = (string) ($snapshot['cp_status_sync_state'] ?? ControlPanelStatusSyncStates::NOT_NEEDED);
        if ($state !== ControlPanelStatusSyncStates::PENDING) {
            return $state !== '' ? $state : ControlPanelStatusSyncStates::NOT_NEEDED;
        }

        $statusId = (string) ($snapshot['cp_status_sync_status_id'] ?? '');
        $statusLabel = (string) ($snapshot['cp_status_sync_status'] ?? '');
        if ($statusId === '' || $statusLabel === '') {
            return ControlPanelStatusSyncStates::PENDING;
        }

        try {
            $this->cpClient->updateOrderStatus(
                substr($orderReference, 0, 13),
                $statusLabel,
                $statusId
            );
            $confirmed = $this->store->compareAndSetConfirmed($attemptId, $statusId, $statusLabel);
            if ($confirmed) {
                return ControlPanelStatusSyncStates::CONFIRMED;
            }

            return $this->currentStateOr($attemptId, ControlPanelStatusSyncStates::PENDING);
        } catch (\Throwable $exception) {
            $classification = $this->classifyFailure($exception);
            $newState = $classification['terminal']
                ? ControlPanelStatusSyncStates::TERMINAL_FAILED
                : ControlPanelStatusSyncStates::PENDING;
            $updated = $this->store->compareAndSetFailure(
                $attemptId,
                $statusId,
                $statusLabel,
                $newState,
                $classification['error_class']
            );
            if (!$updated) {
                return $this->currentStateOr($attemptId, ControlPanelStatusSyncStates::PENDING);
            }

            $this->log(
                $classification['terminal']
                    ? 'CP status sync terminal failure: ' . $classification['error_class']
                    : 'CP status sync remains pending: ' . $classification['error_class']
            );

            return $newState;
        }
    }

    private function currentStateOr(int $attemptId, string $fallback): string
    {
        $snapshot = $this->store->findByAttempt($attemptId);
        if ($snapshot === null) {
            return $fallback;
        }
        $state = (string) ($snapshot['cp_status_sync_state'] ?? $fallback);

        return $state !== '' ? $state : $fallback;
    }

    /** @return self::ADMIT|self::SAME|self::CONFLICT|self::REJECT */
    private function decideAdmission(string $currentState, ?string $currentStatusId, string $newStatusId): string
    {
        if ($currentState === ControlPanelStatusSyncStates::NOT_NEEDED || $currentState === '') {
            return self::ADMIT;
        }

        if ($currentStatusId !== null && $currentStatusId === $newStatusId) {
            return self::SAME;
        }

        if ($this->isIncompatibleTerminalSentPair($currentStatusId, $newStatusId)) {
            return self::CONFLICT;
        }

        return self::REJECT;
    }

    private function isIncompatibleTerminalSentPair(?string $currentStatusId, string $newStatusId): bool
    {
        if ($currentStatusId === null) {
            return false;
        }

        return in_array($currentStatusId, self::TERMINAL_SENT, true)
            && in_array($newStatusId, self::TERMINAL_SENT, true)
            && $currentStatusId !== $newStatusId;
    }

    /** @return array{terminal: bool, error_class: string} */
    private function classifyFailure(\Throwable $exception): array
    {
        if ($exception instanceof CpConnectionException || $exception instanceof CpTimeoutException) {
            return ['terminal' => false, 'error_class' => 'cp_status_transport_ambiguous'];
        }
        if ($exception instanceof CpMalformedJsonException || $exception instanceof CpInvalidPayloadException) {
            return ['terminal' => false, 'error_class' => 'cp_status_malformed_response'];
        }
        if ($exception instanceof CpAuthenticationException) {
            return ['terminal' => false, 'error_class' => 'cp_status_auth_retryable'];
        }
        if ($exception instanceof CpHttpException) {
            $status = $exception->getStatusCode();
            $error = '';
            if ($exception->isCanonicalFailure()) {
                $error = (string) $exception->getCanonicalError();
            } else {
                $response = $exception->getErrorPayload();
                $error = isset($response['error']) && is_string($response['error']) ? $response['error'] : '';
            }

            if ($error !== '' && in_array($error, self::TERMINAL_ERROR_CODES, true)) {
                return ['terminal' => true, 'error_class' => 'cp_status_' . $error];
            }

            if ($error === 'authentication_failed' || $error === 'token_expired') {
                return ['terminal' => false, 'error_class' => 'cp_status_' . $error];
            }
            if ($error === 'rate_limited') {
                return ['terminal' => false, 'error_class' => 'cp_status_rate_limited'];
            }
            if ($error === 'internal_error') {
                return ['terminal' => false, 'error_class' => 'cp_status_internal_error'];
            }

            return [
                'terminal' => false,
                'error_class' => $error !== '' ? 'cp_status_' . $error : 'cp_status_http_' . $status,
            ];
        }

        return ['terminal' => false, 'error_class' => 'cp_status_' . $exception::class];
    }

    /** @param mixed $value */
    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = (string) $value;

        return $string === '' ? null : $string;
    }

    private function log(string $message): void
    {
        error_log('mt_uni_credit: ' . DiagnosticPayloadRedactor::sanitizeLogMessage($message));
    }
}
