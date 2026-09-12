<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Narrow shop-cache sanitizer: retain safe unknown fields, strip unknown secret-like keys.
 *
 * Known SmartUCF credential keys (uni_user / uni_password) are retained through ingress so the
 * shared credential partitioner can classify/persist them, then strip them from general shop_data.
 * Nested objects and list elements that are arrays are sanitized recursively.
 * Key matching normalizes camelCase / PascalCase / kebab-case / snake_case.
 */
final class ShopSnapshotSanitizer
{
    /** @var list<string> */
    private const KNOWN_SENSITIVE_SCHEMA_KEYS = [
        'uni_user',
        'uni_password',
    ];

    /**
     * Normalized token patterns that indicate secrets.
     * Short tokens require exact/prefix/suffix match to avoid stripping harmless fields
     * (e.g. "temp_email" must not match "pem").
     *
     * @var list<string>
     */
    private const SECRET_TOKENS_STRICT = [
        'pem',
        'cert',
        'pass',
        'token',
        'secret',
        'bearer',
    ];

    /**
     * @var list<string>
     */
    private const SECRET_TOKENS_CONTAINS = [
        'apikey',
        'accesstoken',
        'refreshtoken',
        'privatekey',
        'privatekeypem',
        'clientsecret',
        'beartoken',
        'bearertoken',
        'authorization',
        'password',
        'passwd',
        'passphrase',
        'certificate',
    ];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function sanitize(array $data): array
    {
        return self::sanitizeNode($data);
    }

    /**
     * @param array<mixed> $node
     * @return array<mixed>
     */
    private static function sanitizeNode(array $node): array
    {
        $out = [];
        $isList = $node === [] || array_is_list($node);

        foreach ($node as $key => $value) {
            if (!$isList) {
                if (!is_string($key)) {
                    continue;
                }
                if (self::shouldStripUnknownSensitive($key)) {
                    continue;
                }
            }

            if (is_array($value)) {
                $value = self::sanitizeNode($value);
            }

            if ($isList) {
                $out[] = $value;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function shouldStripUnknownSensitive(string $key): bool
    {
        if (in_array($key, self::KNOWN_SENSITIVE_SCHEMA_KEYS, true)) {
            return false;
        }

        $lowerExact = strtolower($key);
        if (in_array($lowerExact, self::KNOWN_SENSITIVE_SCHEMA_KEYS, true)) {
            return false;
        }

        $normalized = self::normalizeKey($key);
        if ($normalized === 'uniuser' || $normalized === 'unipassword') {
            return false;
        }

        foreach (self::SECRET_TOKENS_CONTAINS as $token) {
            if ($normalized === $token || str_contains($normalized, $token)) {
                return true;
            }
        }

        foreach (self::SECRET_TOKENS_STRICT as $token) {
            if (
                $normalized === $token
                || str_starts_with($normalized, $token)
                || str_ends_with($normalized, $token)
            ) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeKey(string $key): string
    {
        // Split camelCase / PascalCase boundaries, then strip separators.
        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key) ?? $key;
        $spaced = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $spaced) ?? $spaced;

        return strtolower(str_replace(['_', '-', ' '], '', $spaced));
    }
}
