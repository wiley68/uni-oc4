<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Payment;

class Uni extends \Opencart\System\Engine\Controller
{
    private string $module = 'module_mt_uni_credit';

    public function index(): string
    {
        $this->load->language('extension/mt_uni_credit/payment/uni');
        $this->load->model('extension/mt_uni_credit/module/product_panel');

        $lang = 'language=' . $this->config->get('config_language');
        $paymentEnabled = (int) $this->config->get('payment_uni_status') === 1;

        $assign = $this->model_extension_mt_uni_credit_module_product_panel->buildAssignForCheckoutPayment($paymentEnabled ? 1 : 0);

        $data = $assign;

        $rangeOk = $data['uni_status'] === 'Yes'
            && $data['uni_total'] >= $data['uni_minstojnost']
            && $data['uni_total'] <= $data['uni_maxstojnost'];
        $data['btn_status'] = ($paymentEnabled && $rangeOk) ? '' : 'disabled';

        $data['text_payment_unavailable'] = $this->language->get('text_payment_unavailable');
        $data['text_cart_range'] = sprintf(
            $this->language->get('text_cart_range'),
            $data['uni_minstojnost'],
            $data['uni_maxstojnost'],
            $data['uni_sign']
        );
        $data['text_title_process_a'] = $this->language->get('text_title_process_a');
        $data['text_title_process_b'] = $this->language->get('text_title_process_b');
        $data['text_product_price'] = $this->language->get('text_product_price');
        $data['text_credit_term'] = $this->language->get('text_credit_term');
        $data['text_months_suffix'] = $this->language->get('text_months_suffix');
        $data['text_first_payment_checkbox'] = $this->language->get('text_first_payment_checkbox');
        $data['text_first_payment_label'] = $this->language->get('text_first_payment_label');
        $data['text_first_payment_recalc'] = $this->language->get('text_first_payment_recalc');
        $data['text_total_credit'] = $this->language->get('text_total_credit');
        $data['text_monthly'] = $this->language->get('text_monthly');
        $data['text_total_due'] = $this->language->get('text_total_due');
        $data['text_glp'] = $this->language->get('text_glp_label');
        $data['text_gpr'] = $this->language->get('text_gpr_label');
        $data['text_firstname'] = $this->language->get('text_firstname');
        $data['text_lastname'] = $this->language->get('text_lastname');
        $data['text_egn'] = $this->language->get('text_egn');
        $data['text_phone'] = $this->language->get('text_phone');
        $data['text_phone2'] = $this->language->get('text_phone2');
        $data['text_email'] = $this->language->get('text_email');
        $data['text_comment'] = $this->language->get('text_comment');
        $data['text_terms_link'] = $this->language->get('text_terms_link');
        $data['text_terms_pdf_title'] = $this->language->get('text_terms_pdf_title');

        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['text_loading'] = $this->language->get('text_loading');
        $data['language'] = $this->config->get('config_language');

        $shema = (int) $data['uni_shema_current'];

        $jsConfig = [
            'calculateUrl'            => $this->url->link('extension/mt_uni_credit/payment/uni.calculateUni', $lang, true),
            'confirmUrl'              => $this->url->link('extension/mt_uni_credit/payment/uni.confirm', $lang, true),
            'uni_promo'               => $data['uni_promo'],
            'uni_promo_data'          => $data['uni_promo_data'],
            'uni_promo_meseci_znak'   => $data['uni_promo_meseci_znak'],
            'uni_promo_meseci'        => $data['uni_promo_meseci'],
            'uni_promo_price'         => $data['uni_promo_price'],
            'uni_product_cat_id'      => (string) $data['uni_product_cat_id'],
            'uni_service'             => $data['uni_service'],
            'uni_user'                => $data['uni_user'],
            'uni_password'            => $data['uni_password'],
            'uni_sertificat'          => $data['uni_sertificat'],
            'uni_liveurl'             => $data['uni_liveurl'],
            'uni_eur'                 => (string) $data['uni_eur'],
            'uni_shema_current'       => $shema,
            'btnStatus'               => $data['btn_status'],
            'uni_proces2'             => (int) $data['uni_proces2'],
        ];

        $data['uni_payment_checkout_config_json'] = json_encode($jsConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        return $this->load->view('extension/mt_uni_credit/payment/uni', $data);
    }

    public function calculateUni(): void
    {
        $this->load->model('extension/mt_uni_credit/module/product_panel');
        $json = $this->model_extension_mt_uni_credit_module_product_panel->calculateUniCheckoutAjax($this->request->post);

        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function confirm(): void
    {
        $this->load->language('extension/mt_uni_credit/payment/uni');

        $json = [];

        if (isset($this->session->data['order_id'])) {
            $this->load->model('checkout/order');

            $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

            if (!$order_info) {
                $json['redirect'] = $this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true);

                unset($this->session->data['order_id']);
            }
        } else {
            $json['error'] = $this->language->get('error_order');
        }

        if (!$json && (!isset($this->session->data['payment_method']) || $this->session->data['payment_method']['code'] !== 'uni.uni')) {
            $json['error'] = $this->language->get('error_payment_method');
        }

        if (!$json) {
            $this->load->model('checkout/order');

            $order_id = (int) $this->session->data['order_id'];
            $order_row = $this->model_checkout_order->getOrder($order_id);

            if ($order_row) {
                $lines = [];
                $months = (int) ($this->session->data['mt_uni_credit_installment_months'] ?? 0);
                if ($months <= 0 && isset($this->request->post['uni_vnoski'])) {
                    $months = (int) $this->request->post['uni_vnoski'];
                }
                if ($months > 0) {
                    $lines[] = sprintf($this->language->get('text_installment_months_comment'), $months);
                }
                foreach (['uni_mesecna', 'uni_gpr', 'uni_glp', 'uni_vnoski', 'uni_parva', 'uni_fname', 'uni_lname', 'uni_phone', 'uni_phone2', 'uni_email', 'uni_egn', 'uni_description', 'uni_kop'] as $k) {
                    if (isset($this->request->post[$k]) && (string) $this->request->post[$k] !== '') {
                        $lines[] = $k . ': ' . $this->request->post[$k];
                    }
                }
                if ($lines !== []) {
                    $comment = trim((string) $order_row['comment']);
                    $comment .= ($comment !== '' ? "\n" : '') . '[UniCredit] ' . implode('; ', $lines);
                    $this->model_checkout_order->editComment($order_id, $comment);
                }
            }

            unset($this->session->data['mt_uni_credit_installment_months']);

            $this->model_checkout_order->addHistory($order_id, (int) $this->config->get('payment_uni_order_status_id'));

            $json['redirect'] = $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true);
        }

        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
