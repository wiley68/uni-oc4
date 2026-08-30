<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * CP bank-status update surface used after SmartUCF Process 1 outcomes.
 */
interface ControlPanelOrderStatusPort
{
    public function updateOrderStatus(string $shopOrderId, string $statusLabel, string $statusId): void;
}
