<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class ProductFinancingResult
{
    public function __construct(
        public bool $success,
        public string $step,
        public ?int $orderId,
        public string $message,
        public bool $replay = false,
        public string $lifecycleState = 'local_order_prepared',
        public ?int $controlPanelOrderId = null,
        public ?string $errorCode = null,
        public ?string $redirectUrl = null,
        public bool $bankSubmitted = false
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
            'success'          => $this->success,
            'step'             => $this->step,
            'message'          => $this->message,
            'lifecycle_state'  => $this->lifecycleState,
            'bank_submitted'   => $this->bankSubmitted,
        ];
        if ($this->orderId !== null) {
            $payload['order_id'] = $this->orderId;
        }
        if ($this->controlPanelOrderId !== null) {
            $payload['control_panel_order_id'] = $this->controlPanelOrderId;
        }
        if ($this->errorCode !== null) {
            $payload['error_code'] = $this->errorCode;
        }
        if ($this->redirectUrl !== null && $this->redirectUrl !== '') {
            $payload['redirect_url'] = $this->redirectUrl;
        }
        if ($this->replay) {
            $payload['replay'] = true;
        }
        if ($this->step === FinancingTerminalNavigationSupport::STEP_SMARTUCF_TERMINAL_FAILED) {
            $payload['terminal'] = true;
            $payload['bank_failure_known'] = true;
        }

        return $payload;
    }
}
