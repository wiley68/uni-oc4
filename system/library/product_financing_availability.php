<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class ProductFinancingAvailability
{
    public function __construct(
        private CurrencyGate $currencyGate,
        private Calculator $calculator
    ) {
    }

    /**
     * @param array<string, mixed>|null $shop
     */
    public function isCalculatorVisible(
        bool $moduleEnabled,
        ?array $shop,
        string $currencyIso,
        ?OpenCartProductLine $line
    ): bool {
        if (!$moduleEnabled || $shop === null || $line === null) {
            return false;
        }
        if (!$this->currencyGate->supports($shop, $currencyIso)) {
            return false;
        }
        if ($line->financingPrice <= 0) {
            return false;
        }

        $product = $line->toProductContext();
        $preferred = $this->calculator->resolvePreferredOffers($shop, $product);

        return $preferred['standard'] !== null || $preferred['promo'] !== null;
    }
}
