<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class InstallmentLabelFormatter
{
    public function format(int $months, float $monthlyInstallment, int $currencyMode): string
    {
        $amount = number_format(abs($monthlyInstallment), 2, '.', '');
        $suffix = in_array($currencyMode, [2, 3], true) ? ' €' : ' лв.';

        return $months . ' x ' . $amount . $suffix;
    }
}
