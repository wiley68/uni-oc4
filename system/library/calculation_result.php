<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class CalculationResult
{
    public AvailableScheme $scheme;

    public float $price;

    public FirstInstallmentState $firstInstallment;

    public float $financedAmount;

    public float $monthlyInstallment;

    public float $totalPayable;

    public float $glp;

    public float $gpr;

    public function __construct(
        AvailableScheme $scheme,
        float $price,
        FirstInstallmentState $firstInstallment,
        float $financedAmount,
        float $monthlyInstallment,
        float $totalPayable,
        float $glp,
        float $gpr
    ) {
        $this->scheme = $scheme;
        $this->price = $price;
        $this->firstInstallment = $firstInstallment;
        $this->financedAmount = $financedAmount;
        $this->monthlyInstallment = $monthlyInstallment;
        $this->totalPayable = $totalPayable;
        $this->glp = $glp;
        $this->gpr = $gpr;
    }
}
