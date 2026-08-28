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
