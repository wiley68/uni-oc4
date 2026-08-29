<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * OpenCart-native status decision for newly materialized Product/Cart financing orders.
 *
 * Product/Cart orders move from default status 0 to a visible interim status via addHistory()
 * so Admin Sales → Orders (WHERE order_status_id > 0) includes them.
 *
 * Interim status must NOT mean bank/CP success. Prefer store Pending
 * ({@see config_order_status_id}), never payment Processing.
 *
 * Checkout reuses the native session.order_id row. OpenCart 4.1.0.3 `editOrder()` always
 * voids first via config_void_status_id, so active checkout orders commonly sit at void
 * until payment confirmation — that void status must remain financing-reuse-eligible.
 */
final class FinancingOrderStatusPolicy
{
    public const AWAITING_FINANCING_SETTING = ModuleConstants::AWAITING_FINANCING_ORDER_STATUS_SETTING;

    public function __construct(
        private int $awaitingFinancingStatusId,
        private int $voidStatusId = 0
    ) {
    }

    /**
     * Resolve Product/Cart post-materialization status (must be > 0 for Admin visibility).
     *
     * 1. Dedicated module setting when configured
     * 2. Else store default Pending: config_order_status_id
     *
     * Do not fall back to payment_*_order_status_id (usually Processing).
     */
    public static function resolveConfiguredAwaitingStatusId(
        int $moduleAwaitingStatusId,
        int $configOrderStatusId = 0
    ): int {
        if ($moduleAwaitingStatusId > 0) {
            return $moduleAwaitingStatusId;
        }

        return $configOrderStatusId > 0 ? $configOrderStatusId : 0;
    }

    public function awaitingFinancingStatusId(): int
    {
        return $this->awaitingFinancingStatusId;
    }

    public function voidStatusId(): int
    {
        return $this->voidStatusId;
    }

    public function isCheckoutReuseAllowedStatus(int $orderStatusId): bool
    {
        if ($orderStatusId === 0) {
            return true;
        }
        if ($this->awaitingFinancingStatusId > 0 && $orderStatusId === $this->awaitingFinancingStatusId) {
            return true;
        }
        if ($this->voidStatusId > 0 && $orderStatusId === $this->voidStatusId) {
            return true;
        }

        return false;
    }

    public function shouldApplyAwaitingStatus(string $entryPoint): bool
    {
        return in_array($entryPoint, [OperationEntryPoint::PRODUCT, OperationEntryPoint::CART], true);
    }
}
