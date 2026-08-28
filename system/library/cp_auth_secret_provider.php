<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Deployment CP API secret from secrets/cp-auth.php (manual, gitignored).
 *
 * Operational consistency: sensitive CP credentials are not admin-editable settings.
 */
class CpAuthSecretProvider
{
    public const RELATIVE_PATH = 'secrets/cp-auth.php';

    public function isConfigured(): bool
    {
        return $this->getSecret() !== null;
    }

    public function getSecret(): ?string
    {
        $path = ExtensionRoot::path() . '/' . self::RELATIVE_PATH;
        if (!is_file($path)) {
            return null;
        }

        $payload = include $path;
        if (!is_array($payload)) {
            return null;
        }

        $secret = isset($payload['secret']) ? trim((string) $payload['secret']) : '';

        return $secret !== '' ? $secret : null;
    }

    /** @return array{status: string, configured: bool} */
    public function health(): array
    {
        $configured = $this->isConfigured();

        return [
            'status'     => $configured ? DeploymentHealthStatus::HEALTHY : DeploymentHealthStatus::MISSING,
            'configured' => $configured,
        ];
    }
}
