<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Process 2 post-CP handoff: durable CP target → local bank_sent_process2 → PATCH → prepared → mail.
 *
 * Canonical sequence after CP create:
 * claimPreparing → admit durable target → local bank fact → PATCH → markPrepared → mail.
 * On CONFLICT after admit: fail without local mutation.
 * Stale preparing reclaim avoids infinite operation_processing loops.
 */
final class ProcessTwoLifecycleCoordinator
{
    public const ERROR_CP_BANK_STATUS_SYNC_PENDING = 'cp_bank_status_sync_pending';
    public const CUSTOMER_SUCCESS_MESSAGE =
        'Очаквайте контакт за потвърждаване на направената от Вас заявка.';

    /** Seconds after which a stuck preparing lease may be reclaimed. */
    public const PREPARING_STALE_SECONDS = 45;

    public function __construct(
        private ProcessTwoLifecycleRepository $lifecycle,
        private OrderBankStatusRepository $bankStatuses,
        private ControlPanelStatusSyncService $statusSync,
        private ProcessTwoSensitiveCipher $cipher,
        private ProcessTwoMailPort $mailer
    ) {
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext Safe non-PII order presentation for mail
     */
    public function run(
        int $attemptId,
        int $storeId,
        int $localOrderId,
        array $shop,
        array $orderContext,
        ?string $successRedirectUrl = null
    ): ProductFinancingResult {
        $row = $this->lifecycle->findByAttempt($attemptId);
        if ($row === null) {
            throw new ProductFinancingFlowException(
                'process2_failed',
                'Поръчката е създадена, но обработката за Процес 2 не може да бъде завършена.'
            );
        }

        $state = (string) ($row['process2_state'] ?? ProcessTwoLifecycleStates::NOT_STARTED);
        $shopOrderId = substr((string) $localOrderId, 0, 13);
        $status = BankStatus::process2Sent();

        if ($state === ProcessTwoLifecycleStates::PREPARED) {
            // Replay: do not re-handoff local bank when already process2; only retry pending CP sync.
            $this->statusSync->retryPending($attemptId, $shopOrderId);
            if (!$this->lifecycle->isMailSent($attemptId)) {
                $this->trySendMail($attemptId, $row, $shop, $orderContext);
            }

            return $this->successResult($localOrderId, $row, $successRedirectUrl, true);
        }

        if ($state === ProcessTwoLifecycleStates::PREPARING) {
            $syncSnapshot = $this->lifecycle->findStatusSyncSnapshot($attemptId);
            $syncState = (string) ($syncSnapshot['cp_status_sync_state'] ?? '');
            $syncStatusId = (string) ($syncSnapshot['cp_status_sync_status_id'] ?? '');
            $hasProcess2Target = $syncStatusId === BankStatus::SENT_PROCESS2
                && in_array($syncState, [
                    ControlPanelStatusSyncStates::PENDING,
                    ControlPanelStatusSyncStates::CONFIRMED,
                ], true);

            if ($hasProcess2Target) {
                // Durable target already admitted — resume local + PATCH without repeating sensitive handoff.
                return $this->resumeAfterAdmittedTarget(
                    $attemptId,
                    $storeId,
                    $localOrderId,
                    $shopOrderId,
                    $status,
                    $row,
                    $shop,
                    $orderContext,
                    $successRedirectUrl
                );
            }

            if ($this->lifecycle->reclaimStalePreparing($attemptId, self::PREPARING_STALE_SECONDS)) {
                // Fall through to fresh claim after reclaim to not_started/failed.
                $row = $this->lifecycle->findByAttempt($attemptId) ?? $row;
            } else {
                throw new ProductFinancingFlowException(
                    'operation_processing',
                    'Заявката се обработва. Моля, изчакайте.'
                );
            }
        }

        if (!$this->lifecycle->claimPreparing($attemptId)) {
            $fresh = $this->lifecycle->findByAttempt($attemptId);
            if ($fresh !== null
                && (string) ($fresh['process2_state'] ?? '') === ProcessTwoLifecycleStates::PREPARED
            ) {
                return $this->run($attemptId, $storeId, $localOrderId, $shop, $orderContext, $successRedirectUrl);
            }
            throw new ProductFinancingFlowException(
                'operation_processing',
                'Заявката се обработва. Моля, изчакайте.'
            );
        }

        try {
            $enc = (string) ($row['process2_sensitive_enc'] ?? '');
            if ($enc === '') {
                throw new \RuntimeException('Process 2 sensitive payload missing.');
            }

            $decision = $this->statusSync->admitTarget(
                $attemptId,
                $status['status_id'],
                $status['status_label']
            );
            if ($decision === ControlPanelStatusSyncService::CONFLICT
                || $decision === ControlPanelStatusSyncService::REJECT
            ) {
                throw new \RuntimeException('Process 2 durable target admission conflict.');
            }

            try {
                $this->bankStatuses->upsertAuthorizedLocal(
                    $storeId,
                    $localOrderId,
                    $status['status_id'],
                    $status['status_label']
                );
            } catch (OrderBankStatusSemanticConflictException $exception) {
                throw new \RuntimeException('Process 2 local bank status conflict.', 0, $exception);
            }

            $syncState = $this->statusSync->retryPending($attemptId, $shopOrderId);
            if ($syncState === ControlPanelStatusSyncStates::PENDING) {
                error_log(
                    'mt_uni_credit: ' . self::ERROR_CP_BANK_STATUS_SYNC_PENDING
                    . ' attempt_id=' . $attemptId
                    . ' order_id=' . $shopOrderId
                    . ' status_id=' . $status['status_id']
                );
            }

            $this->lifecycle->markPrepared($attemptId);
            $this->trySendMail($attemptId, $row, $shop, $orderContext);
            $this->lifecycle->redactExpiredSensitiveBatch();
        } catch (\Throwable $exception) {
            try {
                $this->lifecycle->markFailed($attemptId);
            } catch (\Throwable $ignored) {
            }
            error_log(
                'mt_uni_credit: Process 2 handoff failed attempt_id=' . $attemptId
                . ' class=' . $exception::class
            );
            throw new ProductFinancingFlowException(
                'process2_failed',
                'Поръчката е създадена, но обработката за Процес 2 не беше завършена успешно.'
            );
        }

        $fresh = $this->lifecycle->findByAttempt($attemptId) ?? $row;

        return $this->successResult($localOrderId, $fresh, $successRedirectUrl, false);
    }

    /**
     * @param array{status_id: string, status_label: string} $status
     * @param array<string, mixed> $row
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext
     */
    private function resumeAfterAdmittedTarget(
        int $attemptId,
        int $storeId,
        int $localOrderId,
        string $shopOrderId,
        array $status,
        array $row,
        array $shop,
        array $orderContext,
        ?string $successRedirectUrl
    ): ProductFinancingResult {
        try {
            try {
                $this->bankStatuses->upsertAuthorizedLocal(
                    $storeId,
                    $localOrderId,
                    $status['status_id'],
                    $status['status_label']
                );
            } catch (OrderBankStatusSemanticConflictException $exception) {
                throw new \RuntimeException('Process 2 local bank status conflict on resume.', 0, $exception);
            }

            $this->statusSync->retryPending($attemptId, $shopOrderId);
            $this->lifecycle->markPrepared($attemptId);
            $this->trySendMail($attemptId, $row, $shop, $orderContext);
        } catch (\Throwable $exception) {
            error_log(
                'mt_uni_credit: Process 2 resume after admitted target failed attempt_id=' . $attemptId
                . ' class=' . $exception::class
            );
            throw new ProductFinancingFlowException(
                'operation_processing',
                'Заявката се обработва. Моля, изчакайте.'
            );
        }

        $fresh = $this->lifecycle->findByAttempt($attemptId) ?? $row;

        return $this->successResult($localOrderId, $fresh, $successRedirectUrl, true);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext
     */
    private function trySendMail(int $attemptId, array $row, array $shop, array $orderContext): void
    {
        if ($this->lifecycle->isMailSent($attemptId)) {
            return;
        }

        // Residual SMTP crash window: provider may have accepted the message before markSent;
        // stale reclaim issues a NEW claim token so a prior owner cannot mutate after reclaim.
        $claimToken = $this->lifecycle->claimSending($attemptId);
        if ($claimToken === null) {
            return;
        }

        $sensitive = null;
        $enc = (string) ($row['process2_sensitive_enc'] ?? '');
        if ($enc !== '') {
            try {
                $sensitive = $this->cipher->decrypt($enc);
            } catch (\Throwable $exception) {
                $this->lifecycle->releaseSendingOnFailure($attemptId, $claimToken);
                error_log('mt_uni_credit: Process 2 sensitive decrypt failed attempt_id=' . $attemptId);

                return;
            }
        }

        try {
            $orderContext = $this->enrichMailContext($attemptId, $row, $orderContext);
            $ok = $this->mailer->sendProcess2Notifications($shop, $orderContext, $sensitive);
            if ($ok) {
                $this->lifecycle->markSent($attemptId, $claimToken);
            } else {
                $this->lifecycle->releaseSendingOnFailure($attemptId, $claimToken);
            }
        } catch (\Throwable $exception) {
            $this->lifecycle->releaseSendingOnFailure($attemptId, $claimToken);
            error_log(
                'mt_uni_credit: Process 2 mail failed attempt_id=' . $attemptId
                . ' class=' . $exception::class
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $orderContext
     * @return array<string, mixed>
     */
    private function enrichMailContext(int $attemptId, array $row, array $orderContext): array
    {
        $status = BankStatus::process2Sent();
        $orderContext['bank_status_label'] = $status['status_label'];
        $orderContext['control_panel_order_id'] = isset($row['control_panel_order_id'])
            ? (int) $row['control_panel_order_id']
            : null;
        $json = (string) ($row['leasing_presentation_json'] ?? '');
        if ($json !== '') {
            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $orderContext['leasing_snapshot'] = $decoded;
                }
            } catch (\Throwable) {
                error_log('mt_uni_credit: leasing presentation json decode failed attempt_id=' . $attemptId);
            }
        }

        return $orderContext;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function successResult(
        int $localOrderId,
        array $row,
        ?string $successRedirectUrl,
        bool $replay
    ): ProductFinancingResult {
        $cpId = isset($row['control_panel_order_id']) ? (int) $row['control_panel_order_id'] : null;

        return new ProductFinancingResult(
            true,
            'process2_prepared',
            $localOrderId,
            self::CUSTOMER_SUCCESS_MESSAGE,
            $replay,
            ProcessTwoLifecycleStates::PREPARED,
            $cpId > 0 ? $cpId : null,
            null,
            $successRedirectUrl,
            false
        );
    }
}
