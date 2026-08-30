<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Module persistence table names (without DB_PREFIX).
 */
final class PersistenceTableNames
{
    public const SHOP_CACHE = 'mt_uni_credit_shop_cache';
    public const API_NONCE = 'mt_uni_credit_api_nonce';
    public const OPERATION_LOCK = 'mt_uni_credit_operation_lock';
    public const FINANCING_ATTEMPT = 'mt_uni_credit_financing_attempt';

    public const ORDER_CORRELATION = 'mt_uni_credit_order_correlation';

    public const ORDER_BANK_STATUS = 'mt_uni_credit_order_bank_status';

    public const DIAGNOSTIC_DEBUG_LOG = 'mt_uni_credit_diagnostic_debug_log';

    /** @return list<string> */
    public static function phase3Tables(): array
    {
        return [
            self::SHOP_CACHE,
            self::API_NONCE,
            self::OPERATION_LOCK,
            self::FINANCING_ATTEMPT,
        ];
    }

    /** @return list<string> */
    public static function phase6Tables(): array
    {
        return [self::ORDER_CORRELATION];
    }

    /** @return list<string> */
    public static function bridgeATables(): array
    {
        return [
            self::ORDER_BANK_STATUS,
            self::DIAGNOSTIC_DEBUG_LOG,
        ];
    }

    /** @return list<string> */
    public static function allPersistenceTables(): array
    {
        return array_merge(self::phase3Tables(), self::phase6Tables(), self::bridgeATables());
    }
}
