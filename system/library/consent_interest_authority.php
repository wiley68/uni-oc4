<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Reads all raw matching rows independently of the financial selector. */
final class ConsentInterestAuthority
{
    public static function classify(array $shop, string $kop, int $months): array
    {
        $rows = [];
        $classes = [];
        $invalid = false;
        foreach (is_array($shop['coeff_list'] ?? null) ? $shop['coeff_list'] : [] as $row) {
            if (!is_array($row)) continue;
            $code = $row['onlineProductCode'] ?? null;
            $codeText = is_array($code) ? 'Array' : (is_scalar($code) || $code === null ? (string) $code : '');
            if (!is_scalar($row['installmentCount'] ?? null) || trim($codeText) !== $kop
                || (int) ($row['installmentCount'] ?? 0) !== $months) continue;
            $interest = $row['interestPercent'] ?? null;
            $coefficient = $row['coeff'] ?? null;
            $valid = is_string($code) && self::numeric($row['installmentCount']) && (float) $row['installmentCount'] === (float) $months && self::numeric($interest) && self::numeric($coefficient) && (float) $coefficient > 0.0;
            if (!$valid) $invalid = true;
            $classification = $valid ? (abs((float) $interest) <= 0.00001 ? 'zero_interest' : 'nonzero_interest') : 'unknown';
            $classes[$classification] = true;
            $rows[] = ['interest' => $valid ? (float) $interest : null, 'coefficient' => $valid ? (float) $coefficient : null, 'classification' => $classification];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp(ConsentContext::json($a), ConsentContext::json($b)));
        return ['classification' => !$invalid && count($classes) === 1 ? (string) array_key_first($classes) : 'unknown', 'rows' => $rows];
    }

    private static function numeric(mixed $value): bool
    {
        return (is_int($value) || is_float($value) || is_string($value)) && is_numeric($value) && is_finite((float) $value);
    }
}
