<?php

/**
 * Ануитетна периодна лихва и форматиране на ГПР (аналог на PrestaShop FinancialRateHelper).
 */
final class MtUniCreditFinancialRate
{
    private const PRECISION = 1.0e-8;

    private const MAX_ITERATIONS = 128;

    private const GPR_NEAR_ZERO_PERCENT = 0.05;

    public static function periodicRate(
        float $nper,
        float $pmt,
        float $pv,
        float $fv = 0.0,
        int $type = 0,
        float $guess = 0.1
    ): float {
        $rate = $guess;
        if (abs($rate) >= self::PRECISION) {
            $f = exp($nper * log(1 + $rate));
        }
        if (!isset($f)) {
            $f = exp($nper * log(1 + $rate));
        }
        $y0 = $pv + $pmt * $nper + $fv;
        $y1 = $pv * $f + $pmt * (1 / $rate + $type) * ($f - 1) + $fv;
        $i = $x0 = 0.0;
        $x1 = $rate;
        while ((abs($y0 - $y1) > self::PRECISION) && ($i < self::MAX_ITERATIONS)) {
            $rate = ($y1 * $x0 - $y0 * $x1) / ($y1 - $y0);
            $x0 = $x1;
            $x1 = $rate;
            if (abs($rate) < self::PRECISION) {
                $y = $pv * (1 + $nper * $rate) + $pmt * (1 + $rate * $type) * $nper + $fv;
            } else {
                $f = exp($nper * log(1 + $rate));
                $y = $pv * $f + $pmt * (1 / $rate + $type) * ($f - 1) + $fv;
            }
            $y0 = $y1;
            $y1 = $y;
            ++$i;
        }

        return $rate;
    }

    public static function formatGprPercentForDisplay(float $percentPoints): string
    {
        $a = abs($percentPoints);
        if ($a <= self::GPR_NEAR_ZERO_PERCENT) {
            return '0.00';
        }

        return number_format($a, 2, '.', '');
    }
}
