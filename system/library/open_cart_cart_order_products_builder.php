<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Maps live OpenCart cart product rows into OrderDraft product/total arrays.
 */
final class OpenCartCartOrderProductsBuilder
{
    /**
     * @param list<array<string, mixed>> $cartProducts Cart::getProducts()
     * @param callable(float, int): float $unitTax ($priceExTax, $taxClassId) → unit tax amount
     * @return array{products: list<array<string, mixed>>, totals: list<array<string, mixed>>, order_total: float, shipping_required: bool}
     */
    public function build(array $cartProducts, callable $unitTax): array
    {
        $products = [];
        $subTotal = 0.0;
        $taxTotal = 0.0;
        $shippingRequired = false;

        foreach ($cartProducts as $product) {
            $productId = (int) ($product['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $quantity = max(1, (int) ($product['quantity'] ?? 1));
            $unitPrice = round((float) ($product['price'] ?? 0.0), 4);
            $taxClassId = (int) ($product['tax_class_id'] ?? 0);
            $unitTaxAmount = round($unitTax($unitPrice, $taxClassId), 4);
            $lineTotal = round($unitPrice * $quantity, 4);
            $subTotal += $lineTotal;
            $taxTotal += round($unitTaxAmount * $quantity, 4);
            if (!empty($product['shipping'])) {
                $shippingRequired = true;
            }

            $options = [];
            if (!empty($product['option']) && is_array($product['option'])) {
                foreach ($product['option'] as $option) {
                    $options[] = [
                        'product_option_id'       => (int) ($option['product_option_id'] ?? 0),
                        'product_option_value_id' => (int) ($option['product_option_value_id'] ?? 0),
                        'name'                    => (string) ($option['name'] ?? ''),
                        'value'                   => (string) ($option['value'] ?? ''),
                        'type'                    => (string) ($option['type'] ?? ''),
                    ];
                }
            }

            $products[] = [
                'product_id'   => $productId,
                'master_id'    => (int) ($product['master_id'] ?? 0),
                'name'         => (string) ($product['name'] ?? ''),
                'model'        => (string) ($product['model'] ?? ''),
                'quantity'     => $quantity,
                'subtract'     => (int) ($product['subtract'] ?? 1),
                'price'        => $unitPrice,
                'total'        => $lineTotal,
                'tax'          => $unitTaxAmount,
                'reward'       => (int) ($product['reward'] ?? 0) * $quantity,
                'option'       => $options,
                'subscription' => [],
            ];
        }

        $orderTotal = round($subTotal + $taxTotal, 2);
        $totals = [
            ['extension' => 'opencart', 'code' => 'sub_total', 'title' => 'Sub-Total', 'value' => round($subTotal, 4), 'sort_order' => 1],
        ];
        if ($taxTotal > 0) {
            $totals[] = ['extension' => 'opencart', 'code' => 'tax', 'title' => 'Tax', 'value' => round($taxTotal, 4), 'sort_order' => 5];
        }
        $totals[] = ['extension' => 'opencart', 'code' => 'total', 'title' => 'Total', 'value' => $orderTotal, 'sort_order' => 9];

        return [
            'products'          => $products,
            'totals'            => $totals,
            'order_total'       => $orderTotal,
            'shipping_required' => $shippingRequired,
        ];
    }
}
