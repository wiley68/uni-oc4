<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Generalized financing attempt lifecycle states (Revision 1).
 */
final class FinancingAttemptState
{
    public const ISSUED = 'issued';
    public const VALIDATING = 'validating';
    public const ORDER_CREATING = 'order_creating';
    public const ORDER_CREATED = 'order_created';
    public const CP_SUBMITTING = 'cp_submitting';
    public const CP_CREATED = 'cp_created';
    public const CP_FAILED_RETRYABLE = 'cp_failed_retryable';
    public const CP_OUTCOME_UNKNOWN = 'cp_outcome_unknown';

    /**
     * Reserved top-level states — post-CP lifecycle is tracked in smartucf_state /
     * process2_state substates. Not written in Revision 1 production paths.
     */
    public const POST_CP_PROCESSING = 'post_cp_processing';
    public const COMPLETED = 'completed';
    public const TERMINAL_FAILED = 'terminal_failed';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::ISSUED,
            self::VALIDATING,
            self::ORDER_CREATING,
            self::ORDER_CREATED,
            self::CP_SUBMITTING,
            self::CP_CREATED,
            self::CP_FAILED_RETRYABLE,
            self::CP_OUTCOME_UNKNOWN,
            self::POST_CP_PROCESSING,
            self::COMPLETED,
            self::TERMINAL_FAILED,
        ];
    }

    public static function isValid(string $state): bool
    {
        return in_array($state, self::all(), true);
    }
}
