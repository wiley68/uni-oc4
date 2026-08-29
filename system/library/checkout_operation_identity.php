<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Checkout attempt operation identity — store + native session order. */
final class CheckoutOperationIdentity
{
    public static function hash(int $storeId, int $orderId): string
    {
        $canonical = [
            'entry_point' => OperationEntryPoint::CHECKOUT,
            'store_id'    => $storeId,
            'order_id'    => $orderId,
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
