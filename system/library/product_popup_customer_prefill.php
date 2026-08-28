<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Product popup customer prefill — mirrors PS9 ProductPopupCustomerPrefill.
 */
final class ProductPopupCustomerPrefill
{
    /**
     * @param array<string, mixed> $customer
     * @param list<array<string, mixed>> $addresses
     * @return array<string, mixed>
     */
    public function present(bool $isLogged, array $customer, array $addresses, int $defaultAddressId = 0): array
    {
        $empty = [
            'firstname'  => '',
            'lastname'   => '',
            'address'    => '',
            'telephone'  => '',
            'email'      => '',
            'address_id' => 0,
            'is_logged'  => false,
        ];
        if (!$isLogged) {
            return $empty;
        }

        $address = $this->selectAddress($addresses, $defaultAddressId);

        return [
            'firstname'  => trim((string) ($address['firstname'] ?? $customer['firstname'] ?? '')),
            'lastname'   => trim((string) ($address['lastname'] ?? $customer['lastname'] ?? '')),
            'address'    => $this->joinAddress($address),
            'telephone'  => trim((string) ($address['telephone'] ?? $customer['telephone'] ?? '')),
            'email'      => trim((string) ($customer['email'] ?? '')),
            'address_id' => (int) ($address['address_id'] ?? 0),
            'is_logged'  => true,
        ];
    }

    /**
     * @param list<array<string, mixed>> $addresses
     * @return array<string, mixed>
     */
    private function selectAddress(array $addresses, int $preferredId): array
    {
        if ($preferredId > 0) {
            foreach ($addresses as $address) {
                if ((int) ($address['address_id'] ?? 0) === $preferredId) {
                    return $address;
                }
            }
        }

        return $addresses[0] ?? [];
    }

    /** @param array<string, mixed> $address */
    private function joinAddress(array $address): string
    {
        $parts = [];
        foreach (['address_1', 'address_2', 'city', 'postcode'] as $field) {
            $value = trim((string) ($address[$field] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return substr(implode(', ', $parts), 0, 256);
    }
}
