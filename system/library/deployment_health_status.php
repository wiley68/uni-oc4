<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Controlled deployment/configuration health status codes for admin and future storefront gates.
 */
final class DeploymentHealthStatus
{
    public const HEALTHY = 'healthy';
    public const MISSING = 'missing';
    public const INVALID = 'invalid';
    public const UNREADABLE = 'unreadable';
    public const EXPIRED = 'expired';
    public const NOT_YET_VALID = 'not_yet_valid';
    public const MISMATCH = 'mismatch';
    public const UNKNOWN = 'unknown';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::HEALTHY,
            self::MISSING,
            self::INVALID,
            self::UNREADABLE,
            self::EXPIRED,
            self::NOT_YET_VALID,
            self::MISMATCH,
            self::UNKNOWN,
        ];
    }
}
