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
 *
 * getMethods / shipping_method.save $output arg is Unused for OC4 JSON controllers (void return).
 */
class MtUniCreditProductBuy extends \Opencart\System\Engine\Controller
{
    /**
     * Ensure Checkout handoff JS is present; restore preference if a concurrent session race wiped it.
     *
     * @param array<int, mixed> $args
     */
    public function onCheckoutPageBefore(string &$route, array &$args): void
    {
        $storeId = (int) $this->config->get('config_store_id');
        $this->restoreHandoffCookieIfNeeded($storeId);

        $this->document->addScript(
            ModuleAssetVersion::href('catalog/view/javascript/mt_uni_credit_checkout_handoff.js'),
            'footer'
        );
    }

    /**
     * After payment methods are discovered, prefer UniCredit when Product Buy handoff is active.
     *
     * @param array<int, mixed> $args
     * @param mixed             $output Unused for OC4 JSON controllers (void return); body is in Response.
     */
    public function onPaymentMethodsAfter(string &$route, array &$args, mixed &$output): void
    {
        $storeId = (int) $this->config->get('config_store_id');
        $this->restoreHandoffCookieIfNeeded($storeId);

        $raw = $this->readJsonResponseBody($output);
        if ($raw === null) {
            return;
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['payment_methods']) || !is_array($json['payment_methods'])) {
            return;
        }

        $json = ProductBuyCheckoutPreference::enrichPaymentMethodsResponse(
            $this->session->data,
            $json,
            $storeId
        );
        $this->writeJsonResponseBody($json, $output);
    }

    /**
     * After shipping_method.save, native OC4 unsets payment_method + payment_methods.
     *
     * @param array<int, mixed> $args
     * @param mixed             $output Unused for OC4 JSON controllers (void return); body is in Response.
     */
    public function onShippingMethodSaveAfter(string &$route, array &$args, mixed &$output): void
    {
        $storeId = (int) $this->config->get('config_store_id');
        $this->restoreHandoffCookieIfNeeded($storeId);

        $raw = $this->readJsonResponseBody($output);
        if ($raw === null) {
            return;
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return;
        }

        $preference = ProductBuyCheckoutPreference::load($this->session->data, $storeId);
        $shouldAnnotate = $preference !== null
            && ProductBuyCheckoutPreference::shouldPreferPayment($preference)
            && !empty($json['success']);

        if (!$shouldAnnotate) {
            return;
        }

        $json[ProductBuyCheckoutPreference::JSON_PREFERRED_PAYMENT_KEY] = [
            'code'    => (string) ($preference['payment_code'] ?? ModuleConstants::PAYMENT_OPTION_CODE),
            'name'    => PaymentIdentity::DISPLAY_NAME,
            'pending' => true,
        ];
        $this->writeJsonResponseBody($json, $output);
    }

    /**
     * @param array<int, mixed> $args
     * @param mixed             $output
     */
    public function onPaymentMethodSaveAfter(string &$route, array &$args, mixed &$output): void
    {
        $hadPreference = isset($this->session->data[ProductBuyCheckoutPreference::SESSION_KEY]);
        ProductBuyCheckoutPreference::clearIfPaymentChangedAway($this->session->data);
        if ($hadPreference && !isset($this->session->data[ProductBuyCheckoutPreference::SESSION_KEY])) {
            $this->expireHandoffCookie();
        }
    }

    /**
     * @param array<int, mixed> $args
     */
    public function onCheckoutSuccessBefore(string &$route, array &$args): void
    {
        ProductBuyCheckoutPreference::clear($this->session->data);
        $this->expireHandoffCookie();
    }

    private function restoreHandoffCookieIfNeeded(int $storeId): bool
    {
        $cookieRaw = (string) ($this->request->cookie[ProductBuyCheckoutPreference::HANDOFF_COOKIE] ?? '');
        if ($cookieRaw === '') {
            return false;
        }
        $restored = ProductBuyCheckoutPreference::restoreFromHandoffCookie(
            $this->session->data,
            $storeId,
            $cookieRaw
        );
        if ($restored) {
            // Keep cookie until checkout success so a later session race can still recover.
            if (
                !isset($this->session->data['payment_method'])
                || !PaymentIdentity::matchesStoredPayment($this->session->data['payment_method'])
            ) {
                $this->session->data['payment_method'] = PaymentIdentity::paymentMethod();
            }
        }

        return $restored;
    }

    private function expireHandoffCookie(): void
    {
        if (!headers_sent()) {
            setcookie(ProductBuyCheckoutPreference::HANDOFF_COOKIE, '', [
                'expires'  => time() - 3600,
                'path'     => (string) ($this->config->get('session_path') ?: '/'),
                'secure'   => !empty($this->request->server['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        unset($this->request->cookie[ProductBuyCheckoutPreference::HANDOFF_COOKIE]);
    }

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
