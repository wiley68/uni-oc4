<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class CartLine
{
    public ProductContext $product;

    public int $productAttributeId;

    public int $quantity;

    public float $lineTotal;

    /** @var list<int> Canonical product_option_value_id list for fingerprinting. */
    public array $optionValueIds = [];

    /**
     * @param list<int> $optionValueIds
     */
    public function __construct(
        ProductContext $product,
        int $productAttributeId,
        int $quantity,
        float $lineTotal,
        array $optionValueIds = []
    ) {
        $this->product = $product;
        $this->productAttributeId = max(0, $productAttributeId);
        $this->quantity = max(1, $quantity);
        $this->lineTotal = round($lineTotal, 2);
        $ids = array_values(array_unique(array_map('intval', $optionValueIds)));
        sort($ids);
        $this->optionValueIds = $ids;
    }

    /** @return list<int> */
    public function optionValueIds(): array
    {
        return $this->optionValueIds;
    }
}
