<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

interface OrderCorrelationStoreInterface
{
    public function linkCreatedOrder(int $storeId, int $attemptId, int $orderId): void;

    public function findOrderIdByAttempt(int $storeId, int $attemptId): ?int;
}
