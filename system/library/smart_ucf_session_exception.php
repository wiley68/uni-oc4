<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class SmartUcfSessionException extends \RuntimeException
{
    public const KIND_PRE_SEND = 'pre_send';
    public const KIND_TRANSPORT = 'transport';
    public const KIND_REMOTE = 'remote';
    public const KIND_DUPLICATE = 'duplicate';

    public function __construct(
        string $message,
        private bool $retryable = false,
        private string $rawResponse = '',
        private int $httpCode = 0,
        private string $failureKind = self::KIND_REMOTE,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function httpCode(): int
    {
        return $this->httpCode;
    }

    public function rawResponse(): string
    {
        return $this->rawResponse;
    }

    public function getFailureKind(): string
    {
        return $this->failureKind;
    }
}
