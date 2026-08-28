<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Foundation for full-cart financing order drafts (Phase 6 synthetic input). */
final class CartOrderDraftFactory
{
    /**
     * @param list<array<string, mixed>> $products
     * @param list<array<string, mixed>> $totals
     */
    public function create(
        int $storeId,
        string $storeName,
        string $storeUrl,
        string $invoicePrefix,
        FinancingCustomerData $customer,
        FinancingAddressData $billingAddress,
        FinancingAddressData $shippingAddress,
        array $products,
        array $totals,
        float $orderTotal,
        int $languageId,
        string $languageCode,
        int $currencyId,
        string $currencyCode,
        float $currencyValue,
        array $shippingMethod,
        string $ip = '127.0.0.1'
    ): OrderDraft {
        return new OrderDraft(
            $storeId,
            $storeName,
            $storeUrl,
            $invoicePrefix,
            $customer,
            $billingAddress,
            $shippingAddress,
            PaymentIdentity::paymentMethod(),
            $shippingMethod,
            $products,
            $totals,
            $orderTotal,
            $languageId,
            $languageCode,
            $currencyId,
            $currencyCode,
            $currencyValue,
            '',
            $ip
        );
    }
}
