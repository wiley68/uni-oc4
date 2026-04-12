<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit;

/**
 * Страница след UniCredit плащане: банкови заявки и пренасочване към UniCredit (proces1) или само резултат (proces2).
 */
class Uni extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        $this->load->language('extension/mt_uni_credit/uni_bank');

        $lang = 'language=' . $this->config->get('config_language');

        $handoff = $this->session->data['mt_uni_credit_bank_handoff'] ?? null;
        if (!is_array($handoff)) {
            $this->response->redirect($this->url->link('checkout/failure', $lang, true));

            return;
        }

        $orderId = (int) ($handoff['order_id'] ?? 0);
        if ($orderId <= 0 || !isset($this->session->data['order_id']) || (int) $this->session->data['order_id'] !== $orderId) {
            unset($this->session->data['mt_uni_credit_bank_handoff']);
            $this->response->redirect($this->url->link('checkout/failure', $lang, true));

            return;
        }

        $this->load->model('extension/mt_uni_credit/uni_bank_redirect');
        $out = $this->model_extension_mt_uni_credit_uni_bank_redirect->processHandoff($orderId, $handoff);

        if (!$out['ok']) {
            unset($this->session->data['mt_uni_credit_bank_handoff']);
            $this->response->redirect($this->url->link('checkout/failure', $lang, true));

            return;
        }

        unset($this->session->data['mt_uni_credit_bank_handoff']);

        $this->clearCheckoutSessionAfterUni();

        $this->document->setTitle($this->language->get('heading_title'));

        $cssRel = 'catalog/view/stylesheet/mt_uni_credit/uni_bank_loader.css';
        $cssExt = \DIR_EXTENSION . 'mt_uni_credit/catalog/view/stylesheet/mt_uni_credit/uni_bank_loader.css';
        $cssCatalog = \DIR_APPLICATION . 'view/stylesheet/mt_uni_credit/uni_bank_loader.css';
        $cssPath = is_file($cssCatalog) ? $cssCatalog : $cssExt;
        $ver = is_file($cssPath) ? (string) filemtime($cssPath) : '1';
        $this->document->addStyle($cssRel . '?v=' . $ver);

        $data = $out['data'];
        $data['heading_title'] = $this->language->get('heading_title');
        $data['breadcrumbs'] = [
            [
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/home', $lang),
            ],
            [
                'text' => $this->language->get('text_uni'),
                'href' => $this->url->link('extension/mt_uni_credit/uni', $lang),
            ],
        ];

        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');

        if ($data['column_left'] && $data['column_right']) {
            $data['class'] = 'col-sm-6';
        } elseif ($data['column_left'] || $data['column_right']) {
            $data['class'] = 'col-sm-9';
        } else {
            $data['class'] = 'col-sm-12';
        }

        $this->response->setOutput($this->load->view('extension/mt_uni_credit/uni_bank', $data));
    }

    private function clearCheckoutSessionAfterUni(): void
    {
        $this->cart->clear();

        unset(
            $this->session->data['shipping_method'],
            $this->session->data['shipping_methods'],
            $this->session->data['payment_method'],
            $this->session->data['payment_methods'],
            $this->session->data['guest'],
            $this->session->data['comment'],
            $this->session->data['order_id'],
            $this->session->data['coupon'],
            $this->session->data['reward'],
            $this->session->data['voucher'],
            $this->session->data['vouchers'],
            $this->session->data['totals']
        );
    }
}
