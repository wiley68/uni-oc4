<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Builds CartContext from live OpenCart cart products.
 *
 * Authoritative financed amount = cart merchandise total (tax-inc via cart->getTotal()).
 * Per-line ProductContext carries category ids for filter matching; price is overwritten
 * to cart total inside CartSchemeResolver (frozen Phase 5 semantics).
 */
final class OpenCartCartContextFactory
{
    /**
     * @param list<array<string, mixed>> $cartProducts OpenCart Cart::getProducts() rows
     * @param callable(int): list<int> $categoryLoader
     * @param callable(float, int): float $taxCalculator ($price, $taxClassId) → unit price with tax
     */
    public function __construct(
        private $categoryLoader,
        private $taxCalculator
    ) {
    }

    /**
     * @param list<array<string, mixed>> $cartProducts
     */
    public function create(array $cartProducts, float $cartTotal): CartContext
    {
        $lines = [];
        foreach ($cartProducts as $product) {
            $productId = (int) ($product['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $quantity = max(1, (int) ($product['quantity'] ?? 1));
            $unit = ($this->taxCalculator)((float) ($product['price'] ?? 0.0), (int) ($product['tax_class_id'] ?? 0));
            $lineTotal = round($unit * $quantity, 2);
            $categories = ($this->categoryLoader)($productId);
            $categories = array_values(array_unique(array_map('intval', $categories)));
            sort($categories);
            $optionValueIds = [];
            if (!empty($product['option']) && is_array($product['option'])) {
                foreach ($product['option'] as $option) {
                    $optionValueId = (int) ($option['product_option_value_id'] ?? 0);
                    if ($optionValueId > 0) {
                        $optionValueIds[] = $optionValueId;
                    }
                }
            }
            $optionValueIds = array_values(array_unique($optionValueIds));
            sort($optionValueIds);
            // Legacy single attribute id retained for scheme helpers; fingerprint uses full sorted option set.
            $attributeId = $optionValueIds === [] ? 0 : max($optionValueIds);
            $lines[] = new CartLine(
                new ProductContext($productId, $categories, $lineTotal),
                $attributeId,
                $quantity,
                $lineTotal,
                $optionValueIds
            );
        }

        return new CartContext($lines, round($cartTotal, 2));
    }
}
