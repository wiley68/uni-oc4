<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class SmartUcfFailureClassification
{
    public const CLASS_PRE_SEND = 'pre_send';
    public const CLASS_REMOTE_REJECT = 'remote_reject';
    public const CLASS_TRANSPORT_AMBIGUOUS = 'transport_ambiguous';
    public const CLASS_DUPLICATE_ORDER_NO = 'duplicate_order_no';

    public function __construct(
        private string $targetState,
        private bool $retryable,
        private string $errorClass,
        private int $httpCode = 0
    ) {
    }

    public function targetState(): string
    {
        return $this->targetState;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function errorClass(): string
    {
        return $this->errorClass;
    }

    public function httpCode(): int
    {
        return $this->httpCode;
    }
}
