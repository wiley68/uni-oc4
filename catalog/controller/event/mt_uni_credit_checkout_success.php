<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

/**
 * Checkout success message — catalog/view/common/success/after
 * (OpenCart 4.1 Success controller renders common/success).
 *
 * OpenCart 4.1.0.3 supplies: string &$route, array &$data, string &$output
 */
class MtUniCreditCheckoutSuccess extends \Opencart\System\Engine\Controller
{
    public function init(string &$route, array &$data, string &$output): void
    {
        if ($route !== 'common/success') {
            return;
        }

        $message = trim((string) ($this->session->data['mt_uni_credit_checkout_success'] ?? ''));
        if ($message === '') {
            return;
        }

        unset($this->session->data['mt_uni_credit_checkout_success']);

        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $alert = '<div class="alert alert-success alert-dismissible mt-uni-credit-checkout-success" role="alert">'
            . '<i class="fa-solid fa-circle-check"></i> ' . $safe
            . ' <button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
            . '</div>';

        $output = $alert . $output;
    }
}
