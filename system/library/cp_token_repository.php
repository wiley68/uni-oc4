<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Encrypted store-scoped CP bearer token persistence in oc_setting.
 */
final class CpTokenRepository
{
    public const ACCESS_TOKEN = ModuleConstants::MODULE_SETTING_CODE . '_cp_access_token';

    public const TOKEN_TYPE = ModuleConstants::MODULE_SETTING_CODE . '_cp_token_type';

    public const EXPIRES_AT = ModuleConstants::MODULE_SETTING_CODE . '_cp_token_expires_at';

    private ModuleSettingStore $settings;

    private ModuleSettingCipher $cipher;

    private int $storeId;

    public function __construct(ModuleSettingStore $settings, ModuleSettingCipher $cipher, int $storeId)
    {
        $this->settings = $settings;
        $this->cipher = $cipher;
        $this->storeId = $storeId;
    }

    public function save(string $accessToken, string $tokenType, int $expiresAt): bool
    {
        if ($accessToken === '' || $expiresAt <= 0) {
            return false;
        }

        $this->settings->set($this->storeId, self::ACCESS_TOKEN, $this->cipher->encrypt($accessToken));
        $this->settings->set($this->storeId, self::TOKEN_TYPE, $tokenType !== '' ? $tokenType : 'Bearer');
        $this->settings->set($this->storeId, self::EXPIRES_AT, (string) $expiresAt);

        return true;
    }

    public function getAccessToken(): ?string
    {
        $stored = (string) ($this->settings->get($this->storeId, self::ACCESS_TOKEN) ?? '');
        if ($stored === '' || !str_starts_with($stored, ModuleSettingCipher::encryptedPrefix())) {
            return null;
        }

        try {
            $token = $this->cipher->decrypt($stored);
        } catch (\Throwable $exception) {
            return null;
        }

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function getTokenType(): string
    {
        $tokenType = trim((string) ($this->settings->get($this->storeId, self::TOKEN_TYPE) ?? ''));

        return $tokenType !== '' ? $tokenType : 'Bearer';
    }

    public function getExpiresAt(): int
    {
        return (int) ($this->settings->get($this->storeId, self::EXPIRES_AT) ?? 0);
    }

    public function hasToken(): bool
    {
        return $this->getAccessToken() !== null;
    }

    public function invalidate(): void
    {
        foreach ([self::ACCESS_TOKEN, self::TOKEN_TYPE, self::EXPIRES_AT] as $key) {
            $this->settings->delete($this->storeId, $key);
        }
    }
}
