<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Port isolating native OpenCart checkout/order model operations.
 */
interface CheckoutOrderModelPort
{
    /**
     * @param array<string, mixed> $orderData
     */
    public function addOrder(array $orderData): int;

    /** @return array<string, mixed> */
    public function getOrder(int $orderId): array;

    /** @return list<array<string, mixed>> */
    public function getProducts(int $orderId): array;

    /** @return list<array<string, mixed>> */
    public function getTotals(int $orderId): array;

    /** @return list<array<string, mixed>> */
    public function getProductOptions(int $orderId, int $orderProductId): array;

    public function addHistory(int $orderId, int $orderStatusId, string $comment = '', bool $notify = false): void;
}
