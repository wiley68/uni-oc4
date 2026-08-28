<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class OpenCartCatalogAddressResolver implements ProductAddressCatalogPort
{
    /**
     * @param callable(int, int): bool $addressOwnershipChecker
     * @param callable(int): array<string, mixed>|null $addressLoader
     * @param callable(array<string, string>, FinancingCustomerData): FinancingAddressData $postedAddressBuilder
     * @param callable(FinancingAddressData, OpenCartProductLine): array<string, string>|null $shippingMethodResolver
     */
    public function __construct(
        $addressOwnershipChecker,
        $addressLoader,
        $postedAddressBuilder,
        $shippingMethodResolver
    ) {
        $this->addressOwnershipChecker = $addressOwnershipChecker;
        $this->addressLoader = $addressLoader;
        $this->postedAddressBuilder = $postedAddressBuilder;
        $this->shippingMethodResolver = $shippingMethodResolver;
    }

    /** @var callable(int, int): bool */
    private $addressOwnershipChecker;

    /** @var callable(int): array<string, mixed>|null */
    private $addressLoader;

    /** @var callable(array<string, string>, FinancingCustomerData): FinancingAddressData */
    private $postedAddressBuilder;

    /** @var callable(FinancingAddressData, OpenCartProductLine): array<string, string>|null */
    private $shippingMethodResolver;

    public function resolveBillingAddress(
        int $customerId,
        array $postedAddress,
        ?int $postedAddressId,
        FinancingCustomerData $customer
    ): FinancingAddressData {
        if ($customerId > 0 && $postedAddressId !== null && $postedAddressId > 0) {
            if (!($this->addressOwnershipChecker)($postedAddressId, $customerId)) {
                throw new ProductFinancingFlowException('validation', 'Избраният адрес не принадлежи на текущия клиент.', [
                    'address_id' => 'Избраният адрес не принадлежи на текущия клиент.',
                ]);
            }
            $loaded = ($this->addressLoader)($postedAddressId);
            if ($loaded === null) {
                throw new ProductFinancingFlowException('validation', 'Адресът не е намерен.');
            }

            return $this->mapAddress($loaded, $customer);
        }

        return ($this->postedAddressBuilder)($postedAddress, $customer);
    }

    public function resolveShippingAddress(
        bool $shippingRequired,
        FinancingAddressData $billingAddress,
        array $postedAddress,
        ?int $postedShippingAddressId,
        int $customerId
    ): ?FinancingAddressData {
        if (!$shippingRequired) {
            return null;
        }
        if ($customerId > 0 && $postedShippingAddressId !== null && $postedShippingAddressId > 0) {
            if (!($this->addressOwnershipChecker)($postedShippingAddressId, $customerId)) {
                throw new ProductFinancingFlowException('validation', 'Избраният адрес за доставка не принадлежи на текущия клиент.');
            }
            $loaded = ($this->addressLoader)($postedShippingAddressId);
            if ($loaded === null) {
                throw new ProductFinancingFlowException('validation', 'Адресът за доставка не е намерен.');
            }

            return $this->mapAddress($loaded, new FinancingCustomerData(
                $customerId,
                0,
                (string) ($loaded['firstname'] ?? ''),
                (string) ($loaded['lastname'] ?? ''),
                '',
                ''
            ));
        }

        return $billingAddress;
    }

    public function resolveShippingMethod(
        FinancingAddressData $shippingAddress,
        OpenCartProductLine $line
    ): ?array {
        return ($this->shippingMethodResolver)($shippingAddress, $line);
    }

    /** @param array<string, mixed> $row */
    private function mapAddress(array $row, FinancingCustomerData $customer): FinancingAddressData
    {
        return new FinancingAddressData(
            (int) ($row['address_id'] ?? 0),
            (string) ($row['firstname'] ?? $customer->firstname),
            (string) ($row['lastname'] ?? $customer->lastname),
            (string) ($row['company'] ?? ''),
            (string) ($row['address_1'] ?? ''),
            (string) ($row['address_2'] ?? ''),
            (string) ($row['city'] ?? ''),
            (string) ($row['postcode'] ?? ''),
            (string) ($row['country'] ?? ''),
            (int) ($row['country_id'] ?? 0),
            (string) ($row['zone'] ?? ''),
            (int) ($row['zone_id'] ?? 0),
            (string) ($row['address_format'] ?? ''),
            is_array($row['custom_field'] ?? null) ? $row['custom_field'] : []
        );
    }
}
