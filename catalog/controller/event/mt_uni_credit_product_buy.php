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
 * TEMPORARY 09E: ProductBuyHandoffTrace markers when mtuc_trace=1 is active in session.
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

        $this->document->addScript(
            ModuleAssetVersion::href('catalog/view/javascript/mt_uni_credit_checkout_handoff.js'),
            'footer'
        );

        if (ProductBuyHandoffTrace::isEnabled($this->session->data)) {
            $storeId = (int) $this->config->get('config_store_id');
            $pref = ProductBuyCheckoutPreference::load($this->session->data, $storeId);
            $snap = ProductBuyHandoffTrace::preferenceSnapshot($pref);
            $native = (string) ($this->session->data['payment_method']['code'] ?? '');
            $this->response->addHeader(
                'X-Mtuc-Trace: CHECKOUT_HANDOFF_INTENT_PRESENT;prefer_payment='
                    . (!empty($snap['prefer_payment']) ? '1' : '0')
                    . ';payment_code=' . (string) ($snap['payment_code'] ?? '')
                    . ';scheme_key=' . (string) ($snap['scheme_key'] ?? '')
                    . ';months=' . (int) $snap['months']
                    . ';native_payment=' . $native
                    . ';build=' . ProductBuyHandoffTrace::BUILD
            );
            // Trace-only asset: exposes window.__MTUC_* for Console proof (no PII).
            $this->document->addScript(
                ModuleAssetVersion::href('catalog/view/javascript/mt_uni_credit_09e_trace.js')
                    . '&intent=' . rawurlencode((string) json_encode($snap, JSON_UNESCAPED_SLASHES)),
                'footer'
            );
        }
    }

    /**
     * After payment methods are discovered, prefer UniCredit when Product Buy handoff is active.
     *
     * @param array<int, mixed> $args
     * @param mixed             $output Unused for OC4 JSON controllers (void return); body is in Response.
     */
    public function onPaymentMethodsAfter(string &$route, array &$args, mixed &$output): void
    {
        ProductBuyHandoffTrace::captureRequest(
            $this->session->data,
            $this->request->get ?? [],
            $this->request->post ?? []
        );

        $traceOn = ProductBuyHandoffTrace::isEnabled($this->session->data);
        $raw = $this->readJsonResponseBody($output);
        $storeId = (int) $this->config->get('config_store_id');
        $pref = ProductBuyCheckoutPreference::load($this->session->data, $storeId);
        $paymentBefore = (string) ($this->session->data['payment_method']['code'] ?? '');

        if ($raw === null) {
            if ($traceOn) {
                // Cannot mutate missing body; header still proves hook entry.
                $this->response->addHeader(
                    'X-Mtuc-Trace: PAYMENT_GET_METHODS_ENTER;raw_empty=1;output_type='
                        . gettype($output)
                        . ';prefer_payment=' . (!empty($pref['prefer_payment']) ? '1' : '0')
                );
            }

            return;
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['payment_methods']) || !is_array($json['payment_methods'])) {
            if ($traceOn) {
                $traceJson = is_array($json) ? $json : ['parse_ok' => false];
                $traceJson = $this->attachTrace($traceJson, 'payment_method.getMethods/after', [
                    'hook_executed'       => true,
                    'early_exit'          => 'no_payment_methods',
                    'output_arg_empty'    => !is_string($output) || $output === '',
                    'response_len'        => strlen($raw),
                    'prefer_payment'      => !empty($pref['prefer_payment']),
                    'payment_before'      => $paymentBefore,
                ] + ProductBuyHandoffTrace::preferenceSnapshot($pref));
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

        if ($traceOn) {
            $json = $this->attachTrace($json, 'payment_method.getMethods/after', [
                'hook_executed'        => true,
                'prefer_payment'       => !empty($pref['prefer_payment']),
                'unicredit_available'  => $uniAvailable,
                'payment_before'       => $paymentBefore,
                'payment_after'        => $paymentAfter,
                'scheme_months'        => (int) ($pref['months'] ?? 0),
                'scheme_key'           => (string) ($pref['scheme_key'] ?? ''),
                'payment_order_before' => $orderBefore,
                'payment_order_after'  => $orderAfter,
                'output_arg_empty'     => !is_string($output) || $output === '',
                'response_mutated'     => true,
            ] + ProductBuyHandoffTrace::preferenceSnapshot($pref));
            $this->response->addHeader(
                'X-Mtuc-Trace: PAYMENT_GET_METHODS_ENTER;prefer_payment='
                    . (!empty($pref['prefer_payment']) ? '1' : '0')
                    . ';unicredit_available=' . ($uniAvailable ? '1' : '0')
                    . ';payment_after=' . $paymentAfter
            );
        }

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
        ProductBuyHandoffTrace::captureRequest(
            $this->session->data,
            $this->request->get ?? [],
            $this->request->post ?? []
        );

        $traceOn = ProductBuyHandoffTrace::isEnabled($this->session->data);
        $raw = $this->readJsonResponseBody($output);
        $storeId = (int) $this->config->get('config_store_id');
        $preference = ProductBuyCheckoutPreference::load($this->session->data, $storeId);
        $nativePayment = (string) ($this->session->data['payment_method']['code'] ?? '');

        if ($raw === null) {
            if ($traceOn) {
                $this->response->addHeader(
                    'X-Mtuc-Trace: SHIPPING_AFTER_ENTER;raw_empty=1;prefer_payment='
                        . (!empty($preference['prefer_payment']) ? '1' : '0')
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
                'hook_executed'    => true,
                'SHIPPING_AFTER_ENTER' => true,
                'success'          => !empty($json['success']),
                'annotated'        => $shouldAnnotate,
                'native_payment'   => $nativePayment,
                'early_exit'       => $shouldAnnotate ? '' : (
                    $preference === null ? 'no_preference' : (
                        empty($json['success']) ? 'no_success' : 'prefer_payment_false'
                    )
                ),
                'output_arg_empty' => !is_string($output) || $output === '',
            ] + ProductBuyHandoffTrace::preferenceSnapshot($preference));
            $this->response->addHeader(
                'X-Mtuc-Trace: SHIPPING_AFTER_ENTER;prefer_payment='
                    . (!empty($preference['prefer_payment']) ? '1' : '0')
                    . ';months=' . (int) ($preference['months'] ?? 0)
                    . ';annotated=' . ($shouldAnnotate ? '1' : '0')
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
        ProductBuyCheckoutPreference::clearIfPaymentChangedAway($this->session->data);
    }

    /**
     * @param array<int, mixed> $args
     */
    public function onCheckoutSuccessBefore(string &$route, array &$args): void
    {
        ProductBuyCheckoutPreference::clear($this->session->data);
        unset($this->session->data[ProductBuyHandoffTrace::SESSION_KEY]);
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
