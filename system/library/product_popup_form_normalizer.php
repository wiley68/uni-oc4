<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Maps established Product popup POST fields to OpenCart financing validators.
 */
final class ProductPopupFormNormalizer
{
    /**
     * @param array<string, mixed> $posted
     * @return array<string, mixed>
     */
    public static function applicantFields(array $posted): array
    {
        return array_intersect_key($posted, array_flip([
            'firstname', 'first_name', 'lastname', 'last_name', 'email', 'telephone', 'phone',
            'address', 'address_1', 'address_2', 'city', 'postcode', 'zone', 'zone_id',
            'country', 'country_id', 'company', 'address_id', 'shipping_address_id',
            'consent', 'consent[]',
        ]));
    }

    /**
     * @param array<string, mixed> $posted
     * @param array<string, mixed> $storeDefaults country_id, zone_id, country, zone, city, postcode
     * @return array<string, mixed>
     */
    public function normalize(array $posted, array $storeDefaults): array
    {
        $normalized = $posted;

        if (isset($posted['first_name']) && !isset($posted['firstname'])) {
            $normalized['firstname'] = $posted['first_name'];
        }
        if (isset($posted['last_name']) && !isset($posted['lastname'])) {
            $normalized['lastname'] = $posted['last_name'];
        }
        if (isset($posted['phone']) && !isset($posted['telephone'])) {
            $normalized['telephone'] = $posted['phone'];
        }

        $addressLine = trim((string) ($posted['address'] ?? ''));
        if ($addressLine !== '' && trim((string) ($posted['address_1'] ?? '')) === '') {
            $normalized['address_1'] = $addressLine;
        }

        foreach (['city', 'postcode', 'country_id', 'zone_id', 'country', 'zone'] as $field) {
            if (trim((string) ($normalized[$field] ?? '')) === '' && isset($storeDefaults[$field])) {
                $normalized[$field] = $storeDefaults[$field];
            }
        }

        if (isset($posted['consent']) && !isset($posted['consent[]'])) {
            $normalized['consent'] = $posted['consent'];
        }

        return $normalized;
    }
}
