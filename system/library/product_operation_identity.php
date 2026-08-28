<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Product attempt operation identity — stable product selection without financing terms. */
final class ProductOperationIdentity
{
    /**
     * @param array<int, int|string|list<int>> $normalizedOptions
     */
    public static function hash(
        int $storeId,
        int $productId,
        array $normalizedOptions,
        int $quantity,
        string $currencyCode
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
            'entry_point' => OperationEntryPoint::PRODUCT,
            'store_id'    => $storeId,
            'product_id'  => $productId,
            'options'     => $options,
            'quantity'    => $quantity,
            'currency'    => strtoupper($currencyCode),
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
