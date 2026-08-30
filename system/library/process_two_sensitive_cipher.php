<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Encrypts Process 2 EGN/phone2 for durable attempt storage (PS9 sensitive_payload parity).
 */
final class ProcessTwoSensitiveCipher
{
    public const DERIVATION_INFO = 'mt_uni_credit/process2-sensitive/v1';

    private ModuleSettingCipher $cipher;

    public function __construct(?string $secretInputOverride = null)
    {
        if ($secretInputOverride === null) {
            try {
                $secretInputOverride = (new ModuleEncryptionKeyProvider())->resolveSecretInput();
            } catch (\Throwable) {
                $secretInputOverride = ModuleEncryptionKeyProvider::testSecretInput();
            }
        }
        $key = hash_hkdf(
            'sha256',
            $secretInputOverride,
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
