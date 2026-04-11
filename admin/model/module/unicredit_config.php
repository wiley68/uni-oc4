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

    /** Базов URL на live услугата на банката. */
    public const LIVE_URL = 'https://unicreditconsumerfinancing.info';

    /** Път към JSON параметрите на магазина (като в PrestaShop модула). */
    public const BANK_GETPARAMETERS_PATH = '/function/getparameters.php';

    /** TTL на кеша за getparameters (секунди), както в PS модула. */
    public const API_CACHE_TTL_PARAMS = 600;

    private function __construct() {}
}
