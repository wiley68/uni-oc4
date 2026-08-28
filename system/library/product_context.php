<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Platform-neutral product financing context (Phase 5 domain). */
final class ProductContext
{
    public int $productId;

    /** @var int[] */
    public array $categoryIds;

    public float $price;

    /** @param int[] $categoryIds */
    public function __construct(int $productId, array $categoryIds, float $price)
    {
        $this->productId = $productId;
        $this->categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        $this->price = $price;
    }
}
