<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\ProductBuyCheckoutPreference;

/**
 * Product „Купи“ → Checkout preference: payment preselect + cleanup.
 */
class MtUniCreditProductBuy extends \Opencart\System\Engine\Controller
{
    /**
     * After payment methods are discovered, prefer UniCredit when Product Buy handoff is active.
     *
     * @param array<int, mixed> $args
     */
    public function onPaymentMethodsAfter(string &$route, array &$args, &$output): void
    {
        if (!is_string($output) || $output === '') {
            return;
        }

        $json = json_decode($output, true);
        if (!is_array($json) || empty($json['payment_methods']) || !is_array($json['payment_methods'])) {
            return;
        }

        $storeId = (int) $this->config->get('config_store_id');
        $this->session->data['payment_methods'] = $json['payment_methods'];
        ProductBuyCheckoutPreference::applyPaymentIfAvailable(
            $this->session->data,
            $json['payment_methods'],
            $storeId
        );
    }

    /**
     * After customer saves a payment method, drop Product Buy preference if they left UniCredit.
     *
     * @param array<int, mixed> $args
     */
    public function onPaymentMethodSaveAfter(string &$route, array &$args, &$output): void
    {
        ProductBuyCheckoutPreference::clearIfPaymentChangedAway($this->session->data);
    }

    /**
     * Checkout success clears any leftover Product Buy preference.
     *
     * @param array<int, mixed> $args
     */
    public function onCheckoutSuccessBefore(string &$route, array &$args): void
    {
        ProductBuyCheckoutPreference::clear($this->session->data);
    }
}
