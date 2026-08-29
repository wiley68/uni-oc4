<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\CheckoutOrderModelPort;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;

/** In-memory checkout order port for fast Phase 6 unit tests. */
final class InMemoryCheckoutOrderAdapter implements CheckoutOrderModelPort
{
    /** @var array<int, array<string, mixed>> */
    private array $orders = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $products = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $totals = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $options = [];

    /** @var list<array<string, mixed>> */
    private array $history = [];

    private int $nextOrderId = 100000;

    /** @var list<int> */
    private array $addOrderCalls = [];

    public function addOrderCallCount(): int
    {
        return count($this->addOrderCalls);
    }

    public function lastOrderId(): int
    {
        return $this->addOrderCalls === [] ? 0 : (int) end($this->addOrderCalls);
    }

    public function lastOrderStatusId(): int
    {
        $orderId = $this->lastOrderId();
        if ($orderId <= 0 || !isset($this->orders[$orderId])) {
            return 0;
        }

        return (int) ($this->orders[$orderId]['order_status_id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $orderData
     */
    public function addOrder(array $orderData): int
    {
        $orderId = $this->nextOrderId++;
        $this->addOrderCalls[] = $orderId;

        $paymentMethod = $orderData['payment_method'] ?? PaymentIdentity::paymentMethod();
        $this->orders[$orderId] = [
            'order_id'         => $orderId,
            'store_id'         => (int) ($orderData['store_id'] ?? 0),
            'customer_id'      => (int) ($orderData['customer_id'] ?? 0),
            'firstname'        => (string) ($orderData['firstname'] ?? ''),
            'lastname'         => (string) ($orderData['lastname'] ?? ''),
            'email'            => (string) ($orderData['email'] ?? ''),
            'telephone'        => (string) ($orderData['telephone'] ?? ''),
            'total'            => (float) ($orderData['total'] ?? 0.0),
            'currency_code'    => (string) ($orderData['currency_code'] ?? ''),
            'payment_method'   => $paymentMethod,
            'order_status_id'  => 0,
            'tracking'         => (string) ($orderData['tracking'] ?? ''),
            'comment'          => (string) ($orderData['comment'] ?? ''),
            'shipping_method'  => $orderData['shipping_method'] ?? ['name' => '', 'code' => ''],
            'language_id'      => (int) ($orderData['language_id'] ?? 1),
        ];

        $this->products[$orderId] = [];
        foreach ($orderData['products'] ?? [] as $index => $product) {
            $orderProductId = ($orderId * 1000) + $index + 1;
            $this->products[$orderId][] = [
                'order_product_id' => $orderProductId,
                'order_id'         => $orderId,
                'product_id'       => (int) ($product['product_id'] ?? 0),
                'quantity'         => (int) ($product['quantity'] ?? 0),
                'price'            => (float) ($product['price'] ?? 0.0),
                'total'            => (float) ($product['total'] ?? 0.0),
            ];
            $this->options[$orderProductId] = $product['option'] ?? [];
        }

        $this->totals[$orderId] = $orderData['totals'] ?? [];

        return $orderId;
    }

    public function seedExistingOrder(int $orderId, array $orderRow, array $products = [], array $totals = []): void
    {
        $this->orders[$orderId] = $orderRow + ['order_id' => $orderId];
        $this->products[$orderId] = $products;
        $this->totals[$orderId] = $totals;
        $this->nextOrderId = max($this->nextOrderId, $orderId + 1);
    }

    public function getOrder(int $orderId): array
    {
        return $this->orders[$orderId] ?? [];
    }

    public function getProducts(int $orderId): array
    {
        return $this->products[$orderId] ?? [];
    }

    public function getTotals(int $orderId): array
    {
        return $this->totals[$orderId] ?? [];
    }

    public function getProductOptions(int $orderId, int $orderProductId): array
    {
        return $this->options[$orderProductId] ?? [];
    }

    public function addHistory(int $orderId, int $orderStatusId, string $comment = '', bool $notify = false): void
    {
        if (isset($this->orders[$orderId])) {
            $this->orders[$orderId]['order_status_id'] = $orderStatusId;
        }
        $this->history[] = compact('orderId', 'orderStatusId', 'comment', 'notify');
    }
}
