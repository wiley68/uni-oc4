<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\OrderCorrelationStoreInterface;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceConflictException;

/** In-memory order correlation store for unit tests without MySQL. */
final class InMemoryOrderCorrelationRepository implements OrderCorrelationStoreInterface
{
    /** @var array<int, array{store_id:int,attempt_id:int,order_id:int}> keyed by attempt_id */
    private array $byAttempt = [];

    /** @var array<string, int> keyed by store_id:order_id */
    private array $byOrder = [];

    public function linkCreatedOrder(int $storeId, int $attemptId, int $orderId): void
    {
        if (isset($this->byAttempt[$attemptId])) {
            if ($this->byAttempt[$attemptId]['order_id'] === $orderId) {
                return;
            }
            throw new PersistenceConflictException('The order is already correlated to another financing attempt.');
        }

        $orderKey = $storeId . ':' . $orderId;
        if (isset($this->byOrder[$orderKey])) {
            throw new PersistenceConflictException('The order is already correlated to another financing attempt.');
        }

        $this->byAttempt[$attemptId] = [
            'store_id'   => $storeId,
            'attempt_id' => $attemptId,
            'order_id'   => $orderId,
        ];
        $this->byOrder[$orderKey] = $attemptId;
    }

    public function findOrderIdByAttempt(int $storeId, int $attemptId): ?int
    {
        $row = $this->byAttempt[$attemptId] ?? null;
        if ($row === null || $row['store_id'] !== $storeId) {
            return null;
        }

        return $row['order_id'];
    }

    public function findAttemptIdByOrder(int $storeId, int $orderId): ?int
    {
        return $this->byOrder[$storeId . ':' . $orderId] ?? null;
    }
}
