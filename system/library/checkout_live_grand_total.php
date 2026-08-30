<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Checkout order.total vs live cart amount for session.order_id parity.
 *
 * OpenCart {@see \Opencart\System\Library\Cart\Cart::getTotal()} is merchandise +
 * product tax only. Native confirm writes order.total via checkout/cart getTotals()
 * (sub_total + shipping + tax + other total extensions). Comparing cart->getTotal()
 * to order.total falsely invalidates every paid-shipping checkout and blanks the
 * UniCredit payment panel (Logged often uses flat shipping; Guest tests may use free).
 */
final class CheckoutLiveGrandTotal
{
    /**
     * @param callable(array<int, array<string, mixed>>, array<int, float>, float|int): void $getTotals
     *        Signature matches OpenCart checkout/cart::getTotals (by-ref totals/taxes/total).
     * @param array<int, float> $taxes
     */
    public static function compute(callable $getTotals, array $taxes): float
    {
        $totals = [];
        $total = 0.0;
        $getTotals($totals, $taxes, $total);

        return round((float) $total, 2);
    }
}
