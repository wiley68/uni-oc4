<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleLocalSettings;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\ProductOptionNormalizer;
use Opencart\System\Library\Extension\MtUniCredit\ProductVisibilityDebugLog;
use Opencart\System\Library\Extension\MtUniCredit\StandardThemeProductPlacement;

/**
 * Product calculator placement — catalog/view/product/product/after.
 *
 * OpenCart 4.1.0.3 supplies: string &$route, array &$data, string &$output
 */
class MtUniCreditProductView extends \Opencart\System\Engine\Controller
{
    private string $viewPath = 'extension/mt_uni_credit/module/mt_uni_credit_product_calculator';

    public function init(string &$route, array &$data, string &$output): void
    {
        $debug = (bool) $this->config->get(ModuleLocalSettings::DEBUG_ENABLED);

        if (!$this->config->get(ModuleConstants::MODULE_SETTING_CODE . '_status')) {
            ProductVisibilityDebugLog::write($this->log, $debug, 'Product calculator hidden: module disabled');

            return;
        }
        if (!isset($this->request->get['product_id'])) {
            ProductVisibilityDebugLog::write($this->log, $debug, 'Product calculator hidden: product_id missing');

            return;
        }

        $this->load->language('extension/mt_uni_credit/module/mt_uni_credit_product');
        $this->load->model('extension/mt_uni_credit/module/mt_uni_credit_product');
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
            'text_egn',
            'text_phone2',
            'text_required',
            'text_processing_title',
            'text_processing_message',
            'text_local_order_prepared',
            'text_error_required_options',
        ];
        $assetBase = 'extension/mt_uni_credit/catalog/view/image/product/';
        foreach ($languageKeys as $key) {
            $data[$key] = $this->language->get($key);
        }
        $model = $this->model_extension_mt_uni_credit_module_mt_uni_credit_product;
        $shop = $model->getShopConfiguration();
        $productId = (int) $this->request->get['product_id'];
        $storeId = (int) ($this->config->get('config_store_id') ?? 0);
        $quantity = max(1, (int) ($this->request->get['quantity'] ?? 1));
        $options = ProductOptionNormalizer::normalize($this->request->post['option'] ?? []);

        if ($shop === null) {
            ProductVisibilityDebugLog::write(
                $this->log,
                $debug,
                'Product calculator hidden: shop cache unavailable store_id=' . $storeId . ' product_id=' . $productId
            );

            return;
        }

        try {
            // Display path: missing required options use base price (submit remains strict).
            $line = $model->createDisplayProductContextFactory()->create(
                $storeId,
                $productId,
                $quantity,
                $options
            );
        } catch (ProductFinancingFlowException $exception) {
            ProductVisibilityDebugLog::write(
                $this->log,
                $debug,
                'Product calculator hidden: product context failed (' . $exception->getMessage() . ') product_id=' . $productId
            );

            return;
        } catch (\Throwable $exception) {
            ProductVisibilityDebugLog::write(
                $this->log,
                $debug,
                'Product calculator hidden: product context technical failure product_id=' . $productId
            );

            return;
        }

        $currency = (string) ($this->session->data['currency'] ?? $this->config->get('config_currency'));
        $availability = $model->createAvailabilityGate();
        if (!$availability->isCalculatorVisible($model->isModuleEnabled(), $shop, $currency, $line)) {
            $gate = new \Opencart\System\Library\Extension\MtUniCredit\CurrencyGate();
            if (!$gate->supports($shop, $currency)) {
                $reason = 'unsupported currency ' . strtoupper($currency);
            } elseif ($line->financingPrice <= 0) {
                $reason = 'non-positive financing price';
            } else {
                $reason = 'no eligible schemes';
            }
            ProductVisibilityDebugLog::write(
                $this->log,
                $debug,
                'Product calculator hidden: ' . $reason
                . ' product_id=' . $productId
                . ' store_id=' . $storeId
                . ' currency=' . strtoupper($currency)
                . ' amount=' . $line->financingPrice
            );

            return;
        }

        $presenter = $model->createCalculatorPresenter()->present($shop, $line, $currency);
        if ($presenter === null) {
            ProductVisibilityDebugLog::write(
                $this->log,
                $debug,
                'Product calculator hidden: presenter empty product_id=' . $productId
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
        // url->link(..., true) keeps raw "&" for JavaScript fetch (not HTML &amp;).
        $calculateUrl = $this->url->link(
            'extension/mt_uni_credit/module/mt_uni_credit_product.calculate',
            'language=' . $language,
            true
        );
        $issueUrl = $this->url->link(
            'extension/mt_uni_credit/module/mt_uni_credit_product.issueSubmission',
            'language=' . $language,
            true
        );
        $submitUrl = $this->url->link(
            'extension/mt_uni_credit/module/mt_uni_credit_product.submit',
            'language=' . $language,
            true
        );
        $stashBuyUrl = $this->url->link(
            'extension/mt_uni_credit/module/mt_uni_credit_product.stashBuyPreference',
            'language=' . $language,
            true
        );
        $checkoutUrl = $this->url->link('checkout/checkout', 'language=' . $language, true);

        $data['mt_uni_credit'] = [
            'enabled'               => true,
            'product_id'            => $productId,
            'button_top_spacing'    => ModuleLocalSettings::normalizeButtonTopSpacing(
                $this->config->get(ModuleLocalSettings::BUTTON_TOP_SPACING)
            ),
            'product_button_action' => $buttonAction,
            'calculator'            => $presenter,
            'modal'                 => $modal,
            'logo_standard_url'     => $assetBase . 'uni_logo.svg',
            'logo_alternative_url'  => $assetBase . 'uni_logo_red.svg',
            'badge_url'             => $assetBase . 'uni_mini_logo.png',
            'checkout_url'          => $checkoutUrl,
            'calculate_url'         => $calculateUrl,
            'issue_url'             => $issueUrl,
            'submit_url'            => $submitUrl,
            'stash_buy_url'         => $stashBuyUrl,
            'csrf_token'            => $csrfToken,
        ];
        $data['mt_uni_credit_bootstrap_json'] = json_encode([
            'product_id'            => $productId,
            'calculator'            => $presenter,
            'modal'                 => $modal,
            'product_button_action' => $buttonAction,
            'checkout_url'          => $checkoutUrl,
            'logo_standard_url'     => $assetBase . 'uni_logo.svg',
            'logo_alternative_url'  => $assetBase . 'uni_logo_red.svg',
            'badge_url'             => $assetBase . 'uni_mini_logo.png',
            'calculate_url'         => $calculateUrl,
            'issue_url'             => $issueUrl,
            'submit_url'            => $submitUrl,
            'stash_buy_url'         => $stashBuyUrl,
            'csrf_token'            => $csrfToken,
            'button_top_spacing'    => ModuleLocalSettings::normalizeButtonTopSpacing(
                $this->config->get(ModuleLocalSettings::BUTTON_TOP_SPACING)
            ),
            'i18n'                  => [
                'error_required_options' => (string) $this->language->get('text_error_required_options'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data['mt_uni_credit_modal_html'] = $this->load->view(
            'extension/mt_uni_credit/module/mt_uni_credit_product_modal',
            $data
        );

        $fragment = $this->load->view($this->viewPath, $data);
        if (trim($fragment) === '') {
            ProductVisibilityDebugLog::write($this->log, $debug, 'Product calculator hidden: empty Twig fragment');

            return;
        }

        $before = $output;
        $output = (new StandardThemeProductPlacement())->insertAfterAddToCartBlock($output, $fragment);
        if ($output === $before) {
            ProductVisibilityDebugLog::write(
                $this->log,
                $debug,
                'Product calculator placement failed: button-cart marker not found'
            );
        } else {
            ProductVisibilityDebugLog::write(
                $this->log,
                $debug,
                'Product calculator visible product_id=' . $productId . ' store_id=' . $storeId
                . ' currency=' . strtoupper($currency) . ' amount=' . $line->financingPrice
            );
        }
    }
}
