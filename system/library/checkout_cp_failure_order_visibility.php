<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Ensures a Checkout local order remains Admin-visible when CP submission fails
 * before native payment success/addHistory.
 *
 * Uses store default {@see config_order_status_id} (typically Pending) — not a
 * UniCredit merchant setting and not the payment success status.
 */
final class CheckoutCpFailureOrderVisibility
{
    /**
     * @return bool true when addHistory was applied
     */
    public static function ensureVisible(
        CheckoutOrderModelPort $orders,
        int $orderId,
        int $fallbackStatusId
    ): bool {
        if ($orderId <= 0 || $fallbackStatusId <= 0) {
            return false;
        }

        $order = $orders->getOrder($orderId);
        if ($order === []) {
            return false;
        }

        $current = (int) ($order['order_status_id'] ?? 0);
        if ($current > 0) {
            return false;
        }

        $orders->addHistory($orderId, $fallbackStatusId, '', false);

        return true;
    }
}
