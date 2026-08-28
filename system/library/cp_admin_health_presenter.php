<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Safe admin CP/auth/cache health fields — never exposes bearer token or secret. */
final class CpAdminHealthPresenter
{
    private ModuleCredentialsRepository $credentials;

    private CpTokenRepository $tokens;

    private ShopConfigurationService $shopConfiguration;

    private ModuleDeploymentEnvironment $environment;

    private int $storeId;

    public function __construct(
        ModuleCredentialsRepository $credentials,
        CpTokenRepository $tokens,
        ShopConfigurationService $shopConfiguration,
        int $storeId,
        ?ModuleDeploymentEnvironment $environment = null
    ) {
        $this->credentials = $credentials;
        $this->tokens = $tokens;
        $this->shopConfiguration = $shopConfiguration;
        $this->storeId = $storeId;
        $this->environment = $environment ?? new ModuleDeploymentEnvironment();
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        $unicid = $this->credentials->getUnicid($this->storeId);
        $expiresAt = $this->tokens->getExpiresAt();
        $now = time();
        $metadata = $this->shopConfiguration->getMetadata();

        $authState = 'missing_credentials';
        if ($this->credentials->hasCompleteCredentials($this->storeId)) {
            if ($this->tokens->hasToken()) {
                $authState = $expiresAt > $now ? 'authenticated' : 'expired';
            } else {
                $authState = 'disconnected';
            }
        }

        return [
            'cp_host'              => $this->environment->controlPanelHost(),
            'unicid'               => $unicid !== '' ? $unicid : null,
            'secret_configured' => $this->credentials->hasSecret($this->storeId),
            'secret_readable'   => $this->credentials->isSecretReadable($this->storeId),
            'auth_state'           => $authState,
            'token_expires_at'     => $this->tokens->hasToken() && $expiresAt > 0
                ? gmdate('Y-m-d H:i:s', $expiresAt) . ' UTC'
                : null,
            'token_expired'        => $this->tokens->hasToken() && $expiresAt > 0 && $expiresAt <= $now,
            'cache_present'        => $metadata !== null,
            'cache_fetched_at'     => $metadata['fetched_at'] ?? null,
            'cache_expires_at'     => $metadata['expires_at'] ?? null,
            'cache_fresh'          => (bool) ($metadata['is_fresh'] ?? false),
        ];
    }
}
