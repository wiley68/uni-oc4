<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Central extension identity constants (Phase 1).
 *
 * Catalog payment method code {@see ModuleConstants::PAYMENT_CODE} with option
 * {@see ModuleConstants::PAYMENT_OPTION_CODE} ({code}.{option}).
 */
final class ModuleConstants
{
    public const EXTENSION_CODE = 'mt_uni_credit';

    public const VERSION = '2.0.2';

    public const MODULE_SETTING_CODE = 'module_mt_uni_credit';

    public const ADMIN_ROUTE = 'extension/mt_uni_credit/module/mt_uni_credit';

    /** Catalog payment route: extension/mt_uni_credit/payment/mt_uni_credit */
    public const PAYMENT_CODE = 'mt_uni_credit';

    public const PAYMENT_OPTION_CODE = 'mt_uni_credit.mt_uni_credit';

    /**
     * UniCredit payment method „Състояние на поръчката“
     * (`payment_mt_uni_credit_order_status_id`) — Product/Cart post-materialization source.
     * Duplicate module key `module_mt_uni_credit_awaiting_financing_order_status_id` is removed.
     */
    public const PAYMENT_ORDER_STATUS_SETTING = 'payment_mt_uni_credit_order_status_id';

    public const AUTHOR = 'Авалон ООД';
}
