<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\OpenCartProductCatalogPort;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartProductLine;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;

final class InMemoryProductCatalogPort implements OpenCartProductCatalogPort
{
    /** @var array<int, array<string, mixed>> */
    private array $products;

    /** @var array<int, list<int>> */
    private array $categories;

    /** @var array<int, array<int, float>> option price deltas by product_option_value_id */

    private array $optionPrices;

    public function __construct(array $products = [], array $categories = [], array $optionPrices = [])
    {
        $this->products = $products;
        $this->categories = $categories;
        $this->optionPrices = $optionPrices;
    }

    public function resolveLine(
        int $storeId,
        int $productId,
        int $quantity,
        array $requestedOptions
    ): OpenCartProductLine {
        if ($storeId <= 0 || !isset($this->products[$productId])) {
            throw new ProductFinancingFlowException('validation', 'Product is unavailable.');
        }
        $product = $this->products[$productId];
        $optionPrice = 0.0;
        $orderOptions = [];
        $normalized = [];
        foreach ($requestedOptions as $optionId => $value) {
            $normalized[(int) $optionId] = $value;
            if (is_array($value)) {
                foreach ($value as $valueId) {
                    $optionPrice += (float) ($this->optionPrices[$productId][$valueId] ?? 0.0);
                    $orderOptions[] = [
                        'product_option_id' => (int) $optionId,
                        'product_option_value_id' => (int) $valueId,
                        'name' => 'Option',
                        'value' => 'Value ' . $valueId,
                        'type' => 'checkbox',
                    ];
                }
                continue;
            }
            $valueId = (int) $value;
            $optionPrice += (float) ($this->optionPrices[$productId][$valueId] ?? 0.0);
            $orderOptions[] = [
                'product_option_id' => (int) $optionId,
                'product_option_value_id' => $valueId,
                'name' => 'Option',
                'value' => 'Value ' . $valueId,
                'type' => 'select',
            ];
        }

        $unit = (float) ($product['special'] ?? $product['price']) + $optionPrice;
        $taxRate = (float) ($product['tax_rate'] ?? 0.2);
        $unitTax = round($unit * $taxRate, 4);
        $financingPrice = round(($unit + $unitTax) * $quantity, 4);

        return new OpenCartProductLine(
            $productId,
            0,
            $this->categories[$productId] ?? [(int) ($product['category_id'] ?? 0)],
            (string) ($product['name'] ?? 'Product'),
            (string) ($product['model'] ?? 'MODEL'),
            $quantity,
            1,
            0,
            (bool) ($product['shipping'] ?? false),
            $unit,
            $unitTax,
            $financingPrice,
            $normalized,
            $orderOptions
        );
    }
}
