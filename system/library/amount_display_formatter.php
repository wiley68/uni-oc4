<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Dual-currency amount display (Woo uni_eur parity). */
final class AmountDisplayFormatter
{
    public const DISPLAY_RATE = 1.95583;

    private CurrencyDisplayLabel $labels;

    public function __construct(?CurrencyDisplayLabel $labels = null)
    {
        $this->labels = $labels ?? new CurrencyDisplayLabel();
    }

    /** @param array<string, mixed> $shop @return array{primary:string,secondary:string,dual:bool} */
    public function format(float $amount, array $shop): array
    {
        $mode = (int) ($shop['uni_eur'] ?? 0);
        $primaryCurrency = in_array($mode, [2, 3], true) ? 'EUR' : 'BGN';
        $primary = number_format(abs($amount), 2, '.', '') . ' ' . $this->labels->forAmount($primaryCurrency);
        if (!in_array($mode, [1, 2], true)) {
            return ['primary' => $primary, 'secondary' => '', 'dual' => false];
        }
        $secondary = $mode === 1 ? round($amount / self::DISPLAY_RATE, 2) : round($amount * self::DISPLAY_RATE, 2);
        $secondaryCurrency = $mode === 1 ? 'EUR' : 'BGN';

        return [
            'primary'   => $primary,
            'secondary' => number_format(abs($secondary), 2, '.', '') . ' ' . $this->labels->forAmount($secondaryCurrency),
            'dual'      => true,
        ];
    }
}
