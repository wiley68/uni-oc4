<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Process 2 supplemental fields — EGN + phone2 only (PS9/PS8/Woo parity).
 */
final class ProcessTwoSensitiveData
{
    public function __construct(
        public string $egn,
        public string $phone2
    ) {
    }

    /** @return array{egn: string, phone2: string} */
    public function toArray(): array
    {
        return [
            'egn' => $this->egn,
            'phone2' => $this->phone2,
        ];
    }
}
