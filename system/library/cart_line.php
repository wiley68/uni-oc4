<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class CartLine
{
    public ProductContext $product;

    public int $productAttributeId;

    public int $quantity;

    public float $lineTotal;

    public function __construct(ProductContext $product, int $productAttributeId, int $quantity, float $lineTotal)
    {
        $this->product = $product;
        $this->productAttributeId = max(0, $productAttributeId);
        $this->quantity = max(1, $quantity);
        $this->lineTotal = round($lineTotal, 2);
    }
}
