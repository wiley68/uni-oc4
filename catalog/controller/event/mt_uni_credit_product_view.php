<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

/**
 * Вмъква UniCredit блок под бутона за количката на продуктова страница.
 */
class MtUniCreditProductView extends \Opencart\System\Engine\Controller
{
    private string $path = 'extension/mt_uni_credit/module/mt_uni_credit_product';

    private string $module = 'module_mt_uni_credit';

    public function init(&$route, &$data, &$output): void
    {
        if ($route !== 'product/product' || !$this->config->get($this->module . '_status')) {
            return;
        }

        $productId = isset($this->request->get['product_id']) ? (int) $this->request->get['product_id'] : 0;
        if ($productId <= 0) {
            return;
        }

        $this->load->language('extension/mt_uni_credit/product');

        $currencyCode = (string) ($this->session->data['currency'] ?? 'BGN');
        if ($currencyCode !== 'EUR' && $currencyCode !== 'BGN') {
            return;
        }

        $configCustomerPrice = $this->config->get('config_customer_price');
        if ($configCustomerPrice && !$this->customer->isLogged()) {
            return;
        }

        $this->load->model('catalog/product');
        $productInfo = $this->model_catalog_product->getProduct($productId);
        if (!$productInfo) {
            return;
        }

        $rawPrice = (float) ($productInfo['special'] ?: $productInfo['price']);
        $taxed = (float) $this->tax->calculate($rawPrice, (int) $productInfo['tax_class_id'], $this->config->get('config_tax'));
        $displayPrice = (float) $this->currency->convert($taxed, $this->config->get('config_currency'), $currencyCode);

        $lang = 'language=' . $this->config->get('config_language');
        $calculateUrlJs = $this->url->link('extension/mt_uni_credit/product/uni_calculate', $lang, true);
        $optionCheckUrlJs = $this->url->link('extension/mt_uni_credit/product/uni_option', $lang, true);
        $cartAddUrlJs = $this->url->link('checkout/cart.add', $lang, true);
        $cartPageUrlJs = $this->url->link('checkout/cart', $lang, true);

        $sslUrl = (string) ($this->config->get('config_ssl') ?: $this->config->get('config_url'));
        $shopSslBase = rtrim($sslUrl, '/');

        $userAgent = isset($this->request->server['HTTP_USER_AGENT']) ? (string) $this->request->server['HTTP_USER_AGENT'] : '';

        $texts = [
            'months_desktop'        => $this->language->get('text_months_star'),
            'months_mobile'         => $this->language->get('text_months_repay_star'),
            'installment_desktop'   => $this->language->get('text_installment_payment'),
            'installment_mobile'    => $this->language->get('text_monthly_installment'),
            'js_cart_add_failed'    => $this->language->get('text_js_cart_add_failed'),
            'js_store_error'        => $this->language->get('text_js_store_error'),
        ];

        $this->load->model('extension/mt_uni_credit/module/product_panel');
        $assign = $this->model_extension_mt_uni_credit_module_product_panel->buildAssignForProductPage(
            $productId,
            $displayPrice,
            $currencyCode,
            $userAgent,
            $shopSslBase,
            $calculateUrlJs,
            $optionCheckUrlJs,
            $cartAddUrlJs,
            $cartPageUrlJs,
            $texts
        );

        if ($assign === null) {
            return;
        }

        $stepsKey = 'text_steps_' . (int) ($assign['uni_eur'] ?? 0);
        $intro = $this->language->get($stepsKey);
        if ($intro === $stepsKey) {
            $intro = $this->language->get('text_steps_0');
        }

        $assign['uni_steps_intro'] = $intro;
        $assign['text_title_calc'] = sprintf($this->language->get('text_title_calc'), $assign['uni_mod_version'] ?? '1.0.0');
        $assign['text_installments'] = $this->language->get('text_installments');
        $assign['text_months'] = $this->language->get('text_months');
        $assign['text_item_price'] = $this->language->get('text_item_price');
        $assign['text_glp'] = $this->language->get('text_glp');
        $assign['text_apr'] = $this->language->get('text_apr');
        $assign['text_help_repayment'] = $this->language->get('text_help_repayment');
        $assign['text_cancel'] = $this->language->get('text_cancel');
        $assign['text_add_to_cart'] = $this->language->get('text_add_to_cart');
        $assign['text_buy_installment'] = $this->language->get('text_buy_installment');
        $assign['text_step_1'] = $this->language->get('text_step_1');
        $assign['text_step_2'] = $this->language->get('text_step_2');
        $assign['text_step_3'] = $this->language->get('text_step_3');
        $assign['text_step_4'] = $this->language->get('text_step_4');

        $hook1 = '<button type="submit" id="button-cart"';
        $hook2 = '</div>';
        $positionHook1 = strpos($output, $hook1);
        if ($positionHook1 !== false) {
            $suboutputAfterHook1 = substr($output, $positionHook1 + strlen($hook1));
            $positionHook2InSuboutput = strpos($suboutputAfterHook1, $hook2);
            if ($positionHook2InSuboutput !== false) {
                $positionHook2After = $positionHook1 + strlen($hook1) + $positionHook2InSuboutput + strlen($hook2);
                $output = substr($output, 0, $positionHook2After) . $this->load->view($this->path, $assign) . substr($output, $positionHook2After);
            }
        }
    }
}
