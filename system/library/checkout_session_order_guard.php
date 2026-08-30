<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Decides when session.order_id must be cleared so native confirm can addOrder() again.
 *
 * OpenCart 4.1.0.3 only editOrder()s when order_status_id == 0. After editOrder voids the
 * row, later cart mutations leave session.order_id pointing at a Voided stale order that
 * confirm will neither update nor replace — payment modules then show order.total.
 */
final class CheckoutSessionOrderGuard
{
    /**
     * Cart mutations always invalidate the checkout draft order pointer.
     * Native cart controllers clear payment_method but not order_id.
     */
    public static function invalidateOnCartMutation(array &$sessionData): bool
    {
        if (!isset($sessionData['order_id'])) {
            return false;
        }
        unset($sessionData['order_id']);

        return true;
    }

    /**
     * @param array<string, mixed>|null $order
     * @param list<array<string, mixed>> $orderProducts
     * @param callable(int, int): list<array<string, mixed>> $getOptions
     * @param list<array<string, mixed>> $cartProducts
     * @param float $checkoutGrandTotal Confirm-equivalent live total (see CheckoutLiveGrandTotal)
     */
    public static function shouldInvalidateForCurrentCart(
        ?array $order,
        array $orderProducts,
        callable $getOptions,
        array $cartProducts,
        float $checkoutGrandTotal,
        string $sessionCurrency
    ): bool {
        if ($order === null || $order === []) {
            return true;
        }
        if ($cartProducts === [] || $checkoutGrandTotal <= 0.0) {
            return true;
        }

        return !CheckoutOrderCartParity::matchesCurrentCart(
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            $checkoutGrandTotal,
            $sessionCurrency
        );
    }

    /**
     * Clear session.order_id when the stored order no longer represents the live cart.
     *
     * @param array<string, mixed> $sessionData
     * @param array<string, mixed>|null $order
     * @param list<array<string, mixed>> $orderProducts
     * @param callable(int, int): list<array<string, mixed>> $getOptions
     * @param list<array<string, mixed>> $cartProducts
     * @param float $checkoutGrandTotal Confirm-equivalent live total (see CheckoutLiveGrandTotal)
     */
    public static function reconcileSessionOrder(
        array &$sessionData,
        ?array $order,
        array $orderProducts,
        callable $getOptions,
        array $cartProducts,
        float $checkoutGrandTotal,
        string $sessionCurrency
    ): bool {
        if (!isset($sessionData['order_id'])) {
            return false;
        }

        if (!self::shouldInvalidateForCurrentCart(
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            $checkoutGrandTotal,
            $sessionCurrency
        )) {
            return false;
        }

        unset($sessionData['order_id']);

        return true;
    }
}
