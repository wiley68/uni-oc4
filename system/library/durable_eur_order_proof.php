<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Checked native OpenCart order and its historical transaction-currency factor. */
final class DurableEurOrderProof
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $storeId,
        public readonly int $currencyId,
        public readonly float $currencyValue,
        public readonly float $baseTotal,
        public readonly float $eurTotal
    ) {
    }
}
