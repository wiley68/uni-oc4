<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

interface ProductAddressCatalogPort
{
    /**
     * @param array<string, string> $postedAddress
     *
     * @throws ProductFinancingFlowException
     */
    public function resolveBillingAddress(
        int $customerId,
        array $postedAddress,
        ?int $postedAddressId,
        FinancingCustomerData $customer
    ): FinancingAddressData;

    /**
     * @throws ProductFinancingFlowException
     */
    public function resolveShippingAddress(
        bool $shippingRequired,
        FinancingAddressData $billingAddress,
        array $postedAddress,
        ?int $postedShippingAddressId,
        int $customerId
    ): ?FinancingAddressData;

    /**
     * @throws ProductFinancingFlowException
     */
    public function resolveShippingMethod(
        FinancingAddressData $shippingAddress,
        OpenCartProductLine $line
    ): ?array;
}
