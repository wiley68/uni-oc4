<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class OpenCartProductContextFactory
{
    public function __construct(private OpenCartProductCatalogPort $catalog)
    {
    }

    /**
     * @param array<int, int|string|list<int>> $requestedOptions
     */
    public function create(
        int $storeId,
        int $productId,
        int $quantity,
        array $requestedOptions
    ): OpenCartProductLine {
        if ($productId <= 0) {
            throw new ProductFinancingFlowException('validation', 'Invalid product.');
        }
        if ($quantity <= 0 || $quantity > 9999) {
            throw new ProductFinancingFlowException('validation', 'Invalid quantity.');
        }

        return $this->catalog->resolveLine($storeId, $productId, $quantity, $requestedOptions);
    }
}
