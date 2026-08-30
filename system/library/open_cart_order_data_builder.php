<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Maps normalized {@see OrderDraft} to native OpenCart addOrder() payload. */
final class OpenCartOrderDataBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(OrderDraft $draft): array
    {
        $shipping = $draft->shippingAddress ?? $draft->billingAddress;

        return [
            'subscription_id'        => 0,
            'invoice_prefix'         => $draft->invoicePrefix,
            'store_id'               => $draft->storeId,
            'store_name'             => $draft->storeName,
            'store_url'              => $draft->storeUrl,
            'customer_id'            => $draft->customer->customerId,
            'customer_group_id'      => $draft->customer->customerGroupId,
            'firstname'              => $draft->customer->firstname,
            'lastname'               => $draft->customer->lastname,
            'email'                  => $draft->customer->email,
            'telephone'              => $draft->customer->telephone,
            'custom_field'           => $draft->customer->customField,
            'payment_address_id'     => $draft->billingAddress->addressId,
            'payment_firstname'      => $draft->billingAddress->firstname,
            'payment_lastname'       => $draft->billingAddress->lastname,
            'payment_company'        => $draft->billingAddress->company,
            'payment_address_1'      => $draft->billingAddress->address1,
            'payment_address_2'      => $draft->billingAddress->address2,
            'payment_city'           => $draft->billingAddress->city,
            'payment_postcode'       => $draft->billingAddress->postcode,
            'payment_country'        => $draft->billingAddress->country,
            'payment_country_id'     => $draft->billingAddress->countryId,
            'payment_zone'           => $draft->billingAddress->zone,
            'payment_zone_id'        => $draft->billingAddress->zoneId,
            'payment_address_format' => $draft->billingAddress->addressFormat,
            'payment_custom_field'   => $draft->billingAddress->customField,
            'payment_method'         => $draft->paymentMethod,
            'shipping_address_id'     => $shipping->addressId,
            'shipping_firstname'      => $shipping->firstname,
            'shipping_lastname'       => $shipping->lastname,
            'shipping_company'        => $shipping->company,
            'shipping_address_1'      => $shipping->address1,
            'shipping_address_2'      => $shipping->address2,
            'shipping_city'           => $shipping->city,
            'shipping_postcode'       => $shipping->postcode,
            'shipping_country'        => $shipping->country,
            'shipping_country_id'     => $shipping->countryId,
            'shipping_zone'           => $shipping->zone,
            'shipping_zone_id'        => $shipping->zoneId,
            'shipping_address_format' => $shipping->addressFormat,
            'shipping_custom_field'   => $shipping->customField,
            'shipping_method'         => ShippingMethodSnapshot::normalize(
                is_array($draft->shippingMethod) ? $draft->shippingMethod : ShippingMethodSnapshot::empty()
            ),
            'comment'                 => $draft->comment,
            'total'                   => $draft->orderTotal,
            'affiliate_id'            => 0,
            'commission'              => 0.0,
            'marketing_id'            => 0,
            'tracking'                => '',
            'language_id'             => $draft->languageId,
            'language_code'           => $draft->languageCode,
            'currency_id'             => $draft->currencyId,
            'currency_code'           => $draft->currencyCode,
            'currency_value'          => $draft->currencyValue,
            'ip'                      => $draft->ip,
            'forwarded_ip'            => $draft->forwardedIp,
            'user_agent'              => $draft->userAgent,
            'accept_language'         => $draft->acceptLanguage,
            'products'                => $this->mapProducts($draft->products),
            'vouchers'                => [],
            'totals'                  => $draft->totals,
        ];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    private function mapProducts(array $products): array
    {
        $mapped = [];
        foreach ($products as $product) {
            $mapped[] = [
                'product_id'   => (int) ($product['product_id'] ?? 0),
                'master_id'    => (int) ($product['master_id'] ?? 0),
                'name'         => (string) ($product['name'] ?? ''),
                'model'        => (string) ($product['model'] ?? ''),
                'option'       => $product['option'] ?? [],
                'download'     => [],
                'quantity'     => (int) ($product['quantity'] ?? 0),
                'subtract'     => (int) ($product['subtract'] ?? 0),
                'price'        => (float) ($product['price'] ?? 0.0),
                'total'        => (float) ($product['total'] ?? 0.0),
                'tax'          => (float) ($product['tax'] ?? 0.0),
                'reward'       => (int) ($product['reward'] ?? 0),
                'subscription' => $product['subscription'] ?? [],
            ];
        }

        return $mapped;
    }
}
