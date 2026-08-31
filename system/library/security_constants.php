<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Frozen security/persistence timing constants (Phase 0 contracts).
 */
final class SecurityConstants
{
    public const NONCE_HEX_LENGTH = 64;
    public const NONCE_RETENTION_SECONDS = 900;
    public const OPERATION_LOCK_TTL_SECONDS = 45;
    public const SHOP_CACHE_TTL_SECONDS = 86400;
    public const FINANCING_ATTEMPT_ISSUED_TTL_SECONDS = 1800;
    public const SUBMISSION_TOKEN_BYTES = 32;
    public const LOCK_OWNER_TOKEN_BYTES = 16;
    public const HASH_HEX_LENGTH = 64;
    public const CLEANUP_DEFAULT_BATCH_SIZE = 100;

    /** Frozen leasing presentation JSON retention (~6 months). */
    public const PRESENTATION_RETENTION_DAYS = 183;
}
