<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\ModuleAssetVersion;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductBuyCheckoutPreference;

/**
 * Product „Купи“ → Checkout preference: payment preselect + cleanup across OC4 rerenders.
 *
 * OC4 JSON endpoints (payment_method.getMethods, shipping_method.save) return void and write
 * the body via Response::setOutput(). Controller /after $output is therefore null — hooks must
 * read/write $this->response, not the unused $output argument.
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
     * @param mixed             $output Unused for OC4 JSON controllers (void return); body is in Response.
     */
    public function onPaymentMethodsAfter(string &$route, array &$args, mixed &$output): void
    {
        $raw = $this->readJsonResponseBody($output);
        if ($raw === null) {
            return;
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['payment_methods']) || !is_array($json['payment_methods'])) {
            return;
        }

        $storeId = (int) $this->config->get('config_store_id');
        $json = ProductBuyCheckoutPreference::enrichPaymentMethodsResponse(
            $this->session->data,
            $json,
            $storeId
        );
        $this->writeJsonResponseBody($json, $output);
    }

    /**
     * After shipping_method.save, native OC4 unsets payment_method + payment_methods.
     * Product Buy preference must survive; payment is re-applied on the next getMethods.
     * Annotate response so handoff JS can clear stale non-UniCredit #input-payment-code.
     *
     * @param array<int, mixed> $args
     * @param mixed             $output Unused for OC4 JSON controllers (void return); body is in Response.
     */
    public function onShippingMethodSaveAfter(string &$route, array &$args, mixed &$output): void
    {
        $raw = $this->readJsonResponseBody($output);
        if ($raw === null) {
            return;
        }

        $json = json_decode($raw, true);
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
            'code'    => (string) ($preference['payment_code'] ?? ModuleConstants::PAYMENT_OPTION_CODE),
            'name'    => PaymentIdentity::DISPLAY_NAME,
            'pending' => true,
        ];
        $this->writeJsonResponseBody($json, $output);
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

    /**
     * OC4 catalog JSON endpoints put the body on Response and return void — $output is null.
     */
    private function readJsonResponseBody(mixed $output): ?string
    {
        $raw = $this->response->getOutput();
        if (is_string($raw) && $raw !== '') {
            return $raw;
        }
        if (is_string($output) && $output !== '') {
            return $output;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $json
     */
    private function writeJsonResponseBody(array $json, mixed &$output): void
    {
        $encoded = json_encode($json);
        if ($encoded === false) {
            return;
        }
        $this->response->setOutput($encoded);
        $output = $encoded;
    }
}
