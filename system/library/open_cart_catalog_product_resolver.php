<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Resolves authoritative product pricing/options using injected OpenCart runtime ports.
 */
final class OpenCartCatalogProductResolver implements OpenCartProductCatalogPort
{
    /**
     * @param callable(int): array<string, mixed>|null $productLoader
     * @param callable(int): list<int> $categoryLoader
     * @param callable(int, int, array<int, int|string|list<int>>): array{option_price: float, order_options: list<array<string, mixed>>, normalized: array<int, int|string|list<int>>} $optionResolver
     * @param callable(float, int): float $taxCalculator
     * @param callable(float, string, string): float $currencyConverter
     */
    public function __construct(
        private int $storeId,
        private int $customerGroupId,
        private string $baseCurrency,
        private string $displayCurrency,
        private bool $customerPriceVisible,
        $productLoader,
        $categoryLoader,
        $optionResolver,
        $taxCalculator,
        $currencyConverter
    ) {
        $this->productLoader = $productLoader;
        $this->categoryLoader = $categoryLoader;
        $this->optionResolver = $optionResolver;
        $this->taxCalculator = $taxCalculator;
        $this->currencyConverter = $currencyConverter;
    }

    /** @var callable(int): array<string, mixed>|null */
    private $productLoader;

    /** @var callable(int): list<int> */
    private $categoryLoader;

    /** @var callable(int, int, array<int, int|string|list<int>>): array{option_price: float, order_options: list<array<string, mixed>>, normalized: array<int, int|string|list<int>>} */
    private $optionResolver;

    /** @var callable(float, int): float */
    private $taxCalculator;

    /** @var callable(float, string, string): float */
    private $currencyConverter;

    public function resolveLine(
        int $storeId,
        int $productId,
        int $quantity,
        array $requestedOptions
    ): OpenCartProductLine {
        if ($storeId !== $this->storeId) {
            throw new ProductFinancingFlowException('validation', 'Invalid store scope.');
        }
        if (!$this->customerPriceVisible) {
            throw new ProductFinancingFlowException('validation', 'Product price is unavailable.');
        }

        $product = ($this->productLoader)($productId);
        if ($product === null || (int) ($product['status'] ?? 1) !== 1) {
            throw new ProductFinancingFlowException('validation', 'Product is unavailable.');
        }

        $optionData = ($this->optionResolver)($productId, $quantity, $requestedOptions);
        $baseUnit = (float) ($product['special'] ?: ($product['discount'] ?: $product['price']));
        $baseUnit += (float) $optionData['option_price'];
        $taxClassId = (int) ($product['tax_class_id'] ?? 0);
        $unitExTax = $baseUnit;
        $unitWithTax = ($this->taxCalculator)($baseUnit, $taxClassId);
        $unitTax = max(0.0, $unitWithTax - $unitExTax);
        $displayUnitWithTax = ($this->currencyConverter)($unitWithTax, $this->baseCurrency, $this->displayCurrency);
        $financingPrice = round($displayUnitWithTax * $quantity, 4);
        if ($financingPrice <= 0) {
            throw new ProductFinancingFlowException('validation', 'Product price is invalid.');
        }

        return new OpenCartProductLine(
            $productId,
            (int) ($product['master_id'] ?? 0),
            ($this->categoryLoader)($productId),
            (string) ($product['name'] ?? ''),
            (string) ($product['model'] ?? ''),
            $quantity,
            (int) ($product['subtract'] ?? 1),
            (int) ($product['reward'] ?? 0),
            (int) ($product['shipping'] ?? 1) === 1,
            $unitExTax,
            $unitTax,
            $financingPrice,
            $optionData['normalized'],
            $optionData['order_options']
        );
    }
}
