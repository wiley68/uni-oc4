<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Encrypts Process 2 EGN/phone2 for durable attempt storage (PS9 sensitive_payload parity).
 *
 * Production construction requires a resolvable deployment encryption secret.
 * Tests may pass an explicit secret; there is no predictable test-secret fallback.
 */
final class ProcessTwoSensitiveCipher
{
    public const DERIVATION_INFO = 'mt_uni_credit/process2-sensitive/v1';

    private ModuleSettingCipher $cipher;

    public function __construct(?string $secretInputOverride = null)
    {
        if ($secretInputOverride !== null) {
            if ($secretInputOverride === '') {
                throw new \RuntimeException('Process 2 sensitive encryption secret unavailable.');
            }
            $secretInput = $secretInputOverride;
        } else {
            $secretInput = (new ModuleEncryptionKeyProvider())->resolveSecretInput();
        }

        $key = hash_hkdf(
            'sha256',
            $secretInput,
            32,
            self::DERIVATION_INFO
        );
        if ($key === false) {
            throw new \RuntimeException('Process 2 sensitive key derivation failed.');
        }
        $this->cipher = new ModuleSettingCipher($key);
    }

    public function encrypt(ProcessTwoSensitiveData $data): string
    {
        return $this->cipher->encrypt(json_encode($data->toArray(), JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $encoded): ProcessTwoSensitiveData
    {
        $decoded = json_decode($this->cipher->decrypt($encoded), true);
        if (!is_array($decoded)
            || !isset($decoded['egn'], $decoded['phone2'])
            || !is_string($decoded['egn'])
            || !is_string($decoded['phone2'])
        ) {
            throw new \RuntimeException('Process 2 sensitive payload is malformed.');
        }

        return new ProcessTwoSensitiveData($decoded['egn'], $decoded['phone2']);
    }
}
