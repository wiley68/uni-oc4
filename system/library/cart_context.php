<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class CartContext
{
    /** @var CartLine[] */
    public array $lines;

    public float $total;

    /** @var array<string, mixed> */
    public array $checkoutState;

    /** @param CartLine[] $lines @param array<string, mixed> $checkoutState */
    public function __construct(array $lines, float $total, array $checkoutState = [])
    {
        $this->lines = array_values($lines);
        $this->total = round($total, 2);
        $this->checkoutState = $checkoutState;
    }
}
