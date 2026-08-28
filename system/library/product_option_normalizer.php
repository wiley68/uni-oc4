<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class ProductOptionNormalizer
{
    /**
     * @param array<string|int, mixed> $raw
     * @return array<int, int|string|list<int>>
     */
    public static function normalize(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $key => $value) {
            $optionId = (int) $key;
            if ($optionId <= 0) {
                continue;
            }
            if (is_array($value)) {
                $values = array_values(array_unique(array_filter(array_map('intval', $value), static fn(int $v): bool => $v > 0)));
                if ($values !== []) {
                    $normalized[$optionId] = $values;
                }
                continue;
            }
            if (is_string($value) && str_contains($value, ',')) {
                $values = array_values(array_unique(array_filter(array_map('intval', explode(',', $value)), static fn(int $v): bool => $v > 0)));
                if ($values !== []) {
                    $normalized[$optionId] = count($values) === 1 ? $values[0] : $values;
                }
                continue;
            }
            $intValue = (int) $value;
            if ($intValue > 0) {
                $normalized[$optionId] = $intValue;
            } elseif (is_string($value) && trim($value) !== '') {
                $normalized[$optionId] = trim($value);
            }
        }
        ksort($normalized);

        return $normalized;
    }
}
