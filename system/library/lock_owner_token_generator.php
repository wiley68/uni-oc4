<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Cryptographically random operation lock owner tokens (32 lowercase hex).
 */
final class LockOwnerTokenGenerator
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(SecurityConstants::LOCK_OWNER_TOKEN_BYTES));
    }

    public static function isValidFormat(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $token);
    }
}
