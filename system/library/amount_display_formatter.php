<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Financing amounts are already normalized to EUR before presentation. */
final class AmountDisplayFormatter
{
    /** @return array{primary:string} */
    public function format(float $amount): array
    {
        return ['primary' => number_format(abs($amount), 2, '.', '') . ' евро'];
    }
}
