<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Woo-compatible installment button label (e.g. "12 x 97.49 евро"). */
final class InstallmentLabelFormatter
{
    private const DISPLAY_RATE = 1.95583;

    public function __construct(private CurrencyDisplayLabel $labels = new CurrencyDisplayLabel())
    {
    }

    public function format(int $months, float $monthlyInstallment, int $currencyMode): string
    {
        $amount = number_format(abs($monthlyInstallment), 2, '.', '');

        if ($currencyMode === 1 || $currencyMode === 2) {
            $secondary = $currencyMode === 1
                ? round($monthlyInstallment / self::DISPLAY_RATE, 2)
                : round($monthlyInstallment * self::DISPLAY_RATE, 2);
            $primaryIso = $currencyMode === 1 ? 'BGN' : 'EUR';
            $secondaryIso = $currencyMode === 1 ? 'EUR' : 'BGN';

            return sprintf(
                '%d x %s %s (%s %s)',
                $months,
                $amount,
                $this->labels->forButton($primaryIso, true),
                number_format($secondary, 2, '.', ''),
                $this->labels->forButton($secondaryIso, true)
            );
        }

        return sprintf(
            '%d x %s %s',
            $months,
            $amount,
            $this->labels->forAmount($currencyMode === 3 ? 'EUR' : 'BGN')
        );
    }
}
