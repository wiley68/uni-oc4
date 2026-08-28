<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

class CpException extends \RuntimeException
{
    public function isTransient(): bool
    {
        return false;
    }

    public function isPermanentAuthOrConfiguration(): bool
    {
        return false;
    }
}

class CpConnectionException extends CpException
{
    public function isTransient(): bool
    {
        return true;
    }
}

final class CpTimeoutException extends CpConnectionException {}

final class CpMalformedJsonException extends CpException {}

final class CpAuthenticationException extends CpException
{
    public function isPermanentAuthOrConfiguration(): bool
    {
        return true;
    }
}

final class CpHttpException extends CpException
{
    private int $statusCode;

    /** @var array<string, mixed> */
    private array $errorPayload;

    /**
     * @param array<string, mixed> $errorPayload Safe decoded error body without secrets.
     */
    public function __construct(int $statusCode, array $errorPayload = [], string $message = 'Control Panel HTTP error.')
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorPayload = $errorPayload;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, mixed> */
    public function getErrorPayload(): array
    {
        return $this->errorPayload;
    }

    public function isTransient(): bool
    {
        return $this->statusCode >= 500;
    }

    public function isPermanentAuthOrConfiguration(): bool
    {
        return in_array($this->statusCode, [400, 401, 403, 404], true);
    }
}

final class CpInvalidPayloadException extends CpException
{
    public function isPermanentAuthOrConfiguration(): bool
    {
        return true;
    }
}
