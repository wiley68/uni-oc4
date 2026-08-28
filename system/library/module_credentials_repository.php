<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Local UNICID and CP login secret (operator-configured, encrypted at rest).
 *
 * Operational model aligned with uni-ps9 ConfigurationRepository / Woo Mtuc_Settings.
 */
final class ModuleCredentialsRepository
{
    public const UNICID_SETTING = ModuleConstants::MODULE_SETTING_CODE . '_unicid';

    public const SECRET_SETTING = ModuleConstants::MODULE_SETTING_CODE . '_secret';

    private ModuleSettingStore $settings;

    private ModuleSettingCipher $cipher;

    public function __construct(ModuleSettingStore $settings, ModuleSettingCipher $cipher)
    {
        $this->settings = $settings;
        $this->cipher = $cipher;
    }

    public function getUnicid(int $storeId): string
    {
        return trim((string) ($this->settings->get($storeId, self::UNICID_SETTING) ?? ''));
    }

    public function setUnicid(int $storeId, string $unicid): void
    {
        $this->settings->set($storeId, self::UNICID_SETTING, trim($unicid));
    }

    public function getSecret(int $storeId): ?string
    {
        $storedSecret = (string) ($this->settings->get($storeId, self::SECRET_SETTING) ?? '');
        if ($storedSecret === '') {
            return null;
        }

        if (!str_starts_with($storedSecret, ModuleSettingCipher::encryptedPrefix())) {
            return null;
        }

        try {
            $decrypted = $this->cipher->decrypt($storedSecret);
        } catch (\Throwable $exception) {
            return null;
        }

        return is_string($decrypted) && $decrypted !== '' ? $decrypted : null;
    }

    public function hasSecret(int $storeId): bool
    {
        return $this->getSecret($storeId) !== null;
    }

    public function isSecretReadable(int $storeId): bool
    {
        $storedSecret = (string) ($this->settings->get($storeId, self::SECRET_SETTING) ?? '');

        return $storedSecret === '' || $this->getSecret($storeId) !== null;
    }

    public function saveSecret(int $storeId, string $plainSecret): void
    {
        $plainSecret = trim($plainSecret);
        if ($plainSecret === '') {
            return;
        }

        $this->settings->set(
            $storeId,
            self::SECRET_SETTING,
            $this->cipher->encrypt($plainSecret)
        );
    }

    public function hasCompleteCredentials(int $storeId): bool
    {
        return $this->getUnicid($storeId) !== '' && $this->hasSecret($storeId);
    }
}
