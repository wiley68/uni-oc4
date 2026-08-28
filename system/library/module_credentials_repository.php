<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Local UNICID and deployment secret accessors (Phase 4). */
final class ModuleCredentialsRepository
{
    public const UNICID_SETTING = ModuleConstants::MODULE_SETTING_CODE . '_unicid';

    private ModuleSettingStore $settings;

    private CpAuthSecretProvider $secretProvider;

    public function __construct(ModuleSettingStore $settings, ?CpAuthSecretProvider $secretProvider = null)
    {
        $this->settings = $settings;
        $this->secretProvider = $secretProvider ?? new CpAuthSecretProvider();
    }

    public function getUnicid(int $storeId): string
    {
        return trim((string) ($this->settings->get($storeId, self::UNICID_SETTING) ?? ''));
    }

    public function setUnicid(int $storeId, string $unicid): void
    {
        $this->settings->set($storeId, self::UNICID_SETTING, trim($unicid));
    }

    public function getSecret(): ?string
    {
        return $this->secretProvider->getSecret();
    }

    public function hasSecret(): bool
    {
        return $this->secretProvider->isConfigured();
    }

    public function hasCompleteCredentials(int $storeId): bool
    {
        return $this->getUnicid($storeId) !== '' && $this->hasSecret();
    }
}
