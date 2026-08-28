<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class ProductAddressValidator
{
    /**
     * @param array<string, mixed> $posted
     * @return array<string, string>
     */
    public function extractPostedAddress(array $posted): array
    {
        return [
            'address_1'  => trim((string) ($posted['address_1'] ?? $posted['address'] ?? '')),
            'address_2'  => trim((string) ($posted['address_2'] ?? '')),
            'city'       => trim((string) ($posted['city'] ?? '')),
            'postcode'   => trim((string) ($posted['postcode'] ?? '')),
            'zone'       => trim((string) ($posted['zone'] ?? '')),
            'zone_id'    => trim((string) ($posted['zone_id'] ?? '')),
            'country'    => trim((string) ($posted['country'] ?? '')),
            'country_id' => trim((string) ($posted['country_id'] ?? '')),
            'company'    => trim((string) ($posted['company'] ?? '')),
        ];
    }

    /**
     * @param array<string, string> $address
     * @return array<string, string>
     */
    public function validateRequired(array $address): array
    {
        $errors = [];
        if ($address['address_1'] === '' || mb_strlen($address['address_1']) > 128) {
            $errors['address_1'] = 'Моля, въведете адрес.';
        }
        if ($address['city'] === '' || mb_strlen($address['city']) > 128) {
            $errors['city'] = 'Моля, въведете град.';
        }
        if ($address['postcode'] === '' || mb_strlen($address['postcode']) > 10) {
            $errors['postcode'] = 'Моля, въведете пощенски код.';
        }
        if ((int) $address['country_id'] <= 0) {
            $errors['country_id'] = 'Моля, изберете държава.';
        }
        if ((int) $address['zone_id'] <= 0 && $address['zone'] === '') {
            $errors['zone_id'] = 'Моля, изберете област.';
        }

        if ($errors !== []) {
            throw new ProductFinancingFlowException('validation', 'Моля, коригирайте адреса.', $errors);
        }

        return $address;
    }
}
