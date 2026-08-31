<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\ModuleAssetVersion;
use Opencart\System\Library\Extension\MtUniCredit\ProductBuyCheckoutPreference;

/**
 * Product „Купи“ → Checkout preference: payment preselect + cleanup across OC4 rerenders.
 */
class MtUniCreditProductBuy extends \Opencart\System\Engine\Controller
{
    /**
     * Ensure Checkout handoff JS is present (syncs #input-payment-code after native resets).
     *
     * @param array<int, mixed> $args
     */
    public function onCheckoutPageBefore(string &$route, array &$args): void
    {
        $this->document->addScript(
            ModuleAssetVersion::href('catalog/view/javascript/mt_uni_credit_checkout_handoff.js'),
            'footer'
        );
    }

    /**
     * After payment methods are discovered, prefer UniCredit when Product Buy handoff is active.
     *
     * Also reorders UniCredit first and annotates preferred payment for Checkout handoff JS
     * (native payment modal checks #input-payment-code / first radio).
     *
     * @param array<int, mixed> $args
     * @param mixed             $output JSON response body from payment_method.getMethods
     */
    public function onPaymentMethodsAfter(string &$route, array &$args, mixed &$output): void
    {
        if (!is_string($output) || $output === '') {
            return;
        }

        $json = json_decode($output, true);
        if (!is_array($json) || empty($json['payment_methods']) || !is_array($json['payment_methods'])) {
            return;
        }

        $storeId = (int) $this->config->get('config_store_id');
        $json = ProductBuyCheckoutPreference::enrichPaymentMethodsResponse(
            $this->session->data,
            $json,
            $storeId
        );
        $output = json_encode($json);
    }

    /**
     * After shipping_method.save, native OC4 unsets payment_method + payment_methods.
     * Product Buy preference must survive; payment is re-applied on the next getMethods.
     * Annotate response so handoff JS can clear stale non-UniCredit #input-payment-code.
     *
     * @param array<int, mixed> $args
     * @param mixed             $output JSON response body from shipping_method.save
     */
    public function onShippingMethodSaveAfter(string &$route, array &$args, mixed &$output): void
    {
        if (!is_string($output) || $output === '') {
            return;
        }

        $json = json_decode($output, true);
        if (!is_array($json) || empty($json['success'])) {
            return;
        }

        $storeId = (int) $this->config->get('config_store_id');
        $preference = ProductBuyCheckoutPreference::load($this->session->data, $storeId);
        if ($preference === null || !ProductBuyCheckoutPreference::shouldPreferPayment($preference)) {
            return;
        }

        // Intent survives; native payment_methods are empty until getMethods.
        // Signal Checkout UI to clear payment display so first-radio / getMethods path can re-apply.
        $json[ProductBuyCheckoutPreference::JSON_PREFERRED_PAYMENT_KEY] = [
            'code'   => (string) ($preference['payment_code'] ?? \Opencart\System\Library\Extension\MtUniCredit\ModuleConstants::PAYMENT_OPTION_CODE),
            'name'   => \Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity::DISPLAY_NAME,
            'pending' => true,
        ];
        $output = json_encode($json);
    }

    /**
     * After customer saves a payment method, drop Product Buy preference if they left UniCredit.
     * Automatic UniCredit session apply does not go through payment_method.save.
     *
     * @param array<int, mixed> $args
     * @param mixed             $output
     */
    public function onPaymentMethodSaveAfter(string &$route, array &$args, mixed &$output): void
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
