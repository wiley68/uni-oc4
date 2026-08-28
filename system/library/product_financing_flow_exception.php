<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Product financing flow errors — validation, stale selection, conflicts, materialization.
 */
final class ProductFinancingFlowException extends \RuntimeException
{
    /** @var array<string, string> */
    private array $fieldErrors;

    /**
     * @param array<string, string> $fieldErrors
     */
    public function __construct(
        private string $errorCode,
        string $message,
        array $fieldErrors = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->fieldErrors = $fieldErrors;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, string> */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            'validation' => 422,
            'stale_selection', 'unavailable_scheme', 'attempt_conflict', 'expired_attempt' => 409,
            'operation_processing' => 423,
            default => 500,
        };
    }
}
