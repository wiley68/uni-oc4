<?php

namespace Opencart\Catalog\Model\Extension\MtUniCredit\Payment;

/**
 * Метод на плащане UniCredit (избор в чекаута).
 */
class Uni extends \Opencart\System\Engine\Model
{
    /**
     * @param array<string, mixed> $address
     *
     * @return array<string, mixed>
     */
    public function getMethods(array $address = []): array
    {
        $this->load->language('extension/mt_uni_credit/payment/uni');

        if ($this->cart->hasSubscription()) {
            $status = false;
        } elseif (!$this->config->get('config_checkout_payment_address')) {
            $status = true;
        } elseif (!(int) $this->config->get('payment_uni_geo_zone_id')) {
            $status = true;
        } else {
            $this->load->model('localisation/geo_zone');

            $results = $this->model_localisation_geo_zone->getGeoZone(
                (int) $this->config->get('payment_uni_geo_zone_id'),
                (int) $address['country_id'],
                (int) $address['zone_id']
            );

            $status = $results !== [];
        }

        $method_data = [];

        if ($status) {
            $option_data['uni'] = [
                'code' => 'uni.uni',
                'name' => $this->language->get('heading_title'),
            ];

            $method_data = [
                'code'       => 'uni',
                'name'       => $this->language->get('heading_title'),
                'option'     => $option_data,
                'sort_order' => (int) $this->config->get('payment_uni_sort_order'),
            ];
        }

        return $method_data;
    }
}
