<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

require_once \DIR_EXTENSION . 'mt_uni_credit/admin/model/module/unicredit_config.php';

use Opencart\Admin\Model\Extension\MtUniCredit\Module\UnicreditConfig;

/**
 * Class MtUniCreditCartView
 *
 * @package Opencart\Catalog\Controller\Extension\MtUniCredit\Event
 */
class MtUniCreditCartView extends \Opencart\System\Engine\Controller
{

    private string $path = 'extension/mt_uni_credit/module/mt_uni_credit';
    private string $module = UnicreditConfig::MODULE_SETTING_KEY;

    public function init(&$route, &$data, &$output): void
    {
        if ($route !== 'checkout/cart_list' || !$this->config->get($this->module . '_status')) {
            return;
        }

        $this->load->language('extension/mt_uni_credit/product');
        $this->load->language('extension/mt_uni_credit/payment/uni');
        $this->load->model('extension/mt_uni_credit/module/product_panel');

        $assign = $this->model_extension_mt_uni_credit_module_product_panel->buildAssignForCheckoutPayment(
            (int) $this->config->get('payment_uni_status') === 1 ? 1 : 0
        );

        if (($assign['uni_status'] ?? 'No') !== 'Yes') {
            return;
        }

        $lang = 'language=' . $this->config->get('config_language');
        $assign['uni_prepare_installmentcheckout_url'] = $this->url->link(
            'extension/mt_uni_credit/checkout/installment_intent.save',
            $lang,
            true
        );
        $assign['uni_checkout_url'] = $this->url->link('checkout/checkout', $lang, true);
        $assign['uni_get_product_link'] = $this->url->link('extension/mt_uni_credit/payment/uni.calculateUni', $lang, true);
        $assign['uni_mod_version'] = UnicreditConfig::MODULE_VERSION;

        $stepsKey = 'text_steps_' . (int) ($assign['uni_eur'] ?? 0);
        $intro = $this->language->get($stepsKey);
        if ($intro === $stepsKey) {
            $intro = $this->language->get('text_steps_0');
        }

        $assign['uni_steps_intro'] = $intro;
        $assign['text_title_calc'] = sprintf($this->language->get('text_title_calc'), $assign['uni_mod_version']);
        $assign['text_installments'] = $this->language->get('text_installments');
        $assign['text_months'] = $this->language->get('text_months');
        $assign['text_item_price'] = $this->language->get('text_item_price');
        $assign['text_glp'] = $this->language->get('text_glp');
        $assign['text_apr'] = $this->language->get('text_apr');
        $assign['text_help_repayment'] = $this->language->get('text_help_repayment');
        $assign['text_cancel'] = $this->language->get('text_cancel');
        $assign['text_buy_installment'] = $this->language->get('text_buy_installment');
        $assign['text_step_1'] = $this->language->get('text_step_1');
        $assign['text_step_2'] = $this->language->get('text_step_2');
        $assign['text_step_3'] = $this->language->get('text_step_3');
        $assign['text_step_4'] = $this->language->get('text_step_4');
        $assign['text_payment_unavailable'] = $this->language->get('text_payment_unavailable');
        $assign['text_cart_range'] = sprintf(
            $this->language->get('text_cart_range'),
            (float) ($assign['uni_minstojnost'] ?? 0),
            (float) ($assign['uni_maxstojnost'] ?? 0),
            (string) ($assign['uni_sign'] ?? 'лева')
        );
        $assign['uni_meseci_txt'] = $this->language->get('text_months_star');
        $assign['uni_vnoska_txt'] = $this->language->get('text_installment_payment');

        $assign['uni_picture'] = 'catalog/view/stylesheet/mt_uni_credit/uni.png';
        $assign['uni_mini_logo'] = 'catalog/view/stylesheet/mt_uni_credit/uni_mini_logo.png';
        $assign['uni_cart_config_json'] = json_encode([
            'uni_promo'             => (string) ($assign['uni_promo'] ?? ''),
            'uni_promo_data'        => (string) ($assign['uni_promo_data'] ?? ''),
            'uni_promo_meseci_znak' => (string) ($assign['uni_promo_meseci_znak'] ?? ''),
            'uni_promo_meseci'      => (string) ($assign['uni_promo_meseci'] ?? ''),
            'uni_promo_price'       => (string) ($assign['uni_promo_price'] ?? ''),
            'uni_product_cat_id'    => (string) ($assign['uni_product_cat_id'] ?? ''),
            'uni_service'           => (string) ($assign['uni_service'] ?? ''),
            'uni_sertificat'        => (string) ($assign['uni_sertificat'] ?? ''),
            'uni_liveurl'           => (string) ($assign['uni_liveurl'] ?? ''),
            'uni_eur'               => (string) ($assign['uni_eur'] ?? 0),
            'months'                => (string) ($assign['uni_shema_current'] ?? 12),
            'price'                 => number_format((float) ($assign['uni_total'] ?? 0), 2, '.', ''),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        $hook1 = '<div id="accordion" class="accordion">';
        $hook2 = '<br/>';
        $position_hook1 = strpos($output, $hook1);
        if ($position_hook1 !== false) {
            $suboutput_after_hook1 = substr($output, $position_hook1 + strlen($hook1));
            $position_hook2_in_suboutput = strpos($suboutput_after_hook1, $hook2);
            if ($position_hook2_in_suboutput !== false) {
                $position_hook2_after = $position_hook1 + strlen($hook1) + $position_hook2_in_suboutput + strlen($hook2);
                $output = substr($output, 0, $position_hook2_after) . $this->load->view($this->path . '_cart', $assign) . substr($output, $position_hook2_after);
            }
        }
    }
}
