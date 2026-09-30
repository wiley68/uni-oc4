<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Shared CP completion helper for Product/Cart/Checkout financing submission services.
 */
final class FinancingControlPanelCompletion
{
    /**
     * A bound order owns recovery amounts. Keep existing materialization/CP locks and
     * post-CP claims; rebuild only from native rows and the frozen financing snapshot.
     * @param array<string, mixed> $row Already authorized by the entrypoint service.
     */
    public static function resumeBoundAttempt(
        ControlPanelOrderLifecycleService $lifecycle,
        OrderMaterializationService $materialization,
        array $row,
        int $storeId,
        array $shop,
        string $lockOwnerToken
    ): ?ProductFinancingResult {
        $orderId = (int) ($row['order_id'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }
        $attemptId = (int) $row['attempt_id'];
        $resume = ResumeSubmissionFactory::create(
            (string) $row['entry_point'], $storeId, $row['submission_token'] ?? null,
            (string) $row['operation_key_hash'], $orderId
        );
        if ((string) $row['state'] === FinancingAttemptState::CP_CREATED) {
            return self::resumeExistingCp(
                $lifecycle, $attemptId, $resume, $orderId,
                (int) ($row['control_panel_order_id'] ?? 0), $shop
            );
        }
        $proof = (new DurableEurOrderGuard($lifecycle->database()))->prove($attemptId, $storeId, $orderId);
        (new DurableEurResumeHydrator($lifecycle->database()))->hydrate($attemptId, $resume, $proof, true);
        try {
            $created = $materialization->materializeAndBind($resume, new FinancingAttemptContext($row), $lockOwnerToken);
        } catch (OrderMaterializationException $exception) {
            throw new ProductFinancingFlowException('order_materialization', 'Поръчката не може да бъде възстановена.', [], $exception);
        }
        $fresh = (new FinancingAttemptRepository($lifecycle->database()))->findById($attemptId) ?? $row;
        try {
            $result = self::apply($lifecycle, new FinancingAttemptContext($fresh), $resume, $orderId, $shop, $lockOwnerToken);
        } catch (ProductFinancingFlowException $exception) {
            $materialization->applyProductCartVisibleStatus($created, $resume->entryPoint);
            throw $exception;
        }
        $materialization->applyProductCartVisibleStatus($created, $resume->entryPoint);

        return $result;
    }

    public static function apply(
        ControlPanelOrderLifecycleService $lifecycle,
        FinancingAttemptContext $attempt,
        ValidatedFinancingSubmission $submission,
        int $localOrderId,
        array $shop,
        string $lockOwnerToken,
        ?PostControlPanelLifecycleService $postControlPanel = null,
        ?string $successRedirectUrl = null,
        ?ProcessTwoMailPort $process2Mailer = null
    ): ProductFinancingResult {
        self::proveOrder($lifecycle, $attempt->attemptId(), $submission, $localOrderId);
        $row = $attempt->row();
        $existingCp = isset($row['control_panel_order_id']) ? (int) $row['control_panel_order_id'] : 0;
        if ($existingCp > 0 && (string) ($row['state'] ?? '') === FinancingAttemptState::CP_CREATED) {
            return self::postControlPanel($lifecycle, $postControlPanel, $successRedirectUrl, $process2Mailer, $shop)->handle(
                $attempt->attemptId(),
                $submission,
                $localOrderId,
                $existingCp,
                $shop,
                true
            );
        }

        $result = $lifecycle->submitOrRecover($attempt, $submission, $localOrderId, $shop, $lockOwnerToken);
        if ($result->success && $result->cpOrderId !== null) {
            FinancingPresentationSupport::attachControlPanelOrderId(
                $lifecycle->database(),
                $attempt->attemptId(),
                $result->cpOrderId
            );

            return self::postControlPanel($lifecycle, $postControlPanel, $successRedirectUrl, $process2Mailer, $shop)->handle(
                $attempt->attemptId(),
                $submission,
                $localOrderId,
                $result->cpOrderId,
                $shop,
                $result->replay
            );
        }

        if ($result->definitiveFailure) {
            $status = BankStatus::cpFailure();
            $bankStatuses = new OrderBankStatusRepository($lifecycle->database());
            $previous = $bankStatuses->findCurrentStatus($submission->storeId, $localOrderId);
            $previousStatusId = is_array($previous) ? trim((string) ($previous['status_id'] ?? '')) : '';

            $bankStatuses->upsertAuthorizedLocal(
                $submission->storeId,
                $localOrderId,
                $status['status_id'],
                $status['status_label']
            );

            (new SatrudnikFailureNotifier(null, $lifecycle->database()))->notifyIfEligible(
                $shop,
                $localOrderId,
                $status['status_id'],
                $status['status_label'],
                $previousStatusId !== '' ? $previousStatusId : null,
                null
            );

            return new ProductFinancingResult(
                false,
                FinancingTerminalNavigationSupport::STEP_CP_TERMINAL_FAILED,
                $localOrderId,
                FinancingLeasingPresenter::CP_TERMINAL_FAILURE_MESSAGE,
                false,
                FinancingAttemptState::TERMINAL_FAILED,
                null,
                BankStatus::SEND_FAILED_CP,
                (string) ($successRedirectUrl ?? ''),
                false
            );
        }

        throw new ProductFinancingFlowException(
            $result->errorCode ?? 'cp_submit_failed',
            ControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE,
            [
                'error_class' => $result->errorCode ?? ControlPanelErrorClass::TRANSPORT_FAILED,
                'recoverable' => $result->recoverable ? '1' : '0',
            ]
        );
    }

    /**
     * Resume post-CP lifecycle when attempt is already cp_created (Process 1 or 2).
     *
     * @param array<string, mixed> $shop
     */
    public static function resumeExistingCp(
        ControlPanelOrderLifecycleService $lifecycle,
        int $attemptId,
        ValidatedFinancingSubmission $submission,
        int $localOrderId,
        int $cpOrderId,
        array $shop,
        ?string $successRedirectUrl = null,
        ?ProcessTwoMailPort $process2Mailer = null
    ): ProductFinancingResult {
        self::proveOrder($lifecycle, $attemptId, $submission, $localOrderId, true);

        return self::postControlPanel($lifecycle, null, $successRedirectUrl, $process2Mailer, $shop)->handle(
            $attemptId,
            $submission,
            $localOrderId,
            $cpOrderId,
            $shop,
            true
        );
    }

    private static function proveOrder(
        ControlPanelOrderLifecycleService $lifecycle,
        int $attemptId,
        ValidatedFinancingSubmission $submission,
        int $orderId,
        bool $cpSuccess = false
    ): void {
        $proof = (new DurableEurOrderGuard($lifecycle->database()))->prove(
            $attemptId,
            $submission->storeId,
            $orderId,
            $cpSuccess
        );
        $draft = $submission->orderDraft;
        if ($submission->submissionSource !== 'resume') {
            if (!(new CurrencyGate())->supports($draft->currencyCode)
                || $draft->currencyId !== $proof->currencyId
                || abs($draft->currencyValue - $proof->currencyValue) > 0.000001
                || abs($draft->orderTotal - $proof->baseTotal) > 0.02
                || abs($submission->financingCalculation->price - $proof->eurTotal) > 0.02) {
                throw DurableEurOrderGuard::failure();
            }
        }
        $submission->eurOrderProof = $proof;
    }

    /**
     * @param array<string, mixed> $shop
     */
    private static function postControlPanel(
        ControlPanelOrderLifecycleService $lifecycle,
        ?PostControlPanelLifecycleService $service,
        ?string $successRedirectUrl = null,
        ?ProcessTwoMailPort $process2Mailer = null,
        array $shop = []
    ): PostControlPanelLifecycleService {
        if ($service !== null) {
            return $service;
        }
        $db = $lifecycle->database();
        $coordinator = new SmartUcfSessionCoordinator(
            new SmartUcfLifecycleRepository($db),
            new SmartUcfSessionClient(),
            new SmartUcfFailureClassifier(),
            new OrderBankStatusRepository($db),
            $lifecycle->client(),
            new ControlPanelStatusSyncService(
                new ControlPanelStatusSyncRepository($db),
                $lifecycle->client()
            ),
            null,
            SmartUcfDiagnosticJournal::fromDatabase($db),
            null
        );
        $process2 = null;
        if (ShopConfigurationFlags::isSecondaryProcess($shop)) {
            try {
                $cipher = new ProcessTwoSensitiveCipher();
            } catch (\Throwable $exception) {
                throw new ProductFinancingFlowException(
                    'process2_encryption_unavailable',
                    'Поръчката е създадена, но обработката за Процес 2 не беше завършена успешно.',
                    [
                        'error_class' => 'process2_encryption_unavailable',
                        'recoverable' => '0',
                    ],
                    $exception
                );
            }
            $process2 = new ProcessTwoLifecycleCoordinator(
                new ProcessTwoLifecycleRepository($db),
                new OrderBankStatusRepository($db),
                new ControlPanelStatusSyncService(
                    new ControlPanelStatusSyncRepository($db),
                    $lifecycle->client()
                ),
                $cipher,
                $process2Mailer ?? new PhpMailProcessTwoMailer()
            );
        }

        return new PostControlPanelLifecycleService(
            $coordinator,
            $process2,
            $successRedirectUrl,
            new OrderBankStatusRepository($db),
            new SatrudnikFailureNotifier(null, $db)
        );
    }
}
