<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Display currency suffixes aligned with Woo (лв. / евро / лева). */
final class CurrencyDisplayLabel
{
    public function forAmount(string $iso): string
    {
        return match (strtoupper(trim($iso))) {
            'EUR' => 'евро',
            'BGN' => 'лв.',
            default => strtoupper(trim($iso)),
        };
    }

    public function forButton(string $iso, bool $dual): string
    {
        if (!$dual) {
            return $this->forAmount($iso);
        }

        return match (strtoupper(trim($iso))) {
            'EUR' => 'евро',
            'BGN' => 'лева',
            default => strtoupper(trim($iso)),
        };
    }
}
