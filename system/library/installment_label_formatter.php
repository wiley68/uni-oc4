<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class InstallmentLabelFormatter
{
    public function format(int $months, float $monthlyInstallment): string
    {
        return sprintf('%d x %s евро', $months, number_format(abs($monthlyInstallment), 2, '.', ''));
    }
}
