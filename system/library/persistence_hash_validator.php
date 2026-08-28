<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Validates opaque SHA-256 hex hashes persisted by repositories.
 */
final class PersistenceHashValidator
{
    public static function isSha256Hex(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $value);
    }

    public static function requireSha256Hex(string $value, string $label): string
    {
        if (!self::isSha256Hex($value)) {
            throw new PersistenceValidationException($label . ' must be a 64-character lowercase SHA-256 hex string.');
        }

        return $value;
    }
}
