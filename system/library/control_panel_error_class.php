<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Durable CP submission error taxonomy (Phase 10B).
 * Stored in financing_attempt.last_error_class — not customer-facing copy.
 */
final class ControlPanelErrorClass
{
    public const AUTH_FAILED = 'cp_auth_failed';
    public const TRANSPORT_FAILED = 'cp_transport_failed';
    public const TIMEOUT = 'cp_timeout';
    public const INVALID_RESPONSE = 'cp_invalid_response';
    public const REJECTED = 'cp_rejected';
    public const CONFLICT = 'cp_conflict';
    public const RECOVERY_FAILED = 'cp_recovery_failed';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::AUTH_FAILED,
            self::TRANSPORT_FAILED,
            self::TIMEOUT,
            self::INVALID_RESPONSE,
            self::REJECTED,
            self::CONFLICT,
            self::RECOVERY_FAILED,
        ];
    }

    public static function isValid(string $code): bool
    {
        return in_array($code, self::all(), true);
    }
}
