<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Redacts sensitive keys from diagnostic payloads returned to CP.
 */
final class DiagnosticPayloadRedactor
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'egn',
        'EGN',
        'clientEGN',
        'email',
        'telephone',
        'phone',
        'clientPhone',
        'phone2',
        'address',
        'address1',
        'address2',
        'address_1',
        'address_2',
        'Authorization',
        'authorization',
        'access_token',
        'refresh_token',
        'secret',
        'secret_key',
        'password',
        'passphrase',
        'private_key',
        'certificate',
        'Bearer',
    ];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::SENSITIVE_KEYS, true)) {
                $data[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
