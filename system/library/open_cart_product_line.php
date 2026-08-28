<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Authoritative resolved product line for calculator and order materialization. */
final class OpenCartProductLine
{
    public int $productId;

    public int $masterId;

    /** @var int[] */
    public array $categoryIds;

    public string $name;

    public string $model;

    public int $quantity;

    public int $subtract;

    public int $reward;

    public bool $shippingRequired;

    public float $unitPriceExTax;

    public float $unitTax;

    public float $financingPrice;

    /** @var array<int, int|string|list<int>> */
    public array $normalizedOptions;

    /** @var list<array<string, mixed>> */
    public array $orderOptions;

    /**
     * @param int[] $categoryIds
     * @param array<int, int|string|list<int>> $normalizedOptions
     * @param list<array<string, mixed>> $orderOptions
     */
    public function __construct(
        int $productId,
        int $masterId,
        array $categoryIds,
        string $name,
        string $model,
        int $quantity,
        int $subtract,
        int $reward,
        bool $shippingRequired,
        float $unitPriceExTax,
        float $unitTax,
        float $financingPrice,
        array $normalizedOptions,
        array $orderOptions
    ) {
        $this->productId = $productId;
        $this->masterId = $masterId;
        $this->categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        $this->name = $name;
        $this->model = $model;
        $this->quantity = $quantity;
        $this->subtract = $subtract;
        $this->reward = $reward;
        $this->shippingRequired = $shippingRequired;
        $this->unitPriceExTax = $unitPriceExTax;
        $this->unitTax = $unitTax;
        $this->financingPrice = $financingPrice;
        $this->normalizedOptions = $normalizedOptions;
        $this->orderOptions = $orderOptions;
    }

    public function toProductContext(): ProductContext
    {
        return new ProductContext($this->productId, $this->categoryIds, $this->financingPrice);
    }
}
