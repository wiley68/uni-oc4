<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Deterministic, scoped event definitions for this extension.
 *
 * Feature phases register Product, Cart, Checkout success, and checkout
 * session.order_id hygiene events here.
 *
 * @phpstan-type EventDefinition array{
 *     code: string,
 *     description: string,
 *     trigger: string,
 *     controller: string,
 *     method: string,
 *     status: bool,
 *     sort_order: int
 * }
 */
final class EventRegistry
{
    /**
     * @return list<EventDefinition>
     */
    public static function definitions(): array
    {
        return [
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_before_product_controller',
                'description' => 'UniCredit product page assets',
                'trigger'     => 'catalog/controller/product/product/before',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_product_controller',
                'method'      => 'init',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_after_product_view',
                'description' => 'UniCredit product calculator placement',
                'trigger'     => 'catalog/view/product/product/after',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_product_view',
                'method'      => 'init',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_before_cart_controller',
                'description' => 'UniCredit cart page assets',
                'trigger'     => 'catalog/controller/checkout/cart/before',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_cart_controller',
                'method'      => 'init',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_after_cart_view',
                'description' => 'UniCredit cart calculator placement',
                'trigger'     => 'catalog/view/checkout/cart/after',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_cart_view',
                'method'      => 'init',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_before_home_controller',
                'description' => 'UniCredit homepage advertising assets',
                'trigger'     => 'catalog/controller/common/home/before',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_home_controller',
                'method'      => 'beforeHome',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_after_home_footer',
                'description' => 'UniCredit homepage advertising markup',
                'trigger'     => 'catalog/view/common/footer/after',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_home_view',
                'method'      => 'afterFooter',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_before_checkout_success_view',
                'description' => 'UniCredit leasing block inside Thank You page body',
                'trigger'     => 'catalog/view/common/success/before',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_checkout_success',
                'method'      => 'beforeView',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_before_checkout_success',
                'description' => 'Stash order_id before checkout/success clears session',
                'trigger'     => 'catalog/controller/checkout/success/before',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_checkout_success_order',
                'method'      => 'before',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_after_cart_add',
                'description' => 'Clear stale session.order_id after cart add',
                'trigger'     => 'catalog/controller/checkout/cart/add/after',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_checkout_session_order',
                'method'      => 'onCartMutated',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_after_cart_edit',
                'description' => 'Clear stale session.order_id after cart edit',
                'trigger'     => 'catalog/controller/checkout/cart/edit/after',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_checkout_session_order',
                'method'      => 'onCartMutated',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_after_cart_remove',
                'description' => 'Clear stale session.order_id after cart remove',
                'trigger'     => 'catalog/controller/checkout/cart/remove/after',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_checkout_session_order',
                'method'      => 'onCartMutated',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_before_checkout_confirm',
                'description' => 'Invalidate stale session.order_id before confirm',
                'trigger'     => 'catalog/controller/checkout/confirm/before',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_checkout_session_order',
                'method'      => 'onConfirmBefore',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_before_checkout_handoff_js',
                'description' => 'Product Buy: Checkout handoff JS for payment reapply after native resets',
                'trigger'     => 'catalog/controller/checkout/checkout/before',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_product_buy',
                'method'      => 'onCheckoutPageBefore',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_after_payment_methods',
                'description' => 'Product Buy: prefer UniCredit payment when handoff is active',
                'trigger'     => 'catalog/controller/checkout/payment_method.getMethods/after',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_product_buy',
                'method'      => 'onPaymentMethodsAfter',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_after_shipping_method_save_buy',
                'description' => 'Product Buy: keep payment intent after native shipping_method.save reset',
                'trigger'     => 'catalog/controller/checkout/shipping_method.save/after',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_product_buy',
                'method'      => 'onShippingMethodSaveAfter',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_after_payment_method_save',
                'description' => 'Product Buy: clear preference when payment leaves UniCredit',
                'trigger'     => 'catalog/controller/checkout/payment_method.save/after',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_product_buy',
                'method'      => 'onPaymentMethodSaveAfter',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_before_checkout_success_clear_buy',
                'description' => 'Product Buy: clear checkout preference on Thank You',
                'trigger'     => 'catalog/controller/checkout/success/before',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_product_buy',
                'method'      => 'onCheckoutSuccessBefore',
                'status'      => true,
                'sort_order'  => 5,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_after_mail_order_add',
                'description' => 'UniCredit leasing block in customer order email',
                'trigger'     => 'catalog/view/mail/order_add/after',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_order_mail',
                'method'      => 'afterOrderAdd',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_after_mail_order_alert',
                'description' => 'UniCredit leasing block in admin order alert email',
                'trigger'     => 'catalog/view/mail/order_alert/after',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_order_mail',
                'method'      => 'afterOrderAlert',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_before_admin_order_list',
                'description' => 'Enrich Admin Orders with UniCredit bank status',
                'trigger'     => 'admin/view/sale/order_list/before',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_admin_order',
                'method'      => 'beforeOrderList',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_after_admin_order_list',
                'description' => 'Render UniCredit bank status column on Admin Orders',
                'trigger'     => 'admin/view/sale/order_list/after',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_admin_order',
                'method'      => 'afterOrderList',
                'status'      => true,
                'sort_order'  => 0,
            ],
            [
                'code'        => ModuleConstants::MODULE_SETTING_CODE . '_after_admin_order_info',
                'description' => 'UniCredit leasing section on Admin Order detail',
                'trigger'     => 'admin/view/sale/order_info/after',
                'controller'  => 'extension/mt_uni_credit/event/mt_uni_credit_admin_order',
                'method'      => 'afterOrderInfo',
                'status'      => true,
                'sort_order'  => 0,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function eventCodes(): array
    {
        $codes = [];
        foreach (self::definitions() as $definition) {
            $codes[] = $definition['code'];
        }

        return $codes;
    }

    /**
     * @return list<array{code: string, description: string, trigger: string, action: string, status: bool, sort_order: int}>
     */
    public static function openCartEventRows(?string $openCartVersion = null): array
    {
        $rows = [];
        foreach (self::definitions() as $definition) {
            $rows[] = [
                'code'        => $definition['code'],
                'description' => $definition['description'],
                'trigger'     => $definition['trigger'],
                'action'      => OpenCartCompatibility::eventAction(
                    $definition['controller'],
                    $definition['method'],
                    $openCartVersion
                ),
                'status'      => $definition['status'],
                'sort_order'  => $definition['sort_order'],
            ];
        }

        return $rows;
    }

    public static function isScopedEventCode(string $code): bool
    {
        return str_starts_with($code, ModuleConstants::MODULE_SETTING_CODE . '_');
    }
}
