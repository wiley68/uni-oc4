<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Verified local OpenCart order produced by a gateway/materializer. */
final class CreatedOpenCartOrder
{
    public int $orderId;

    public int $storeId;

    public float $total;

    public string $currencyCode;

    /** @var list<array<string, mixed>> */
    public array $products;

    /** @var list<array<string, mixed>> */
    public array $totals;

    public string $paymentMethodCode;

    public int $orderStatusId;

    public bool $recovered;

    /**
     * @param list<array<string, mixed>> $products
     * @param list<array<string, mixed>> $totals
     */
    public function __construct(
        int $orderId,
        int $storeId,
        float $total,
        string $currencyCode,
        array $products,
        array $totals,
        string $paymentMethodCode,
        int $orderStatusId,
        bool $recovered = false
    ) {
        $this->orderId = $orderId;
        $this->storeId = $storeId;
        $this->total = $total;
        $this->currencyCode = $currencyCode;
        $this->products = $products;
        $this->totals = $totals;
        $this->paymentMethodCode = $paymentMethodCode;
        $this->orderStatusId = $orderStatusId;
        $this->recovered = $recovered;
    }
}
