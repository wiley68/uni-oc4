<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Normalized financing offer DTO (immutable by convention). */
final class Offer
{
    public string $type;

    public string $kopCode;

    public int $months;

    public float $monthlyInstallment;

    public float $glp;

    public float $gpr;

    public float $financedAmount;

    public float $coefficient;

    public int $filterId;

    public function __construct(
        string $type,
        string $kopCode,
        int $months,
        float $monthlyInstallment,
        float $glp,
        float $gpr,
        float $financedAmount,
        float $coefficient,
        int $filterId = 0
    ) {
        $this->type = $type;
        $this->kopCode = $kopCode;
        $this->months = $months;
        $this->monthlyInstallment = $monthlyInstallment;
        $this->glp = $glp;
        $this->gpr = $gpr;
        $this->financedAmount = $financedAmount;
        $this->coefficient = $coefficient;
        $this->filterId = $filterId;
    }

    public function identityKey(): string
    {
        return $this->type . '|' . $this->kopCode . '|' . $this->months;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type'                  => $this->type,
            'visible'               => true,
            'kop_code'              => $this->kopCode,
            'installment_count'     => $this->months,
            'monthly_installment'   => $this->monthlyInstallment,
            'glp'                   => $this->glp,
            'gpr'                   => $this->gpr,
            'total_amount'          => $this->financedAmount,
            'kimb'                  => $this->coefficient,
            'filter_id'             => $this->filterId,
        ];
    }
}
