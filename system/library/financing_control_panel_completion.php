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
        string $lockOwnerToken
    ): ProductFinancingResult {
        $row = $attempt->row();
        $existingCp = isset($row['control_panel_order_id']) ? (int) $row['control_panel_order_id'] : 0;
        if ($existingCp > 0 && (string) ($row['state'] ?? '') === FinancingAttemptState::CP_CREATED) {
            return new ProductFinancingResult(
                true,
                'cp_order_prepared',
                $localOrderId,
                ControlPanelOrderLifecycleService::CUSTOMER_SUCCESS_MESSAGE,
                true,
                FinancingAttemptState::CP_CREATED,
                $existingCp
            );
        }

        // Ensure attempt is at least order_created before CP (bound retries may already be past).
        $state = (string) ($row['state'] ?? '');
        if (in_array($state, [FinancingAttemptState::ORDER_CREATING, FinancingAttemptState::VALIDATING, FinancingAttemptState::ISSUED], true)) {
            // no-op — materialization should have advanced; CP service also accepts order_created+
        }

        $result = $lifecycle->submitOrRecover($attempt, $submission, $localOrderId, $shop, $lockOwnerToken);
        if ($result->success && $result->cpOrderId !== null) {
            return new ProductFinancingResult(
                true,
                'cp_order_prepared',
                $localOrderId,
                ControlPanelOrderLifecycleService::CUSTOMER_SUCCESS_MESSAGE,
                $result->replay,
                FinancingAttemptState::CP_CREATED,
                $result->cpOrderId
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
}
