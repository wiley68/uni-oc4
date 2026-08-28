<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Derives the AES-256 key for encrypting module settings at rest.
 *
 * OpenCart 4.1 has no native installation encryption secret (unlike PrestaShop
 * {@see _NEW_COOKIE_KEY_}). The provider uses the standard {@see DB_PASSWORD}
 * constant from config.php — a genuine deployment secret outside oc_setting.
 */
final class ModuleEncryptionKeyProvider
{
    public const DERIVATION_INFO = 'mt_uni_credit/settings-encryption/v1';

    private const KEY_LENGTH = 32;

    public function resolveSecretInput(): string
    {
        if (defined('DB_PASSWORD') && \DB_PASSWORD !== '') {
            return \DB_PASSWORD;
        }

        throw new \RuntimeException('Module encryption secret unavailable.');
    }

    /**
     * @param string|null $secretInputOverride PHPUnit-only override of the installation secret.
     */
    public function resolveDerivedKey(?string $secretInputOverride = null): string
    {
        $secret = $secretInputOverride ?? $this->resolveSecretInput();
        $derived = hash_hkdf('sha256', $secret, self::KEY_LENGTH, self::DERIVATION_INFO);
        if ($derived === false) {
            throw new \RuntimeException('Module encryption key derivation failed.');
        }

        return $derived;
    }

    public static function testSecretInput(): string
    {
        return 'phase4-test-installation-db-password-secret';
    }
}
