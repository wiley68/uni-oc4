<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleLocalSettings;
use Opencart\System\Library\Extension\MtUniCredit\ProductVisibilityDebugLog;
use Opencart\System\Library\Extension\MtUniCredit\StandardThemeCartPlacement;

/**
 * Cart calculator placement — catalog/view/checkout/cart/after.
 *
 * OpenCart 4.1.0.3 supplies: string &$route, array &$data, string &$output
 */
class MtUniCreditCartView extends \Opencart\System\Engine\Controller
{
    private string $viewPath = 'extension/mt_uni_credit/module/mt_uni_credit_cart_calculator';

    public function init(string &$route, array &$data, string &$output): void
    {
        $debug = (bool) $this->config->get(ModuleLocalSettings::DEBUG_ENABLED);

        if (!$this->config->get(ModuleConstants::MODULE_SETTING_CODE . '_status')) {
            ProductVisibilityDebugLog::write($this->log, $debug, 'Cart calculator hidden: module disabled');

            return;
        }

        $this->load->language('extension/mt_uni_credit/module/mt_uni_credit_cart');
        $this->load->model('extension/mt_uni_credit/module/mt_uni_credit_cart');
        $languageKeys = [
            'text_button_financing',
            'text_modal_title_scheme',
            'text_modal_title_customer',
            'text_modal_success_title',
            'text_modal_success_message',
            'text_price',
            'text_months',
            'text_months_short',
            'text_first_installment',
            'text_financed_amount',
            'text_monthly_installment',
            'text_monthly_installment_short',
            'text_total_payable',
            'text_glp',
            'text_gpr',
            'text_cancel',
            'text_back',
            'text_apply',
            'text_send',
            'text_consents',
            'text_firstname',
            'text_lastname',
            'text_address',
            'text_email',
            'text_telephone',
            'text_required',
            'text_processing_title',
            'text_processing_message',
            'text_local_order_prepared',
            'text_cart_changed',
            'text_cart_empty',
        ];
        $assetBase = 'extension/mt_uni_credit/catalog/view/image/product/';
        foreach ($languageKeys as $key) {
            $data[$key] = $this->language->get($key);
        }

        $model = $this->model_extension_mt_uni_credit_module_mt_uni_credit_cart;
        $shop = $model->getShopConfiguration();
        $storeId = (int) ($this->config->get('config_store_id') ?? 0);

        if ($shop === null) {
            ProductVisibilityDebugLog::write(
                $this->log,
                $debug,
                'Cart calculator hidden: shop cache unavailable store_id=' . $storeId
            );

            return;
        }

        $cart = $model->createCartContext();
        if ($cart->lines === [] || $cart->total <= 0.0) {
            ProductVisibilityDebugLog::write($this->log, $debug, 'Cart calculator hidden: empty cart');

            return;
        }

        $currency = (string) ($this->session->data['currency'] ?? $this->config->get('config_currency'));
        $presenter = $model->createCalculatorPresenter()->present($shop, $cart, $currency);
        if ($presenter === null) {
            ProductVisibilityDebugLog::write(
                $this->log,
                $debug,
                'Cart calculator hidden: no intersecting schemes store_id=' . $storeId
                . ' currency=' . strtoupper($currency) . ' amount=' . $cart->total
            );

            return;
        }

        $buttonAction = ModuleLocalSettings::normalizeProductButtonAction(
            (string) ($this->config->get(ModuleLocalSettings::PRODUCT_BUTTON_ACTION) ?? '')
        );
        $modal = $model->createModalPresenter()->present($shop, $model->customerPrefill(), $buttonAction);
        $csrf = new \Opencart\System\Library\Extension\MtUniCredit\ProductStorefrontCsrf();
        $csrfToken = $csrf->getOrCreate($this->session->data);
        $language = (string) $this->config->get('config_language');
        $calculateUrl = $this->url->link(
            'extension/mt_uni_credit/module/mt_uni_credit_cart.calculate',
            'language=' . $language,
            true
        );
        $issueUrl = $this->url->link(
            'extension/mt_uni_credit/module/mt_uni_credit_cart.issueSubmission',
            'language=' . $language,
            true
        );
        $submitUrl = $this->url->link(
            'extension/mt_uni_credit/module/mt_uni_credit_cart.submit',
            'language=' . $language,
            true
        );

        $data['mt_uni_credit'] = [
            'enabled'              => true,
            'product_id'           => 0,
            'button_top_spacing'   => ModuleLocalSettings::normalizeButtonTopSpacing(
                $this->config->get(ModuleLocalSettings::BUTTON_TOP_SPACING)
            ),
            'product_button_action'=> $buttonAction,
            'calculator'           => $presenter,
            'modal'                => $modal,
            'logo_standard_url'    => $assetBase . 'uni_logo.svg',
            'logo_alternative_url' => $assetBase . 'uni_logo_red.svg',
            'badge_url'            => $assetBase . 'uni_mini_logo.png',
            'calculate_url'        => $calculateUrl,
            'issue_url'            => $issueUrl,
            'submit_url'           => $submitUrl,
            'csrf_token'           => $csrfToken,
            'source'               => 'cart',
        ];
        $data['mt_uni_credit_bootstrap_json'] = json_encode([
            'source'                => 'cart',
            'product_id'            => 0,
            'calculator'            => $presenter,
            'modal'                 => $modal,
            'product_button_action' => $buttonAction,
            'logo_standard_url'     => $assetBase . 'uni_logo.svg',
            'logo_alternative_url'  => $assetBase . 'uni_logo_red.svg',
            'badge_url'             => $assetBase . 'uni_mini_logo.png',
            'calculate_url'         => $calculateUrl,
            'issue_url'             => $issueUrl,
            'submit_url'            => $submitUrl,
            'csrf_token'            => $csrfToken,
            'button_top_spacing'    => ModuleLocalSettings::normalizeButtonTopSpacing(
                $this->config->get(ModuleLocalSettings::BUTTON_TOP_SPACING)
            ),
            'hide_secondary'        => true,
            'i18n'                  => [
                'cart_changed' => (string) $this->language->get('text_cart_changed'),
                'cart_empty'   => (string) $this->language->get('text_cart_empty'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data['mt_uni_credit_modal_html'] = $this->load->view(
            'extension/mt_uni_credit/module/mt_uni_credit_cart_modal',
            $data
        );

        $fragment = $this->load->view($this->viewPath, $data);
        if (trim($fragment) === '') {
            ProductVisibilityDebugLog::write($this->log, $debug, 'Cart calculator hidden: empty Twig fragment');

            return;
        }

        $before = $output;
        $output = (new StandardThemeCartPlacement())->insertAfterShoppingCart($output, $fragment);
        if ($output === $before) {
            ProductVisibilityDebugLog::write(
                $this->log,
                $debug,
                'Cart calculator placement failed: shopping-cart marker not found'
            );
        } else {
            ProductVisibilityDebugLog::write(
                $this->log,
                $debug,
                'Cart calculator visible store_id=' . $storeId
                . ' currency=' . strtoupper($currency) . ' amount=' . $cart->total
            );
        }
    }
}
