<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Maps native OpenCart checkout order snapshot → shared customer/address validation input.
 *
 * Checkout does not rely on Product/Cart popup POST field names (e.g. phone vs telephone).
 */
final class CheckoutOrderCustomerAdapter
{
    /**
     * Shape accepted by {@see ProductCustomerValidator} + {@see ProductAddressValidator}.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function toValidationInput(array $order): array
    {
        $address1 = trim((string) ($order['payment_address_1'] ?? ''));
        $address2 = trim((string) ($order['payment_address_2'] ?? ''));

        return [
            'firstname'  => trim((string) ($order['payment_firstname'] ?? $order['firstname'] ?? '')),
            'lastname'   => trim((string) ($order['payment_lastname'] ?? $order['lastname'] ?? '')),
            'email'      => trim((string) ($order['email'] ?? '')),
            // Shared validator key is telephone; order stores telephone (never require POST phone).
            'telephone'  => trim((string) ($order['telephone'] ?? '')),
            'address'    => $address1,
            'address_1'  => $address1,
            'address_2'  => $address2,
            'company'    => trim((string) ($order['payment_company'] ?? '')),
            'city'       => trim((string) ($order['payment_city'] ?? '')),
            'postcode'   => trim((string) ($order['payment_postcode'] ?? '')),
            'country'    => trim((string) ($order['payment_country'] ?? '')),
            'country_id' => (string) (int) ($order['payment_country_id'] ?? 0),
            'zone'       => trim((string) ($order['payment_zone'] ?? '')),
            'zone_id'    => (string) (int) ($order['payment_zone_id'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $order
     */
    public function billingAddressFromOrder(array $order, FinancingCustomerData $customer): FinancingAddressData
    {
        return new FinancingAddressData(
            0,
            $customer->firstname,
            $customer->lastname,
            trim((string) ($order['payment_company'] ?? '')),
            trim((string) ($order['payment_address_1'] ?? '')),
            trim((string) ($order['payment_address_2'] ?? '')),
            trim((string) ($order['payment_city'] ?? '')),
            trim((string) ($order['payment_postcode'] ?? '')),
            trim((string) ($order['payment_country'] ?? '')),
            (int) ($order['payment_country_id'] ?? 0),
            trim((string) ($order['payment_zone'] ?? '')),
            (int) ($order['payment_zone_id'] ?? 0),
            (string) ($order['payment_address_format'] ?? ''),
            is_array($order['payment_custom_field'] ?? null) ? $order['payment_custom_field'] : []
        );
    }

    /**
     * @param array<string, mixed> $order
     */
    public function shippingAddressFromOrder(array $order, FinancingAddressData $billing): FinancingAddressData
    {
        $address1 = trim((string) ($order['shipping_address_1'] ?? ''));
        if ($address1 === '') {
            return $billing;
        }

        return new FinancingAddressData(
            0,
            trim((string) ($order['shipping_firstname'] ?? $billing->firstname)),
            trim((string) ($order['shipping_lastname'] ?? $billing->lastname)),
            trim((string) ($order['shipping_company'] ?? '')),
            $address1,
            trim((string) ($order['shipping_address_2'] ?? '')),
            trim((string) ($order['shipping_city'] ?? '')),
            trim((string) ($order['shipping_postcode'] ?? '')),
            trim((string) ($order['shipping_country'] ?? '')),
            (int) ($order['shipping_country_id'] ?? 0),
            trim((string) ($order['shipping_zone'] ?? '')),
            (int) ($order['shipping_zone_id'] ?? 0),
            (string) ($order['shipping_address_format'] ?? ''),
            is_array($order['shipping_custom_field'] ?? null) ? $order['shipping_custom_field'] : []
        );
    }

    /**
     * @param array<string, mixed> $order
     * @return array{name: string, code: string}
     */
    public function shippingMethodFromOrder(array $order): array
    {
        $shippingMethod = $order['shipping_method'] ?? [];
        if (is_string($shippingMethod)) {
            $decoded = json_decode($shippingMethod, true);
            $shippingMethod = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($shippingMethod)) {
            return ['name' => '', 'code' => ''];
        }

        return [
            'name' => (string) ($shippingMethod['name'] ?? ''),
            'code' => (string) ($shippingMethod['code'] ?? ''),
        ];
    }

    /**
     * Normalize consent ids from FormData keys consent[0] / consent[] / consent.
     *
     * @param array<string, mixed> $posted
     * @return list<int|string>
     */
    public function extractPostedConsents(array $posted): array
    {
        if (isset($posted['consent']) && is_array($posted['consent'])) {
            return array_values($posted['consent']);
        }

        $ids = [];
        foreach ($posted as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if ($key === 'consent[]' || preg_match('/^consent\[\d+]$/', $key) === 1) {
                if (is_array($value)) {
                    foreach ($value as $item) {
                        $ids[] = $item;
                    }
                } else {
                    $ids[] = $value;
                }
            }
        }

        return $ids;
    }
}
