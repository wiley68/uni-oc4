<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Opaque server-issued submission tokens (64 lowercase hex).
 */
final class SubmissionTokenGenerator
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(SecurityConstants::SUBMISSION_TOKEN_BYTES));
    }

    public static function isValidFormat(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $token);
    }
}
