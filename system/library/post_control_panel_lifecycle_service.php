<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class PostControlPanelLifecycleService
{
    public function __construct(private SmartUcfSessionCoordinator $coordinator)
    {
    }

    /**
     * @param array<string, mixed> $shop
     */
    public function handle(
        int $attemptId,
        ValidatedFinancingSubmission $submission,
        int $localOrderId,
        int $cpOrderId,
        array $shop,
        bool $cpReplay = false
    ): ProductFinancingResult {
        if (ShopConfigurationFlags::isSecondaryProcess($shop)) {
            return new ProductFinancingResult(
                true,
                'cp_order_prepared',
                $localOrderId,
                ControlPanelOrderLifecycleService::CUSTOMER_SUCCESS_MESSAGE,
                $cpReplay,
                FinancingAttemptState::CP_CREATED,
                $cpOrderId
            );
        }

        $result = $this->coordinator->run($attemptId, $shop, $submission, $localOrderId, $cpOrderId);
        if ($result->isCreated()) {
            return new ProductFinancingResult(
                true,
                'bank_redirect',
                $localOrderId,
                ControlPanelOrderLifecycleService::CUSTOMER_SUCCESS_MESSAGE,
                $cpReplay,
                'completed',
                $cpOrderId,
                null,
                $result->redirectUrl(),
                true
            );
        }
        if ($result->isProcessing()) {
            return new ProductFinancingResult(
                false,
                'bank_processing',
                $localOrderId,
                $result->customerMessage(),
                $cpReplay,
                SmartUcfLifecycleStates::SUBMITTING,
                $cpOrderId,
                'smartucf_processing'
            );
        }
        if ($result->isOutcomeUnknown()) {
            return new ProductFinancingResult(
                false,
                'bank_outcome_unknown',
                $localOrderId,
                $result->customerMessage(),
                $cpReplay,
                SmartUcfLifecycleStates::OUTCOME_UNKNOWN,
                $cpOrderId,
                'smartucf_outcome_unknown'
            );
        }

        throw new ProductFinancingFlowException(
            'smartucf_submit_failed',
            $result->customerMessage() !== '' ? $result->customerMessage() : SmartUcfSessionCoordinator::CUSTOMER_FAILED,
            []
        );
    }
}
