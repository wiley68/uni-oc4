<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * OpenCart-native status decision for newly materialized Product/Cart financing orders.
 *
 * Product/Cart orders move from default status 0 to a dedicated awaiting-financing status
 * via addHistory() so native checkout editOrder(status=0) semantics cannot interfere later.
 *
 * Checkout reuses native status-0 orders created by confirm.php and does not call addOrder().
 */
final class FinancingOrderStatusPolicy
{
    public const AWAITING_FINANCING_SETTING = ModuleConstants::AWAITING_FINANCING_ORDER_STATUS_SETTING;

    public function __construct(private int $awaitingFinancingStatusId)
    {
    }

    public function awaitingFinancingStatusId(): int
    {
        return $this->awaitingFinancingStatusId;
    }

    public function isCheckoutReuseAllowedStatus(int $orderStatusId): bool
    {
        return $orderStatusId === 0 || $orderStatusId === $this->awaitingFinancingStatusId;
    }

    public function shouldApplyAwaitingStatus(string $entryPoint): bool
    {
        return in_array($entryPoint, [OperationEntryPoint::PRODUCT, OperationEntryPoint::CART], true);
    }
}
