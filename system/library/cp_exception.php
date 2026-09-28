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

    private bool $canonicalFailure;

    private ?string $canonicalError;

    /**
     * @param array<string, mixed> $errorPayload Safe decoded error body without secrets.
     */
    public function __construct(
        int $statusCode,
        array $errorPayload = [],
        string $message = 'Control Panel HTTP error.',
        bool $canonicalFailure = false,
        ?string $canonicalError = null
    ) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorPayload = $errorPayload;
        $this->canonicalFailure = $canonicalFailure;
        $this->canonicalError = $canonicalError;
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

    public function isCanonicalFailure(): bool
    {
        return $this->canonicalFailure;
    }

    public function getCanonicalError(): ?string
    {
        return $this->canonicalError;
    }

    public function isTransient(): bool
    {
        return in_array($this->statusCode, [408, 429], true) || $this->statusCode >= 500;
    }

    public function isPermanentAuthOrConfiguration(): bool
    {
        if ($this->statusCode === 401 || $this->statusCode === 410) {
            return true;
        }
        if (!in_array($this->statusCode, [403, 404], true)) {
            return false;
        }

        return in_array($this->canonicalError, [
            'forbidden', 'shop_disabled', 'shop_deleted', 'shop_not_found',
            'credential_mismatch', 'unicid_mismatch', 'revoked',
        ], true);
    }
}

final class CpInvalidPayloadException extends CpException {}
