<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Deterministic product selection hash — authoritative financing inputs only.
 */
final class ProductSelectionHash
{
    public const FLOW = 'product';

    /**
     * @param array<int, int|string|list<int>> $normalizedOptions product_option_id => value(s)
     */
    public static function hash(
        int $storeId,
        int $productId,
        array $normalizedOptions,
        int $quantity,
        string $currencyCode,
        float $financingAmount,
        string $schemeKey,
        string $schemeType,
        string $kopCode,
        int $months,
        int $filterId,
        float $firstInstallment,
        string $actorBindingHash
    ): string {
        ksort($normalizedOptions);
        $options = [];
        foreach ($normalizedOptions as $optionId => $value) {
            if (is_array($value)) {
                $values = array_map('intval', $value);
                sort($values);
                $options[(string) (int) $optionId] = $values;
            } else {
                $options[(string) (int) $optionId] = (int) $value;
            }
        }

        $canonical = [
            'flow'               => self::FLOW,
            'store_id'           => $storeId,
            'product_id'         => $productId,
            'options'            => $options,
            'quantity'           => $quantity,
            'currency'           => strtoupper($currencyCode),
            'financing_amount'   => self::normalizeAmount($financingAmount),
            'scheme_key'         => $schemeKey,
            'scheme_type'        => $schemeType,
            'kop_code'           => $kopCode,
            'months'             => $months,
            'filter_id'          => $filterId,
            'first_installment'  => self::normalizeAmount($firstInstallment),
            'actor_binding_hash' => $actorBindingHash,
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function normalizeAmount(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
