<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Domain-separated actor binding for cart financing attempts. */
final class CartActorBinding
{
    public static function hash(int $storeId, int $customerId, string $sessionFingerprint): string
    {
        $canonical = [
            'domain'      => 'mt_uni_credit_cart_actor',
            'store_id'    => $storeId,
            'customer_id' => max(0, $customerId),
            'session'     => $sessionFingerprint,
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function sessionFingerprint(string $sessionId): string
    {
        return hash('sha256', 'mt_uni_credit_session|' . $sessionId);
    }
}
