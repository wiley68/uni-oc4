<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * OpenCart-native status decision for newly materialized Product/Cart financing orders.
 *
 * Product/Cart orders move from default status 0 to a dedicated awaiting-financing status
 * via addHistory() so native checkout editOrder(status=0) semantics cannot interfere later.
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
        // OC4.1 confirm.php → editOrder() voids the same order_id before rewriting fields.
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
