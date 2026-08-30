<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Process 2 post-CP handoff: bank_sent_process2 + leasing mail (no SmartUCF).
 */
final class ProcessTwoLifecycleCoordinator
{
    public const ERROR_CP_BANK_STATUS_SYNC_PENDING = 'cp_bank_status_sync_pending';
    public const CUSTOMER_SUCCESS_MESSAGE =
        'Очаквайте контакт за потвърждаване на направената от Вас заявка.';

    public function __construct(
        private ProcessTwoLifecycleRepository $lifecycle,
        private OrderBankStatusRepository $bankStatuses,
        private ControlPanelClient $controlPanel,
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
        if ($state === ProcessTwoLifecycleStates::PREPARED) {
            $this->reconcileBankStatus($attemptId, $storeId, $localOrderId);
            if (!$this->lifecycle->isMailSent($attemptId)) {
                $this->trySendMail($attemptId, $row, $shop, $orderContext);
            }

            return $this->successResult($localOrderId, $row, $successRedirectUrl, true);
        }

        if ($state === ProcessTwoLifecycleStates::PREPARING) {
            // Concurrent handoff — wait / retry client-side.
            throw new ProductFinancingFlowException(
                'operation_processing',
                'Заявката се обработва. Моля, изчакайте.'
            );
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
            $this->reconcileBankStatus($attemptId, $storeId, $localOrderId);
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
     * @param array<string, mixed> $row
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext
     */
    private function trySendMail(int $attemptId, array $row, array $shop, array $orderContext): void
    {
        if ($this->lifecycle->isMailSent($attemptId)) {
            return;
        }
        $sensitive = null;
        $enc = (string) ($row['process2_sensitive_enc'] ?? '');
        if ($enc !== '') {
            try {
                $sensitive = $this->cipher->decrypt($enc);
            } catch (\Throwable $exception) {
                error_log('mt_uni_credit: Process 2 sensitive decrypt failed attempt_id=' . $attemptId);
            }
        }
        try {
            $ok = $this->mailer->sendProcess2Notifications($shop, $orderContext, $sensitive);
            if ($ok) {
                $this->lifecycle->markMailSent($attemptId);
            }
        } catch (\Throwable $exception) {
            // Bank status already prepared — mail is independent (PS9 parity).
            error_log(
                'mt_uni_credit: Process 2 mail failed attempt_id=' . $attemptId
                . ' class=' . $exception::class
            );
        }
    }

    private function reconcileBankStatus(int $attemptId, int $storeId, int $localOrderId): void
    {
        $status = BankStatus::process2Sent();
        $shopOrderId = substr((string) $localOrderId, 0, 13);
        try {
            $this->bankStatuses->updateByOrderIdentifier(
                $storeId,
                $shopOrderId,
                $status['status_id'],
                $status['status_label']
            );
        } catch (\Throwable $ignored) {
        }
        try {
            $this->controlPanel->updateOrderStatus(
                $shopOrderId,
                $status['status_label'],
                $status['status_id']
            );
        } catch (\Throwable $exception) {
            error_log(
                'mt_uni_credit: ' . self::ERROR_CP_BANK_STATUS_SYNC_PENDING
                . ' attempt_id=' . $attemptId
                . ' order_id=' . $shopOrderId
                . ' status_id=' . $status['status_id']
                . ' class=' . $exception::class
            );
        }
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
