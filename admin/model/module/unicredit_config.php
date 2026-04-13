<?php

namespace Opencart\Admin\Model\Extension\MtUniCredit\Module;

/**
 * Централни константи за mt_uni_credit (имена на таблици без DB_PREFIX).
 */
final class UnicreditConfig
{
    /** Ключ на модулните настройки в config/setting. */
    public const MODULE_SETTING_KEY = 'module_mt_uni_credit';

    /** Версия на модула (използва се за UI и мета данни). */
    public const MODULE_VERSION = '1.4.1';

    /** Фиксиран курс EUR/BGN за изчисленията в модула. */
    public const EUR_BGN_RATE = 1.95583;

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

    /** TTL на кеша за коефициентите от банката (секунди). */
    public const API_CACHE_TTL_COEFF = 600;

    /** Предпочитани месеци в чекаута — съвпада с PrestaShop UniPayment::BROWSER_COOKIE_CHECKOUT_INSTALLMENTS. */
    public const BROWSER_COOKIE_CHECKOUT_INSTALLMENTS = 'unipayment_pc_inst';

    /** Флаг „поток от UniCredit / купи на изплащане“ — съвпада с PrestaShop бисквитка unipayment_pc. */
    public const BROWSER_COOKIE_CHECKOUT_FLAG = 'unipayment_pc';

    /** TTL на тези бисквитки (секунди), като PS UNIPAYMENT_CHECKOUT_BROWSER_COOKIE_TTL (1800). */
    public const CHECKOUT_BROWSER_COOKIE_TTL = 1800;

    /** TTL на browser cookie за клиентски installment intent (секунди). */
    public const BROWSER_COOKIE_CART_INTENT_TTL = 3600;

    private function __construct() {}
}
