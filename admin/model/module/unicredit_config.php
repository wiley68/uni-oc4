<?php

namespace Opencart\Admin\Model\Extension\MtUniCredit\Module;

/**
 * Централни константи за mt_uni_credit (имена на таблици без DB_PREFIX).
 */
final class UnicreditConfig
{
    /** Таблица за мапинг категория (OpenCart) → КОП / промо / кеш полета от банката. */
    public const TABLE_KOP_MAPPING = 'mt_uni_credit_kop_mapping';

    /** Таблица за API кеш (params / calc / coeff и др.). */
    public const TABLE_API_CACHE = 'mt_uni_credit_api_cache';

    private function __construct() {}
}
