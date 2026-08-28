<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingAvailability;
use Opencart\System\Library\Extension\MtUniCredit\ProductOptionNormalizer;
use Opencart\System\Library\Extension\MtUniCredit\StandardThemeProductPlacement;

class MtUniCreditProductView extends \Opencart\System\Engine\Controller
{
    private string $viewPath = 'extension/mt_uni_credit/module/mt_uni_credit_product_calculator';

    public function init(string &$route, array &$data, string &$output): void
    {
        if (!$this->config->get(ModuleConstants::MODULE_SETTING_CODE . '_status')) {
            return;
        }
        if (!isset($this->request->get['product_id'])) {
            return;
        }

        $this->load->language('extension/mt_uni_credit/module/mt_uni_credit_product');
        $this->load->model('extension/mt_uni_credit/module/mt_uni_credit_product');
        $languageKeys = [
            'text_button_financing',
            'text_button_financing_image',
            'text_modal_title',
            'text_customer_details',
            'text_address_details',
            'text_consents',
            'text_firstname',
            'text_lastname',
            'text_email',
            'text_telephone',
            'text_address_1',
            'text_city',
            'text_postcode',
            'text_country_id',
            'text_zone_id',
            'text_submit',
            'text_close',
        ];
        foreach ($languageKeys as $key) {
            $data[$key] = $this->language->get($key);
        }
        $model = $this->model_extension_mt_uni_credit_module_mt_uni_credit_product;
        $shop = $model->getShopConfiguration();
        $productId = (int) $this->request->get['product_id'];
        $quantity = max(1, (int) ($this->request->get['quantity'] ?? 1));
        $options = ProductOptionNormalizer::normalize($this->request->post['option'] ?? []);

        try {
            $line = $model->createProductContextFactory()->create(
                (int) $this->config->get('config_store_id'),
                $productId,
                $quantity,
                $options
            );
        } catch (\Throwable) {
            return;
        }

        $currency = (string) ($this->session->data['currency'] ?? $this->config->get('config_currency'));
        $availability = $model->createAvailabilityGate();
        if (!$availability->isCalculatorVisible($model->isModuleEnabled(), $shop, $currency, $line)) {
            return;
        }

        $presenter = $model->createCalculatorPresenter()->present($shop, $line, $currency);
        if ($presenter === null) {
            return;
        }

        $modal = $model->createModalPresenter()->present($shop, $model->customerPrefill());
        $data['mt_uni_credit'] = [
            'enabled'        => true,
            'product_id'     => $productId,
            'calculator'     => $presenter,
            'modal'          => $modal,
            'calculate_url'  => $this->url->link('extension/mt_uni_credit/module/mt_uni_credit_product.calculate', 'language=' . $this->config->get('config_language')),
            'issue_url'      => $this->url->link('extension/mt_uni_credit/module/mt_uni_credit_product.issueSubmission', 'language=' . $this->config->get('config_language')),
            'submit_url'     => $this->url->link('extension/mt_uni_credit/module/mt_uni_credit_product.submit', 'language=' . $this->config->get('config_language')),
            'csrf_token'     => $this->session->data['csrf_token'] ?? '',
        ];

        $fragment = $this->load->view($this->viewPath, $data);
        $output = (new StandardThemeProductPlacement())->insertAfterAddToCartBlock($output, $fragment);
    }
}
