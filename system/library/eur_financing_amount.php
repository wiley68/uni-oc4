<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** OpenCart stores native order amounts in base units and snapshots the selected currency factor. */
final class EurFinancingAmount
{
    public static function fromOrder(float $baseAmount, string $code, int $id, float $value): float
    {
        if (!(new CurrencyGate())->supports($code) || $id <= 0 || !is_finite($value) || $value <= 0.0) {
            throw new \InvalidArgumentException('EUR order currency provenance is invalid.');
        }

        $amount = $baseAmount * $value;
        if (!is_finite($amount)) {
            throw new \InvalidArgumentException('EUR order amount is invalid.');
        }

        return round($amount, 2);
    }

}
