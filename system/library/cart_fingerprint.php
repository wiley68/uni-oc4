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
            $lines[] = [
                'product_id' => $line->product->productId,
                'categories' => $line->product->categoryIds,
                'attribute'  => $line->productAttributeId,
                'quantity'   => $line->quantity,
                'line_total' => $line->lineTotal,
            ];
        }
        usort($lines, static fn(array $a, array $b): int => [$a['product_id'], $a['attribute'], $a['quantity']]
            <=> [$b['product_id'], $b['attribute'], $b['quantity']]);

        return hash('sha256', json_encode([
            'entry_point' => OperationEntryPoint::CART,
            'currency'    => strtoupper($currencyCode),
            'total'       => $cart->total,
            'lines'       => $lines,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
