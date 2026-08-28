<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

interface OpenCartProductCatalogPort
{
    /**
     * @param array<int, int|string|list<int>> $requestedOptions product_option_id => value(s)
     *
     * @throws ProductFinancingFlowException
     */
    public function resolveLine(
        int $storeId,
        int $productId,
        int $quantity,
        array $requestedOptions
    ): OpenCartProductLine;
}
