<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Controlled operation lock entry points.
 */
final class OperationEntryPoint
{
    public const PRODUCT = 'product';
    public const CART = 'cart';
    public const CHECKOUT = 'checkout';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PRODUCT, self::CART, self::CHECKOUT];
    }

    public static function isValid(string $entryPoint): bool
    {
        return in_array($entryPoint, self::all(), true);
    }
}
