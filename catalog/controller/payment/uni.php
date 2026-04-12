<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Payment;

class Uni extends \Opencart\System\Engine\Controller
{
    public function index(): string
    {
        $this->load->language('extension/mt_uni_credit/payment/uni');

        $data['language'] = $this->config->get('config_language');

        return $this->load->view('extension/mt_uni_credit/payment/uni', $data);
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
            $months = (int) ($this->session->data['mt_uni_credit_installment_months'] ?? 0);
            if ($months > 0) {
                $order_row = $this->model_checkout_order->getOrder($order_id);
                if ($order_row) {
                    $note = sprintf($this->language->get('text_installment_months_comment'), $months);
                    $comment = trim((string) $order_row['comment']);
                    $comment .= ($comment !== '' ? "\n" : '') . $note;
                    $this->model_checkout_order->editComment($order_id, $comment);
                }
                unset($this->session->data['mt_uni_credit_installment_months']);
            }

            $this->model_checkout_order->addHistory($order_id, (int) $this->config->get('payment_uni_order_status_id'));

            $json['redirect'] = $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true);
        }

        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->setOutput(json_encode($json));
    }
}
