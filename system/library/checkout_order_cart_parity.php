<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Canonical comparison of native checkout order lines vs live cart lines.
 *
 * Used to detect stale session.order_id after Voided confirm/editOrder cycles
 * when OpenCart will not call editOrder again (status != 0).
 */
final class CheckoutOrderCartParity
{
    /**
     * @param list<array<string, mixed>> $orderProducts
     * @param callable(int, int): list<array<string, mixed>> $getOptions fn(orderId, orderProductId)
     */
    public static function structuralKeyFromOrderProducts(int $orderId, array $orderProducts, callable $getOptions): string
    {
        $lines = [];
        foreach ($orderProducts as $product) {
            if (!is_array($product)) {
                continue;
            }
            $orderProductId = (int) ($product['order_product_id'] ?? 0);
            $optionIds = [];
            if ($orderProductId > 0) {
                foreach ($getOptions($orderId, $orderProductId) as $option) {
                    if (!is_array($option)) {
                        continue;
                    }
                    $pov = (int) ($option['product_option_value_id'] ?? 0);
                    if ($pov > 0) {
                        $optionIds[] = $pov;
                    }
                }
            }
            $optionIds = array_values(array_unique($optionIds));
            sort($optionIds);
            $lines[] = [
                'product_id'       => (int) ($product['product_id'] ?? 0),
                'option_value_ids' => $optionIds,
                'quantity'         => (int) ($product['quantity'] ?? 0),
            ];
        }

        return self::encodeLines($lines);
    }

    /**
     * @param list<array<string, mixed>> $cartProducts OpenCart cart->getProducts() rows
     */
    public static function structuralKeyFromCartProducts(array $cartProducts): string
    {
        $lines = [];
        foreach ($cartProducts as $product) {
            if (!is_array($product)) {
                continue;
            }
            $optionIds = [];
            foreach ($product['option'] ?? [] as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $pov = (int) ($option['product_option_value_id'] ?? 0);
                if ($pov > 0) {
                    $optionIds[] = $pov;
                }
            }
            $optionIds = array_values(array_unique($optionIds));
            sort($optionIds);
            $lines[] = [
                'product_id'       => (int) ($product['product_id'] ?? 0),
                'option_value_ids' => $optionIds,
                'quantity'         => (int) ($product['quantity'] ?? 0),
            ];
        }

        return self::encodeLines($lines);
    }

    /**
     * @param array<string, mixed>       $order
     * @param list<array<string, mixed>> $orderProducts
     * @param callable(int, int): list<array<string, mixed>> $getOptions
     * @param list<array<string, mixed>> $cartProducts
     * @param float                      $checkoutGrandTotal Live confirm-equivalent total
     *        (sub_total + shipping + tax + other totals). Never pass cart->getTotal() —
     *        that omits shipping and falsely blanks UniCredit payment render.
     */
    public static function matchesCurrentCart(
        array $order,
        array $orderProducts,
        callable $getOptions,
        array $cartProducts,
        float $checkoutGrandTotal,
        string $sessionCurrency
    ): bool {
        $orderId = (int) ($order['order_id'] ?? 0);
        if ($orderId <= 0 || $cartProducts === []) {
            return false;
        }

        $orderCurrency = strtoupper(trim((string) ($order['currency_code'] ?? '')));
        $cartCurrency = strtoupper(trim($sessionCurrency));
        if ($orderCurrency === '' || $cartCurrency === '' || $orderCurrency !== $cartCurrency) {
            return false;
        }

        $orderTotal = round((float) ($order['total'] ?? 0.0), 2);
        $checkoutGrandTotal = round($checkoutGrandTotal, 2);
        if (abs($orderTotal - $checkoutGrandTotal) > 0.001) {
            return false;
        }

        $orderKey = self::structuralKeyFromOrderProducts($orderId, $orderProducts, $getOptions);
        $cartKey = self::structuralKeyFromCartProducts($cartProducts);

        return hash_equals($orderKey, $cartKey);
    }

    /** @param list<array{product_id:int, option_value_ids:list<int>, quantity:int}> $lines */
    private static function encodeLines(array $lines): string
    {
        usort(
            $lines,
            static fn(array $a, array $b): int => [
                $a['product_id'],
                $a['option_value_ids'],
                $a['quantity'],
            ] <=> [
                $b['product_id'],
                $b['option_value_ids'],
                $b['quantity'],
            ]
        );

        return hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR));
    }
}
