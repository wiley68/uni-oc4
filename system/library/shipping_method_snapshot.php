<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Normalizes shipping method arrays to the native OpenCart 4.1 order.shipping_method JSON shape.
 *
 * Admin sale/order expects cost + tax_class_id on shipping_method (admin4/controller/sale/order.php).
 */
final class ShippingMethodSnapshot
{
    /**
     * @param array<string, mixed> $method Quote or persisted shipping_method array
     * @return array{name: string, code: string, cost: float|string|int, tax_class_id: int|string, text: string}
     */
    public static function normalize(array $method): array
    {
        $name = trim((string) ($method['name'] ?? $method['title'] ?? ''));
        $code = trim((string) ($method['code'] ?? ''));
        $cost = $method['cost'] ?? 0;
        if (is_string($cost) && is_numeric($cost)) {
            // keep numeric string parity with native quotes when present
        } elseif (!is_int($cost) && !is_float($cost) && !is_string($cost)) {
            $cost = 0;
        }
        $taxClassId = $method['tax_class_id'] ?? 0;
        if (!is_int($taxClassId) && !is_string($taxClassId)) {
            $taxClassId = (int) $taxClassId;
        }
        $text = trim((string) ($method['text'] ?? ''));
        if ($text === '' && (is_int($cost) || is_float($cost) || (is_string($cost) && is_numeric($cost)))) {
            $text = (string) $cost;
        }

        return [
            'name' => $name,
            'code' => $code,
            'cost' => $cost,
            'tax_class_id' => $taxClassId,
            'text' => $text,
        ];
    }

    /**
     * @return array{name: string, code: string, cost: float|string|int, tax_class_id: int|string, text: string}
     */
    public static function empty(): array
    {
        return [
            'name' => '',
            'code' => '',
            'cost' => 0,
            'tax_class_id' => 0,
            'text' => '',
        ];
    }

    /**
     * @param array<string, mixed> $quote Native checkout shipping quote row
     * @param array<string, mixed> $method Parent method bag (optional title/code fallback)
     * @return array{name: string, code: string, cost: float|string|int, tax_class_id: int|string, text: string}
     */
    public static function fromQuote(array $quote, array $method = []): array
    {
        return self::normalize([
            'name' => (string) ($quote['title'] ?? $method['title'] ?? 'Shipping'),
            'code' => (string) ($quote['code'] ?? $method['code'] ?? ''),
            'cost' => $quote['cost'] ?? 0,
            'tax_class_id' => $quote['tax_class_id'] ?? 0,
            'text' => (string) ($quote['text'] ?? ''),
        ]);
    }
}
