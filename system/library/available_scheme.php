<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class AvailableScheme
{
    public string $type;

    public string $kopCode;

    public int $months;

    public int $filterId;

    /** @var array<string, mixed>|null */
    public ?array $filter;

    /** @var array<string, mixed> */
    public array $coefficient;

    public bool $firstInstallmentAmbiguous;

    /**
     * @param array<string, mixed>|null $filter
     * @param array<string, mixed> $coefficient
     */
    public function __construct(
        string $type,
        string $kopCode,
        int $months,
        int $filterId,
        ?array $filter,
        array $coefficient,
        bool $firstInstallmentAmbiguous = false
    ) {
        $this->type = $type;
        $this->kopCode = $kopCode;
        $this->months = $months;
        $this->filterId = $filterId;
        $this->filter = $filter;
        $this->coefficient = $coefficient;
        $this->firstInstallmentAmbiguous = $firstInstallmentAmbiguous;
    }

    public function identityKey(): string
    {
        return $this->type . '|' . $this->kopCode . '|' . $this->months;
    }
}
