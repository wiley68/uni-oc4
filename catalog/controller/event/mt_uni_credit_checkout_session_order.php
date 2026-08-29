<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\CheckoutSessionOrderGuard;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleLocalSettings;

/**
 * Keeps session.order_id aligned with the live cart for all payment modules.
 *
 * Native OC4.1 cart add/edit/remove clear payment_method but leave order_id.
 * After confirm editOrder() voids the draft, a later cart change leaves a Voided
 * stale order_id that confirm will not replace (editOrder only when status==0).
 */
class MtUniCreditCheckoutSessionOrder extends \Opencart\System\Engine\Controller
{
    /**
     * catalog/controller/checkout/cart/add|edit|remove/after
     *
     * @param mixed $output
     */
    public function onCartMutated(string &$route, array &$args, &$output): void
    {
        if (!$this->config->get(ModuleConstants::MODULE_SETTING_CODE . '_status')) {
            return;
        }

        $cleared = CheckoutSessionOrderGuard::invalidateOnCartMutation($this->session->data);
        if ($cleared && (bool) $this->config->get(ModuleLocalSettings::DEBUG_ENABLED)) {
            $this->log->write(
                '[mt_uni_credit] cleared session.order_id after cart mutation route=' . $route
            );
        }
    }

    /**
     * catalog/controller/checkout/confirm/before — last chance before payment render.
     */
    public function onConfirmBefore(string &$route, array &$args): void
    {
        if ($route !== 'checkout/confirm') {
            return;
        }
        if (!$this->config->get(ModuleConstants::MODULE_SETTING_CODE . '_status')) {
            return;
        }
        if (!isset($this->session->data['order_id'])) {
            return;
        }

        $orderId = (int) $this->session->data['order_id'];
        if ($orderId <= 0) {
            unset($this->session->data['order_id']);

            return;
        }

        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($orderId) ?: null;
        $orderProducts = $order ? ($this->model_checkout_order->getProducts($orderId) ?: []) : [];
        $cartProducts = $this->cart->getProducts();
        $cartTotal = (float) $this->cart->getTotal();
        $currency = (string) ($this->session->data['currency'] ?? $this->config->get('config_currency'));

        $getOptions = function (int $oid, int $orderProductId): array {
            return $this->model_checkout_order->getOptions($oid, $orderProductId) ?: [];
        };

        $cleared = CheckoutSessionOrderGuard::reconcileSessionOrder(
            $this->session->data,
            is_array($order) ? $order : null,
            $orderProducts,
            $getOptions,
            $cartProducts,
            $cartTotal,
            $currency
        );

        if ($cleared && (bool) $this->config->get(ModuleLocalSettings::DEBUG_ENABLED)) {
            $this->log->write(
                '[mt_uni_credit] cleared stale session.order_id before confirm order_id=' . $orderId
                . ' cart_total=' . $cartTotal
            );
        }
    }
}
