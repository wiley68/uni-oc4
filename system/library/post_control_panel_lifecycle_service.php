<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class PostControlPanelLifecycleService
{
    public function __construct(
        private SmartUcfSessionCoordinator $coordinator,
        private ?ProcessTwoLifecycleCoordinator $process2Coordinator = null,
        private ?string $successRedirectUrl = null
    ) {
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext Safe mail context (no EGN unless decrypted later)
     */
    public function handle(
        int $attemptId,
        ValidatedFinancingSubmission $submission,
        int $localOrderId,
        int $cpOrderId,
        array $shop,
        bool $cpReplay = false,
        array $orderContext = []
    ): ProductFinancingResult {
        if (ShopConfigurationFlags::isSecondaryProcess($shop)) {
            if ($this->process2Coordinator === null) {
                throw new ProductFinancingFlowException(
                    'process2_failed',
                    'Process 2 lifecycle is not configured.'
                );
            }
            $context = $orderContext !== [] ? $orderContext : $this->defaultOrderContext($submission, $localOrderId);

            return $this->process2Coordinator->run(
                $attemptId,
                $submission->storeId,
                $localOrderId,
                $shop,
                $context,
                $this->successRedirectUrl
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

    /**
     * @return array<string, mixed>
     */
    private function defaultOrderContext(ValidatedFinancingSubmission $submission, int $localOrderId): array
    {
        $calc = $submission->financingCalculation;

        return [
            'order_id' => $localOrderId,
            'customer_name' => trim($submission->customer->firstname . ' ' . $submission->customer->lastname),
            'customer_email' => $submission->customer->email,
            'scheme_label' => (string) ($calc->scheme->kopCode ?? '') . ' / ' . (string) ($calc->scheme->months ?? ''),
            'monthly_amount' => (string) $calc->monthlyInstallment,
        ];
    }
}
