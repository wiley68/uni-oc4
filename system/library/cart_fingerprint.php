<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Canonical Cart fingerprint for operation/selection identity. */
final class CartFingerprint
{
    public static function hash(CartContext $cart, string $currencyCode): string
    {
        $lines = [];
        foreach ($cart->lines as $line) {
            $categories = array_values(array_unique(array_map('intval', $line->product->categoryIds)));
            sort($categories);
            $optionValueIds = self::optionValueIds($line);
            $lines[] = [
                'product_id'       => $line->product->productId,
                'categories'       => $categories,
                'option_value_ids' => $optionValueIds,
                'attribute'        => $line->productAttributeId,
                'quantity'         => $line->quantity,
                'line_total'       => self::normalizeAmount($line->lineTotal),
            ];
        }
        usort(
            $lines,
            static fn(array $a, array $b): int => [
                $a['product_id'],
                $a['option_value_ids'],
                $a['attribute'],
                $a['quantity'],
                $a['line_total'],
            ] <=> [
                $b['product_id'],
                $b['option_value_ids'],
                $b['attribute'],
                $b['quantity'],
                $b['line_total'],
            ]
        );

        return hash('sha256', json_encode([
            'entry_point' => OperationEntryPoint::CART,
            'currency'    => strtoupper($currencyCode),
            'total'       => self::normalizeAmount($cart->total),
            'lines'       => $lines,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<int> */
    private static function optionValueIds(CartLine $line): array
    {
        $fromLine = $line->optionValueIds();
        if ($fromLine !== []) {
            return $fromLine;
        }
        // Legacy CartLine without explicit options: keep attribute id as a single-value set.
        if ($line->productAttributeId > 0) {
            return [$line->productAttributeId];
        }

        return [];
    }

    private static function normalizeAmount(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
