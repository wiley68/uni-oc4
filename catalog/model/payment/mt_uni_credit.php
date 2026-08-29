<?php

namespace Opencart\Catalog\Model\Extension\MtUniCredit\Payment;

use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;

/**
 * Class MtUniCredit
 *
 * Catalog payment discovery for UniCredit checkout financing.
 */
class MtUniCredit extends \Opencart\System\Engine\Model
{
    /**
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    public function getMethods(array $address = []): array
    {
        $this->load->language('extension/mt_uni_credit/payment/mt_uni_credit');

        if ($this->cart->hasSubscription()) {
            return [];
        }

        $status = false;
        if (!$this->config->get('config_checkout_payment_address')) {
            $status = true;
        } elseif (!$this->config->get('payment_mt_uni_credit_geo_zone_id')) {
            $status = true;
        } else {
            $query = $this->db->query(
                "SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone`
                 WHERE `geo_zone_id` = '" . (int) $this->config->get('payment_mt_uni_credit_geo_zone_id') . "'
                   AND `country_id` = '" . (int) ($address['country_id'] ?? 0) . "'
                   AND (`zone_id` = '" . (int) ($address['zone_id'] ?? 0) . "' OR `zone_id` = '0')"
            );
            $status = (bool) $query->num_rows;
        }

        if (!$status) {
            return [];
        }

        $this->load->model('extension/mt_uni_credit/module/mt_uni_credit_checkout');
        $currency = (string) ($this->session->data['currency'] ?? $this->config->get('config_currency'));
        if (!$this->model_extension_mt_uni_credit_module_mt_uni_credit_checkout->isPaymentMethodEligible($currency)) {
            return [];
        }

        $optionData[ModuleConstants::PAYMENT_CODE] = [
            'code' => ModuleConstants::PAYMENT_OPTION_CODE,
            'name' => $this->language->get('heading_title'),
        ];

        return [
            'code'       => ModuleConstants::PAYMENT_CODE,
            'name'       => $this->language->get('heading_title'),
            'option'     => $optionData,
            'sort_order' => $this->config->get('payment_mt_uni_credit_sort_order'),
        ];
    }
}
