<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Fixed CP secret for tests or explicit injection. */
final class FixedCpAuthSecretProvider extends CpAuthSecretProvider
{
    public function __construct(private readonly string $secret) {}

    public function isConfigured(): bool
    {
        return trim($this->secret) !== '';
    }

    public function getSecret(): ?string
    {
        $secret = trim($this->secret);

        return $secret !== '' ? $secret : null;
    }

    /** @return array{status: string, configured: bool} */
    public function health(): array
    {
        return [
            'status'     => $this->isConfigured() ? DeploymentHealthStatus::HEALTHY : DeploymentHealthStatus::MISSING,
            'configured' => $this->isConfigured(),
        ];
    }
}
