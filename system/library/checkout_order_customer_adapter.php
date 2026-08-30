<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Resolves financing customer/address from native Checkout sources.
 *
 * OpenCart 4.1.0.3 fact (confirm.php): when config_checkout_payment_address is off,
 * payment_* order columns are intentionally empty strings while customer + shipping_*
 * still hold the authoritative values. Empty payment_* must not shadow those fields.
 *
 * Precedence (first non-empty wins per field):
 * 1. order payment_*
 * 2. order shipping_* (address/name when payment address unused)
 * 3. order customer columns (firstname/lastname/email/telephone)
 * 4. session.payment_address
 * 5. session.shipping_address
 * 6. session.customer
 * 7. verified logged-in address row (optional)
 *
 * Telephone must come from native Checkout sources (never fabricated). Empty
 * telephone is reported in {@see $missing} — CP requires a non-empty phone.
 * Browser POST customer fields are never authoritative.
 */
final class CheckoutOrderCustomerAdapter
{
    /**
     * @param array<string, mixed> $order
     * @param array{
     *     customer?: array<string, mixed>,
     *     payment_address?: array<string, mixed>,
     *     shipping_address?: array<string, mixed>
     * } $sessionCheckout
     * @param array<string, mixed>|null $verifiedAddress owned Address::getAddress() row
     * @return array{
     *     input: array<string, mixed>,
     *     sources: array<string, string>,
     *     missing: list<string>
     * }
     */
    public function fromCheckoutContext(
        array $order,
        array $sessionCheckout = [],
        ?array $verifiedAddress = null
    ): array {
        $customer = is_array($sessionCheckout['customer'] ?? null) ? $sessionCheckout['customer'] : [];
        $paymentAddress = is_array($sessionCheckout['payment_address'] ?? null) ? $sessionCheckout['payment_address'] : [];
        $shippingAddress = is_array($sessionCheckout['shipping_address'] ?? null) ? $sessionCheckout['shipping_address'] : [];
        $verifiedAddress = is_array($verifiedAddress) ? $verifiedAddress : [];

        $sources = [];

        $firstname = $this->firstNonEmpty([
            'order.payment_firstname'   => $order['payment_firstname'] ?? null,
            'order.shipping_firstname'  => $order['shipping_firstname'] ?? null,
            'order.firstname'           => $order['firstname'] ?? null,
            'session.payment_address.firstname'  => $paymentAddress['firstname'] ?? null,
            'session.shipping_address.firstname' => $shippingAddress['firstname'] ?? null,
            'session.customer.firstname'         => $customer['firstname'] ?? null,
            'verified_address.firstname'         => $verifiedAddress['firstname'] ?? null,
        ], $sources, 'firstname');

        $lastname = $this->firstNonEmpty([
            'order.payment_lastname'   => $order['payment_lastname'] ?? null,
            'order.shipping_lastname'  => $order['shipping_lastname'] ?? null,
            'order.lastname'           => $order['lastname'] ?? null,
            'session.payment_address.lastname'  => $paymentAddress['lastname'] ?? null,
            'session.shipping_address.lastname' => $shippingAddress['lastname'] ?? null,
            'session.customer.lastname'         => $customer['lastname'] ?? null,
            'verified_address.lastname'         => $verifiedAddress['lastname'] ?? null,
        ], $sources, 'lastname');

        $email = $this->firstNonEmpty([
            'order.email'               => $order['email'] ?? null,
            'session.customer.email'    => $customer['email'] ?? null,
        ], $sources, 'email');

        $telephone = $this->firstNonEmpty([
            'order.telephone'            => $order['telephone'] ?? null,
            'session.customer.telephone' => $customer['telephone'] ?? null,
            'verified_address.telephone' => $verifiedAddress['telephone'] ?? null,
        ], $sources, 'telephone');

        $address1 = $this->firstNonEmpty([
            'order.payment_address_1'   => $order['payment_address_1'] ?? null,
            'order.shipping_address_1'  => $order['shipping_address_1'] ?? null,
            'session.payment_address.address_1'  => $paymentAddress['address_1'] ?? null,
            'session.shipping_address.address_1' => $shippingAddress['address_1'] ?? null,
            'verified_address.address_1'         => $verifiedAddress['address_1'] ?? null,
        ], $sources, 'address');

        $address2 = $this->firstNonEmpty([
            'order.payment_address_2'   => $order['payment_address_2'] ?? null,
            'order.shipping_address_2'  => $order['shipping_address_2'] ?? null,
            'session.payment_address.address_2'  => $paymentAddress['address_2'] ?? null,
            'session.shipping_address.address_2' => $shippingAddress['address_2'] ?? null,
            'verified_address.address_2'         => $verifiedAddress['address_2'] ?? null,
        ], $sources, 'address_2');

        $company = $this->firstNonEmpty([
            'order.payment_company'  => $order['payment_company'] ?? null,
            'order.shipping_company' => $order['shipping_company'] ?? null,
            'session.payment_address.company'  => $paymentAddress['company'] ?? null,
            'session.shipping_address.company' => $shippingAddress['company'] ?? null,
            'verified_address.company'         => $verifiedAddress['company'] ?? null,
        ], $sources, 'company');

        $city = $this->firstNonEmpty([
            'order.payment_city'  => $order['payment_city'] ?? null,
            'order.shipping_city' => $order['shipping_city'] ?? null,
            'session.payment_address.city'  => $paymentAddress['city'] ?? null,
            'session.shipping_address.city' => $shippingAddress['city'] ?? null,
            'verified_address.city'         => $verifiedAddress['city'] ?? null,
        ], $sources, 'city');

        $postcode = $this->firstNonEmpty([
            'order.payment_postcode'  => $order['payment_postcode'] ?? null,
            'order.shipping_postcode' => $order['shipping_postcode'] ?? null,
            'session.payment_address.postcode'  => $paymentAddress['postcode'] ?? null,
            'session.shipping_address.postcode' => $shippingAddress['postcode'] ?? null,
            'verified_address.postcode'         => $verifiedAddress['postcode'] ?? null,
        ], $sources, 'postcode');

        $country = $this->firstNonEmpty([
            'order.payment_country'  => $order['payment_country'] ?? null,
            'order.shipping_country' => $order['shipping_country'] ?? null,
            'session.payment_address.country'  => $paymentAddress['country'] ?? null,
            'session.shipping_address.country' => $shippingAddress['country'] ?? null,
            'verified_address.country'         => $verifiedAddress['country'] ?? null,
        ], $sources, 'country');

        $countryId = $this->firstPositiveInt([
            'order.payment_country_id'  => $order['payment_country_id'] ?? null,
            'order.shipping_country_id' => $order['shipping_country_id'] ?? null,
            'session.payment_address.country_id'  => $paymentAddress['country_id'] ?? null,
            'session.shipping_address.country_id' => $shippingAddress['country_id'] ?? null,
            'verified_address.country_id'         => $verifiedAddress['country_id'] ?? null,
        ], $sources, 'country_id');

        $zone = $this->firstNonEmpty([
            'order.payment_zone'  => $order['payment_zone'] ?? null,
            'order.shipping_zone' => $order['shipping_zone'] ?? null,
            'session.payment_address.zone'  => $paymentAddress['zone'] ?? null,
            'session.shipping_address.zone' => $shippingAddress['zone'] ?? null,
            'verified_address.zone'         => $verifiedAddress['zone'] ?? null,
        ], $sources, 'zone');

        $zoneId = $this->firstPositiveInt([
            'order.payment_zone_id'  => $order['payment_zone_id'] ?? null,
            'order.shipping_zone_id' => $order['shipping_zone_id'] ?? null,
            'session.payment_address.zone_id'  => $paymentAddress['zone_id'] ?? null,
            'session.shipping_address.zone_id' => $shippingAddress['zone_id'] ?? null,
            'verified_address.zone_id'         => $verifiedAddress['zone_id'] ?? null,
        ], $sources, 'zone_id');

        $input = [
            'firstname'  => $firstname,
            'lastname'   => $lastname,
            'email'      => $email,
            'telephone'  => $telephone,
            'address'    => $address1,
            'address_1'  => $address1,
            'address_2'  => $address2,
            'company'    => $company,
            'city'       => $city,
            'postcode'   => $postcode,
            'country'    => $country,
            'country_id' => (string) $countryId,
            'zone'       => $zone,
            'zone_id'    => (string) $zoneId,
        ];

        // Checkout financing required identity (telephone required for CP POST /orders).
        $missing = [];
        foreach (['firstname', 'lastname', 'email', 'telephone', 'address'] as $required) {
            if (trim((string) ($input[$required] ?? '')) === '') {
                $missing[] = $required;
            }
        }

        return [
            'input'   => $input,
            'sources' => $sources,
            'missing' => $missing,
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     * @deprecated Prefer fromCheckoutContext(); kept for callers that only have an order row.
     */
    public function toValidationInput(array $order): array
    {
        return $this->fromCheckoutContext($order, [])['input'];
    }

    /**
     * @param array<string, mixed> $resolvedInput from fromCheckoutContext()['input']
     */
    public function billingAddressFromResolved(
        array $resolvedInput,
        FinancingCustomerData $customer
    ): FinancingAddressData {
        return new FinancingAddressData(
            0,
            $customer->firstname,
            $customer->lastname,
            (string) ($resolvedInput['company'] ?? ''),
            (string) ($resolvedInput['address_1'] ?? $resolvedInput['address'] ?? ''),
            (string) ($resolvedInput['address_2'] ?? ''),
            (string) ($resolvedInput['city'] ?? ''),
            (string) ($resolvedInput['postcode'] ?? ''),
            (string) ($resolvedInput['country'] ?? ''),
            (int) ($resolvedInput['country_id'] ?? 0),
            (string) ($resolvedInput['zone'] ?? ''),
            (int) ($resolvedInput['zone_id'] ?? 0)
        );
    }

    /**
     * @param array<string, mixed> $order
     */
    public function billingAddressFromOrder(array $order, FinancingCustomerData $customer): FinancingAddressData
    {
        $resolved = $this->fromCheckoutContext($order, []);

        return $this->billingAddressFromResolved($resolved['input'], $customer);
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

    /**
     * @param array<string, mixed> $candidates
     * @param array<string, string> $sources
     */
    private function firstNonEmpty(array $candidates, array &$sources, string $field): string
    {
        foreach ($candidates as $source => $value) {
            $text = trim((string) ($value ?? ''));
            if ($text !== '') {
                $sources[$field] = $source;

                return $text;
            }
        }
        $sources[$field] = 'missing';

        return '';
    }

    /**
     * @param array<string, mixed> $candidates
     * @param array<string, string> $sources
     */
    private function firstPositiveInt(array $candidates, array &$sources, string $field): int
    {
        foreach ($candidates as $source => $value) {
            $id = (int) ($value ?? 0);
            if ($id > 0) {
                $sources[$field] = $source;

                return $id;
            }
        }
        $sources[$field] = 'missing';

        return 0;
    }
}
