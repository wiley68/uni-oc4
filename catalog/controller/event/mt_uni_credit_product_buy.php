<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\ModuleAssetVersion;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductBuyCheckoutPreference;
use Opencart\System\Library\Extension\MtUniCredit\ProductBuyHandoffTrace;

/**
 * Product „Купи“ → Checkout preference: payment preselect + cleanup across OC4 rerenders.
 *
 * OC4 JSON endpoints (payment_method.getMethods, shipping_method.save) return void and write
 * the body via Response::setOutput(). Controller /after $output is therefore null — hooks must
 * read/write $this->response, not the unused $output argument.
 *
 * TEMPORARY 09E/09F: ProductBuyHandoffTrace markers when mtuc_trace=1 is active.
 *
 * getMethods / shipping_method.save $output arg is Unused for OC4 JSON controllers (void return).
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
        ProductBuyHandoffTrace::captureRequest(
            $this->session->data,
            $this->request->get ?? [],
            $this->request->post ?? []
        );

        $storeId = (int) $this->config->get('config_store_id');
        $restored = $this->restoreHandoffCookieIfNeeded($storeId);

        $this->document->addScript(
            ModuleAssetVersion::href('catalog/view/javascript/mt_uni_credit_checkout_handoff.js'),
            'footer'
        );

        if (ProductBuyHandoffTrace::isEnabled($this->session->data)) {
            $checkpoint = ProductBuyHandoffTrace::lifetimeCheckpoint(
                $this->session->data,
                $storeId,
                $this->session->getId()
            );
            $checkpoint['restored_now'] = $restored;
            $checkpoint['checkpoint'] = 'checkout/checkout/before';
            $this->response->addHeader(
                'X-Mtuc-Trace: CHECKOUT_HANDOFF_INTENT_PRESENT;prefer_payment='
                    . (!empty($checkpoint['prefer_payment']) ? '1' : '0')
                    . ';payment_code=' . (string) ($checkpoint['payment_code'] ?? '')
                    . ';scheme_key=' . (string) ($checkpoint['scheme_key'] ?? '')
                    . ';months=' . (int) ($checkpoint['months'] ?? 0)
                    . ';preference_present=' . (!empty($checkpoint['preference_present']) ? '1' : '0')
                    . ';session_fp=' . (string) ($checkpoint['session_fingerprint'] ?? '')
                    . ';restored=' . ($restored ? '1' : '0')
                    . ';build=' . ProductBuyHandoffTrace::BUILD
            );
            $this->document->addScript(
                ModuleAssetVersion::href('catalog/view/javascript/mt_uni_credit_09e_trace.js')
                    . '&intent=' . rawurlencode((string) json_encode($checkpoint, JSON_UNESCAPED_SLASHES)),
                'footer'
            );
        }
    }

    /**
     * @param array<int, mixed> $args
     * @param mixed             $output
     */
    public function onPaymentMethodsAfter(string &$route, array &$args, mixed &$output): void
    {
        ProductBuyHandoffTrace::captureRequest(
            $this->session->data,
            $this->request->get ?? [],
            $this->request->post ?? []
        );

        $storeId = (int) $this->config->get('config_store_id');
        $this->restoreHandoffCookieIfNeeded($storeId);

        $traceOn = ProductBuyHandoffTrace::isEnabled($this->session->data);
        $raw = $this->readJsonResponseBody($output);
        $pref = ProductBuyCheckoutPreference::load($this->session->data, $storeId);
        $paymentBefore = (string) ($this->session->data['payment_method']['code'] ?? '');
        $lifetime = ProductBuyHandoffTrace::lifetimeCheckpoint(
            $this->session->data,
            $storeId,
            $this->session->getId()
        );

        if ($raw === null) {
            if ($traceOn) {
                $this->response->addHeader(
                    'X-Mtuc-Trace: PAYMENT_GET_METHODS_ENTER;raw_empty=1;output_type='
                        . gettype($output)
                        . ';prefer_payment=' . (!empty($pref['prefer_payment']) ? '1' : '0')
                        . ';preference_present=' . (!empty($lifetime['preference_present']) ? '1' : '0')
                );
            }

            return;
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['payment_methods']) || !is_array($json['payment_methods'])) {
            if ($traceOn) {
                $traceJson = is_array($json) ? $json : ['parse_ok' => false];
                $traceJson = $this->attachTrace($traceJson, 'payment_method.getMethods/after', [
                    'hook_executed'    => true,
                    'early_exit'       => 'no_payment_methods',
                    'checkpoint'       => 'payment_method.getMethods/after',
                    'output_arg_empty' => !is_string($output) || $output === '',
                    'response_len'     => strlen($raw),
                    'payment_before'   => $paymentBefore,
                ] + $lifetime);
                $this->writeJsonResponseBody($traceJson, $output);
            }

            return;
        }

        $orderBefore = ProductBuyHandoffTrace::paymentOrderCodes($json['payment_methods']);
        $json = ProductBuyCheckoutPreference::enrichPaymentMethodsResponse(
            $this->session->data,
            $json,
            $storeId
        );
        $paymentAfter = (string) ($this->session->data['payment_method']['code'] ?? '');
        $orderAfter = ProductBuyHandoffTrace::paymentOrderCodes($json['payment_methods'] ?? []);
        $uniAvailable = in_array(ModuleConstants::PAYMENT_OPTION_CODE, $orderBefore, true);
        $lifetime = ProductBuyHandoffTrace::lifetimeCheckpoint(
            $this->session->data,
            $storeId,
            $this->session->getId()
        );

        if ($traceOn) {
            $json = $this->attachTrace($json, 'payment_method.getMethods/after', [
                'hook_executed'        => true,
                'checkpoint'           => 'payment_method.getMethods/after',
                'unicredit_available'  => $uniAvailable,
                'payment_before'       => $paymentBefore,
                'payment_after'        => $paymentAfter,
                'scheme_months'        => (int) ($lifetime['months'] ?? 0),
                'payment_order_before' => $orderBefore,
                'payment_order_after'  => $orderAfter,
                'output_arg_empty'     => !is_string($output) || $output === '',
                'response_mutated'     => true,
            ] + $lifetime);
            $this->response->addHeader(
                'X-Mtuc-Trace: PAYMENT_GET_METHODS_ENTER;prefer_payment='
                    . (!empty($lifetime['prefer_payment']) ? '1' : '0')
                    . ';unicredit_available=' . ($uniAvailable ? '1' : '0')
                    . ';payment_after=' . $paymentAfter
                    . ';preference_present=' . (!empty($lifetime['preference_present']) ? '1' : '0')
            );
        }

        $this->writeJsonResponseBody($json, $output);
    }

    /**
     * @param array<int, mixed> $args
     */
    public function onShippingMethodSaveBefore(string &$route, array &$args): void
    {
        ProductBuyHandoffTrace::captureRequest(
            $this->session->data,
            $this->request->get ?? [],
            $this->request->post ?? []
        );
        $storeId = (int) $this->config->get('config_store_id');
        $this->restoreHandoffCookieIfNeeded($storeId);

        if (!ProductBuyHandoffTrace::isEnabled($this->session->data)) {
            return;
        }

        $lifetime = ProductBuyHandoffTrace::lifetimeCheckpoint(
            $this->session->data,
            $storeId,
            $this->session->getId()
        );
        $this->response->addHeader(
            'X-Mtuc-Trace: SHIPPING_BEFORE_ENTER;preference_present='
                . (!empty($lifetime['preference_present']) ? '1' : '0')
                . ';months=' . (int) ($lifetime['months'] ?? 0)
                . ';session_fp=' . (string) ($lifetime['session_fingerprint'] ?? '')
                . ';clear_reason=' . (string) ($lifetime['clear_reason'] ?? '')
        );
        // Stash into session for after-hook correlation (no PII).
        $this->session->data['_mtuc_trace_shipping_before'] = $lifetime + [
            'checkpoint' => 'shipping_method.save/before',
        ];
    }

    /**
     * @param array<int, mixed> $args
     * @param mixed             $output
     */
    public function onShippingMethodSaveAfter(string &$route, array &$args, mixed &$output): void
    {
        ProductBuyHandoffTrace::captureRequest(
            $this->session->data,
            $this->request->get ?? [],
            $this->request->post ?? []
        );

        $storeId = (int) $this->config->get('config_store_id');
        $this->restoreHandoffCookieIfNeeded($storeId);

        $traceOn = ProductBuyHandoffTrace::isEnabled($this->session->data);
        $raw = $this->readJsonResponseBody($output);
        $preference = ProductBuyCheckoutPreference::load($this->session->data, $storeId);
        $nativePayment = (string) ($this->session->data['payment_method']['code'] ?? '');
        $lifetime = ProductBuyHandoffTrace::lifetimeCheckpoint(
            $this->session->data,
            $storeId,
            $this->session->getId()
        );
        $before = $this->session->data['_mtuc_trace_shipping_before'] ?? null;
        unset($this->session->data['_mtuc_trace_shipping_before']);

        if ($raw === null) {
            if ($traceOn) {
                $this->response->addHeader(
                    'X-Mtuc-Trace: SHIPPING_AFTER_ENTER;raw_empty=1;prefer_payment='
                        . (!empty($preference['prefer_payment']) ? '1' : '0')
                        . ';preference_present=' . (!empty($lifetime['preference_present']) ? '1' : '0')
                );
            }

            return;
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            if ($traceOn) {
                $this->response->addHeader('X-Mtuc-Trace: SHIPPING_AFTER_ENTER;json_invalid=1');
            }

            return;
        }

        $shouldAnnotate = $preference !== null
            && ProductBuyCheckoutPreference::shouldPreferPayment($preference)
            && !empty($json['success']);

        if ($shouldAnnotate) {
            $json[ProductBuyCheckoutPreference::JSON_PREFERRED_PAYMENT_KEY] = [
                'code'    => (string) ($preference['payment_code'] ?? ModuleConstants::PAYMENT_OPTION_CODE),
                'name'    => PaymentIdentity::DISPLAY_NAME,
                'pending' => true,
            ];
        }

        if ($traceOn) {
            $json = $this->attachTrace($json, 'shipping_method.save/after', [
                'hook_executed'        => true,
                'SHIPPING_AFTER_ENTER' => true,
                'checkpoint'           => 'shipping_method.save/after',
                'success'              => !empty($json['success']),
                'annotated'            => $shouldAnnotate,
                'native_payment'       => $nativePayment,
                'before_checkpoint'    => is_array($before) ? $before : null,
                'early_exit'           => $shouldAnnotate ? '' : (
                    $preference === null ? 'no_preference' : (
                        empty($json['success']) ? 'no_success' : 'prefer_payment_false'
                    )
                ),
                'output_arg_empty'     => !is_string($output) || $output === '',
            ] + $lifetime);
            $this->response->addHeader(
                'X-Mtuc-Trace: SHIPPING_AFTER_ENTER;prefer_payment='
                    . (!empty($preference['prefer_payment']) ? '1' : '0')
                    . ';months=' . (int) ($preference['months'] ?? 0)
                    . ';annotated=' . ($shouldAnnotate ? '1' : '0')
                    . ';preference_present=' . (!empty($lifetime['preference_present']) ? '1' : '0')
                    . ';session_fp=' . (string) ($lifetime['session_fingerprint'] ?? '')
            );
            $this->writeJsonResponseBody($json, $output);

            return;
        }

        if ($shouldAnnotate) {
            $this->writeJsonResponseBody($json, $output);
        }
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
        unset($this->session->data[ProductBuyHandoffTrace::SESSION_KEY]);
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

    /**
     * @param array<string, mixed> $json
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function attachTrace(array $json, string $hook, array $payload): array
    {
        $json[ProductBuyHandoffTrace::JSON_KEY] = ProductBuyHandoffTrace::wrap(
            $this->session->data,
            $hook,
            $payload
        );

        return $json;
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
