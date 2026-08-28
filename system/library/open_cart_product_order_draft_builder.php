<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class OpenCartProductOrderDraftBuilder
{
    public function __construct(private ProductOrderDraftFactory $factory)
    {
    }

    /**
     * @param array<string, mixed>|null $shippingMethod
     */
    public function build(
        int $storeId,
        string $storeName,
        string $storeUrl,
        string $invoicePrefix,
        FinancingCustomerData $customer,
        FinancingAddressData $billingAddress,
        ?FinancingAddressData $shippingAddress,
        ?array $shippingMethod,
        OpenCartProductLine $line,
        float $orderTotal,
        int $languageId,
        string $languageCode,
        int $currencyId,
        string $currencyCode,
        float $currencyValue,
        string $ip = '127.0.0.1'
    ): OrderDraft {
        $subTotal = round($line->unitPriceExTax * $line->quantity, 4);
        $taxTotal = round($line->unitTax * $line->quantity, 4);
        $totals = [
            ['extension' => '', 'code' => 'sub_total', 'title' => 'Sub-Total', 'value' => $subTotal, 'sort_order' => 1],
        ];
        if ($taxTotal > 0) {
            $totals[] = ['extension' => '', 'code' => 'tax', 'title' => 'Tax', 'value' => $taxTotal, 'sort_order' => 5];
        }
        $totals[] = ['extension' => '', 'code' => 'total', 'title' => 'Total', 'value' => $orderTotal, 'sort_order' => 9];

        $draft = $this->factory->create(
            $storeId,
            $storeName,
            $storeUrl,
            $invoicePrefix,
            $customer,
            $billingAddress,
            $shippingAddress,
            $line->productId,
            $line->name,
            $line->model,
            $line->quantity,
            $line->unitPriceExTax,
            $line->unitTax,
            $line->subtract,
            $line->reward,
            $line->orderOptions,
            $totals,
            $orderTotal,
            $languageId,
            $languageCode,
            $currencyId,
            $currencyCode,
            $currencyValue,
            $ip
        );

        if ($shippingMethod !== null) {
            $draft->shippingMethod = $shippingMethod;
        }

        return $draft;
    }
}
