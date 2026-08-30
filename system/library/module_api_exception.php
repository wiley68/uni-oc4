<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Inbound module API failure with HTTP status for JSON responses.
 */
final class ModuleApiException extends \RuntimeException
{
    private int $statusCode;

    private ?string $errorCode;

    /** @var array<string, mixed>|null */
    private ?array $responseData;

    /**
     * @param array<string, mixed>|null $responseData
     */
    public function __construct(
        string $message,
        int $statusCode = 400,
        ?string $errorCode = null,
        ?array $responseData = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->responseData = $responseData;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed>|null */
    public function getResponseData(): ?array
    {
        return $this->responseData;
    }
}
