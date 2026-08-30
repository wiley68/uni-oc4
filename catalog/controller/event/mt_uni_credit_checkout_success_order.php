<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

/**
 * Stash order_id before OpenCart checkout/success unsets it.
 */
class MtUniCreditCheckoutSuccessOrder extends \Opencart\System\Engine\Controller
{
    public function before(string &$route, array &$args): void
    {
        if ($route !== 'checkout/success') {
            return;
        }
        $orderId = (int) ($this->session->data['order_id'] ?? 0);
        if ($orderId > 0) {
            $this->session->data['mt_uni_credit_success_order_id'] = $orderId;
        }
    }
}
