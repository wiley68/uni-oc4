<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class SmartUcfCoordinationResult
{
    public const KIND_CREATED = 'created';
    public const KIND_PROCESSING = 'processing';
    public const KIND_OUTCOME_UNKNOWN = 'outcome_unknown';
    public const KIND_FAILED = 'failed';
    public const KIND_PROCESS2 = 'process2';

    private function __construct(
        private string $kind,
        private string $redirectUrl = '',
        private string $sessionId = '',
        private string $customerMessage = '',
        private bool $retryable = false,
        private string $errorClass = ''
    ) {
    }

    public static function created(string $redirectUrl, string $sessionId): self
    {
        return new self(self::KIND_CREATED, $redirectUrl, $sessionId);
    }

    public static function processing(string $message): self
    {
        return new self(self::KIND_PROCESSING, '', '', $message);
    }

    public static function outcomeUnknown(string $message): self
    {
        return new self(self::KIND_OUTCOME_UNKNOWN, '', '', $message);
    }

    public static function failed(string $message, bool $retryable = false, string $errorClass = ''): self
    {
        return new self(self::KIND_FAILED, '', '', $message, $retryable, $errorClass);
    }

    public static function process2(): self
    {
        return new self(self::KIND_PROCESS2);
    }

    public function isCreated(): bool { return $this->kind === self::KIND_CREATED; }
    public function isProcessing(): bool { return $this->kind === self::KIND_PROCESSING; }
    public function isOutcomeUnknown(): bool { return $this->kind === self::KIND_OUTCOME_UNKNOWN; }
    public function isFailed(): bool { return $this->kind === self::KIND_FAILED; }
    public function isProcess2(): bool { return $this->kind === self::KIND_PROCESS2; }
    public function redirectUrl(): string { return $this->redirectUrl; }
    public function sessionId(): string { return $this->sessionId; }
    public function customerMessage(): string { return $this->customerMessage; }
    public function isRetryable(): bool { return $this->retryable; }
    public function errorClass(): string { return $this->errorClass; }
}
