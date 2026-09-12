<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Store-scoped SmartUCF credential pair — encrypted at rest via ModuleSettingCipher.
 *
 * Distinct from ModuleCredentialsRepository (UNICID + CP login secret).
 */
final class SmartUcfCredentialRepository
{
    public const USER_SETTING = ModuleConstants::MODULE_SETTING_CODE . '_smartucf_user';

    public const PASSWORD_SETTING = ModuleConstants::MODULE_SETTING_CODE . '_smartucf_password';

    private ModuleSettingStore $settings;

    private ModuleSettingCipher $cipher;

    public function __construct(ModuleSettingStore $settings, ModuleSettingCipher $cipher)
    {
        $this->settings = $settings;
        $this->cipher = $cipher;
    }

    /**
     * Exact raw stored envelopes (or null when unset) for rollback / diagnostics.
     *
     * @return array{user: ?string, password: ?string}
     */
    public function captureRawPair(int $storeId): array
    {
        $values = $this->settings->getMany($storeId, [self::USER_SETTING, self::PASSWORD_SETTING]);

        return [
            'user' => $this->normalizeRaw($values[self::USER_SETTING] ?? null),
            'password' => $this->normalizeRaw($values[self::PASSWORD_SETTING] ?? null),
        ];
    }

    /**
     * @param array{user: ?string, password: ?string} $prior
     */
    public function restoreRawPair(int $storeId, array $prior): void
    {
        $this->writeRaw($storeId, self::USER_SETTING, $prior['user'] ?? null);
        $this->writeRaw($storeId, self::PASSWORD_SETTING, $prior['password'] ?? null);
    }

    public function saveCompletePair(int $storeId, string $username, string $password): void
    {
        $username = trim($username);
        $password = trim($password);
        if ($username === '' || $password === '') {
            throw new \InvalidArgumentException('SmartUCF credential pair must be complete and non-empty.');
        }

        // Encrypt both before any persistent write.
        $encryptedUser = $this->cipher->encrypt($username);
        $encryptedPassword = $this->cipher->encrypt($password);

        $this->settings->set($storeId, self::USER_SETTING, $encryptedUser);
        $this->settings->set($storeId, self::PASSWORD_SETTING, $encryptedPassword);
    }

    /**
     * Write pre-encrypted envelopes (used when encrypt-before-write and atomic restore need identical bytes).
     */
    public function replaceEncryptedPair(int $storeId, string $encryptedUser, string $encryptedPassword): void
    {
        if (!str_starts_with($encryptedUser, ModuleSettingCipher::encryptedPrefix())
            || !str_starts_with($encryptedPassword, ModuleSettingCipher::encryptedPrefix())
        ) {
            throw new \InvalidArgumentException('SmartUCF credential envelopes must use enc:v1:.');
        }

        $this->settings->set($storeId, self::USER_SETTING, $encryptedUser);
        $this->settings->set($storeId, self::PASSWORD_SETTING, $encryptedPassword);
    }

    public function getUsername(int $storeId): ?string
    {
        $pair = $this->decryptPair($storeId);

        return $pair['user'];
    }

    public function getPassword(int $storeId): ?string
    {
        $pair = $this->decryptPair($storeId);

        return $pair['password'];
    }

    public function hasCompleteReadablePair(int $storeId): bool
    {
        $pair = $this->decryptPair($storeId);

        return $pair['user'] !== null && $pair['password'] !== null;
    }

    public function deletePair(int $storeId): void
    {
        $this->settings->delete($storeId, self::USER_SETTING);
        $this->settings->delete($storeId, self::PASSWORD_SETTING);
    }

    /**
     * Inject decrypted credentials into a runtime shop context. Never hydrates only one side.
     *
     * @param array<string, mixed> $shopData
     * @return array<string, mixed>
     */
    public function hydrateShopSnapshot(int $storeId, array $shopData): array
    {
        $pair = $this->decryptPair($storeId);
        unset($shopData['uni_user'], $shopData['uni_password']);

        if ($pair['user'] === null || $pair['password'] === null) {
            return $shopData;
        }

        $shopData['uni_user'] = $pair['user'];
        $shopData['uni_password'] = $pair['password'];

        return $shopData;
    }

    /**
     * Decrypt both credentials from one getMany snapshot — never exposes a one-sided pair.
     *
     * @return array{user: ?string, password: ?string}
     */
    private function decryptPair(int $storeId): array
    {
        $raw = $this->captureRawPair($storeId);
        $user = $this->decryptRaw($raw['user']);
        $password = $this->decryptRaw($raw['password']);
        if ($user === null || $password === null) {
            return ['user' => null, 'password' => null];
        }

        return ['user' => $user, 'password' => $password];
    }

    private function decryptRaw(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        if (!str_starts_with($stored, ModuleSettingCipher::encryptedPrefix())) {
            return null;
        }
        try {
            $plain = $this->cipher->decrypt($stored);
        } catch (\Throwable $exception) {
            return null;
        }
        $plain = trim($plain);

        return $plain !== '' ? $plain : null;
    }

    private function normalizeRaw(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }

    private function writeRaw(int $storeId, string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            $this->settings->delete($storeId, $key);

            return;
        }

        $this->settings->set($storeId, $key, $value);
    }
}
