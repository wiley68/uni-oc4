<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * AES-256-GCM encryption for values stored in oc_setting.
 *
 * Accepts a 32-byte key from {@see ModuleEncryptionKeyProvider::resolveDerivedKey()}.
 */
final class ModuleSettingCipher
{
    private const PREFIX = 'enc:v1:';

    private string $key;

    public function __construct(string $derivedKey)
    {
        if (strlen($derivedKey) !== 32) {
            throw new \InvalidArgumentException('AES-256 key must be exactly 32 bytes.');
        }

        $this->key = $derivedKey;
    }

    public static function encryptedPrefix(): string
    {
        return self::PREFIX;
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Setting encryption failed.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw new \RuntimeException('Encrypted setting has invalid prefix.');
        }

        $raw = base64_decode(substr($encoded, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Encrypted setting payload is invalid.');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Setting decryption failed.');
        }

        return $plaintext;
    }
}
