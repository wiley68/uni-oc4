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
        'clientphone',
        'phone2',
        'address',
        'address1',
        'address2',
        'address_1',
        'address_2',
        'clientdeliveryaddress',
        'clientemail',
        'clientfirstname',
        'clientlastname',
        'Authorization',
        'authorization',
        'access_token',
        'refresh_token',
        'secret',
        'secret_key',
        'cp_secret',
        'encryption_key',
        'password',
        'pass',
        'passphrase',
        'private_key',
        'private_key_pem',
        'certificate',
        'certificate_pem',
        'certificate_password',
        'Bearer',
        'bearer',
        'token',
        'user',
    ];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $data[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $data[$key] = self::redact($value);
                continue;
            }
            if (is_string($value)) {
                $data[$key] = self::redactText($value);
            }
        }

        return $data;
    }

    public static function redactMixed(mixed $value): mixed
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                try {
                    $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        return self::redact($decoded);
                    }
                } catch (\JsonException $exception) {
                    return self::redactText($value);
                }
            }

            return self::redactText($value);
        }
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return self::redact($value);
        }

        return $value;
    }

    public static function redactText(string $value): string
    {
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value) ?? '[REDACTED]';

        return preg_replace(
            '/\b(secret|token|password|pass|private[_ -]?key)\b\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $value
        ) ?? '[REDACTED]';
    }

    private static function isSensitiveKey(string $key): bool
    {
        if (in_array($key, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        return in_array(strtolower($key), self::SENSITIVE_KEYS, true);
    }
}
