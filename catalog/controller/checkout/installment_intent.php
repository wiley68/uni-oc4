<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Checkout;

require_once \DIR_EXTENSION . 'mt_uni_credit/admin/model/module/unicredit_config.php';

use Opencart\Admin\Model\Extension\MtUniCredit\Module\UnicreditConfig;

/**
 * Записва в сесия намерение за чекаута: метод uni + брой месеци (след добавяне в количката).
 */
class InstallmentIntent extends \Opencart\System\Engine\Controller
{
    private string $module = 'module_mt_uni_credit';

    public function save(): void
    {
        $this->load->language('checkout/cart');
        $this->load->language('extension/mt_uni_credit/checkout/installment_intent');

        $json = [];

        if (!$this->cart->hasProducts()) {
            $json['error'] = $this->language->get('text_no_results');
            $this->outputJson($json);

            return;
        }

        if (!(int) $this->config->get($this->module . '_status')) {
            $json['error'] = $this->language->get('error_module_disabled');
            $this->outputJson($json);

            return;
        }

        $months = isset($this->request->post['installment_months']) ? (int) $this->request->post['installment_months'] : 0;
        if ($months <= 0) {
            $months = isset($this->request->post['uni_vnoski']) ? (int) $this->request->post['uni_vnoski'] : 12;
        }

        $allowed = [3, 4, 5, 6, 9, 10, 12, 15, 18, 24, 30, 36];
        if (!in_array($months, $allowed, true)) {
            $months = 12;
        }

        $this->session->data['mt_uni_credit_auto_payment_code'] = 'uni.uni';
        $this->session->data['mt_uni_credit_installment_months'] = $months;

        $this->writePrepareCheckoutBrowserCookies($months);

        $lang = 'language=' . $this->config->get('config_language');
        $json['success'] = true;
        $json['redirect'] = $this->url->link('checkout/checkout', $lang, true);
        $this->outputJson($json);
    }

    /**
     * Бисквитки като PrestaShop UniPayment::writeBrowserCookiesForPrepareCheckout (TTL 1800 s).
     */
    private function writePrepareCheckoutBrowserCookies(int $resolvedInstallmentsMonths): void
    {
        $secure = !empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] !== 'off';
        $common = [
            'expires'  => time() + UnicreditConfig::CHECKOUT_BROWSER_COOKIE_TTL,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => false,
            'SameSite' => 'Lax',
        ];

        setcookie(UnicreditConfig::BROWSER_COOKIE_CHECKOUT_FLAG, '1', $common);

        if ($resolvedInstallmentsMonths > 0) {
            setcookie(
                UnicreditConfig::BROWSER_COOKIE_CHECKOUT_INSTALLMENTS,
                (string) $resolvedInstallmentsMonths,
                $common
            );
        } else {
            setcookie(UnicreditConfig::BROWSER_COOKIE_CHECKOUT_INSTALLMENTS, '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => false,
                'SameSite' => 'Lax',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $json
     */
    private function outputJson(array $json): void
    {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
