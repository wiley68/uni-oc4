<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * OpenCart-native status decision for newly materialized Product/Cart financing orders.
 *
 * Product/Cart orders move from default status 0 to the UniCredit payment method's
 * configured order status ({@see ModuleConstants::PAYMENT_ORDER_STATUS_SETTING}) via
 * addHistory() so Admin Sales → Orders (WHERE order_status_id > 0) includes them.
 *
 * Checkout reuses the native session.order_id row and does not apply Product/Cart status here.
 * OpenCart 4.1.0.3 `editOrder()` always voids first via config_void_status_id, so active
 * checkout orders commonly sit at void until payment confirmation — that void status must
 * remain financing-reuse-eligible (along with status 0).
 *
 * After CP failure before native success, Checkout may apply store
 * {@see config_order_status_id} (neutral Pending) so Admin does not hide the order.
 * That failure-visible status must also remain Checkout reuse-eligible for same-cart CP retry.
 * After definite SmartUCF reject (CP already created), Checkout confirm applies
 * {@see ModuleConstants::PAYMENT_ORDER_STATUS_SETTING} via addHistory so the order
 * leaves Voided/0 — bank failure must not void the commerce order.
 * The payment-method status is NOT treated as a Checkout reuse key (avoids expanding reuse
 * to Processing mid-lifecycle).
 */
final class FinancingOrderStatusPolicy
{
    public function __construct(
        private int $productCartOrderStatusId,
        private int $voidStatusId = 0,
        private int $checkoutFailureVisibleStatusId = 0
    ) {}

    /**
     * Resolve Product/Cart post-materialization status from the payment method setting.
     *
     * @param int $paymentOrderStatusId value of payment_mt_uni_credit_order_status_id
     */
    public static function resolveProductCartOrderStatusId(int $paymentOrderStatusId): int
    {
        return $paymentOrderStatusId > 0 ? $paymentOrderStatusId : 0;
    }

    public function productCartOrderStatusId(): int
    {
        return $this->productCartOrderStatusId;
    }

    /** @deprecated Use productCartOrderStatusId(); kept for call-site clarity during transition. */
    public function awaitingFinancingStatusId(): int
    {
        return $this->productCartOrderStatusId;
    }

    public function voidStatusId(): int
    {
        return $this->voidStatusId;
    }

    public function checkoutFailureVisibleStatusId(): int
    {
        return $this->checkoutFailureVisibleStatusId;
    }

    public function isCheckoutReuseAllowedStatus(int $orderStatusId): bool
    {
        if ($orderStatusId === 0) {
            return true;
        }
        // Only an explicitly configured Product/Cart status that matches — typically unused
        // for Checkout when productCartOrderStatusId is wired as 0.
        if ($this->productCartOrderStatusId > 0 && $orderStatusId === $this->productCartOrderStatusId) {
            return true;
        }
        if ($this->voidStatusId > 0 && $orderStatusId === $this->voidStatusId) {
            return true;
        }
        // Neutral CP-failure visibility fallback (config_order_status_id) — same-cart retry.
        if ($this->checkoutFailureVisibleStatusId > 0 && $orderStatusId === $this->checkoutFailureVisibleStatusId) {
            return true;
        }

        return false;
    }

    public function shouldApplyProductCartStatus(string $entryPoint): bool
    {
        return in_array($entryPoint, [OperationEntryPoint::PRODUCT, OperationEntryPoint::CART], true);
    }

    /** @deprecated Use shouldApplyProductCartStatus() */
    public function shouldApplyAwaitingStatus(string $entryPoint): bool
    {
        return $this->shouldApplyProductCartStatus($entryPoint);
    }
}
