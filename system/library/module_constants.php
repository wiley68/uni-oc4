<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Central extension identity constants (Phase 1).
 *
 * Future payment method code remains {@see ModuleConstants::PAYMENT_CODE} with option
 * {@see ModuleConstants::PAYMENT_OPTION_CODE} ({code}.{option}).
 */
final class ModuleConstants
{
    public const EXTENSION_CODE = 'mt_uni_credit';

    public const VERSION = '2.0.2';

    public const MODULE_SETTING_CODE = 'module_mt_uni_credit';

    public const ADMIN_ROUTE = 'extension/mt_uni_credit/module/mt_uni_credit';

    /** Future catalog payment route prefix: extension/mt_uni_credit/payment/mt_uni_credit */
    public const PAYMENT_CODE = 'mt_uni_credit';

    public const PAYMENT_OPTION_CODE = 'mt_uni_credit.mt_uni_credit';

    public const AUTHOR = 'Авалон ООД';
}
