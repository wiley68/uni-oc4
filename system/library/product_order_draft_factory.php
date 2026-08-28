<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Foundation for one-product financing order drafts (Phase 6 synthetic input). */
final class ProductOrderDraftFactory
{
    /**
     * @param list<array<string, mixed>> $options
     * @param list<array<string, mixed>> $totals
     */
    public function create(
        int $storeId,
        string $storeName,
        string $storeUrl,
        string $invoicePrefix,
        FinancingCustomerData $customer,
        FinancingAddressData $billingAddress,
        ?FinancingAddressData $shippingAddress,
        int $productId,
        string $productName,
        string $productModel,
        int $quantity,
        float $unitPriceExTax,
        float $unitTax,
        int $subtract,
        int $reward,
        array $options,
        array $totals,
        float $orderTotal,
        int $languageId,
        string $languageCode,
        int $currencyId,
        string $currencyCode,
        float $currencyValue,
        string $ip = '127.0.0.1'
    ): OrderDraft {
        $lineTotal = round($unitPriceExTax * $quantity, 4);

        return new OrderDraft(
            $storeId,
            $storeName,
            $storeUrl,
            $invoicePrefix,
            $customer,
            $billingAddress,
            $shippingAddress,
            PaymentIdentity::paymentMethod(),
            null,
            [[
                'product_id' => $productId,
                'master_id'  => 0,
                'name'       => $productName,
                'model'      => $productModel,
                'quantity'   => $quantity,
                'subtract'   => $subtract,
                'price'      => $unitPriceExTax,
                'total'      => $lineTotal,
                'tax'        => $unitTax,
                'reward'     => $reward * $quantity,
                'option'     => $options,
                'subscription' => [],
            ]],
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
