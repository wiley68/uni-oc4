<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Cart attempt operation identity — stable cart fingerprint without financing terms. */
final class CartOperationIdentity
{
    public static function hash(int $storeId, string $currencyCode, string $cartFingerprint): string
    {
        $canonical = [
            'entry_point'      => OperationEntryPoint::CART,
            'store_id'         => $storeId,
            'currency'         => strtoupper($currencyCode),
            'cart_fingerprint' => $cartFingerprint,
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
