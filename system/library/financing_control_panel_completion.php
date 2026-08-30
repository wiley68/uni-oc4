<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Shared CP completion helper for Product/Cart/Checkout financing submission services.
 */
final class FinancingControlPanelCompletion
{
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
        return self::postControlPanel($lifecycle, null, $successRedirectUrl, $process2Mailer, $shop)->handle(
            $attemptId,
            $submission,
            $localOrderId,
            $cpOrderId,
            $shop,
            true
        );
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
            $lifecycle->client()
        );
        $process2 = null;
        if (ShopConfigurationFlags::isSecondaryProcess($shop)) {
            $process2 = new ProcessTwoLifecycleCoordinator(
                new ProcessTwoLifecycleRepository($db),
                new OrderBankStatusRepository($db),
                $lifecycle->client(),
                new ProcessTwoSensitiveCipher(),
                $process2Mailer ?? new PhpMailProcessTwoMailer()
            );
        }

        return new PostControlPanelLifecycleService($coordinator, $process2, $successRedirectUrl);
    }
}
