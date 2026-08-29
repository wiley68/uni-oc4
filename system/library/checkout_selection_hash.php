<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Checkout financing selection hash — order identity + scheme terms + actor. */
final class CheckoutSelectionHash
{
    public static function hash(
        int $storeId,
        int $orderId,
        string $cartFingerprint,
        string $currencyCode,
        float $orderTotal,
        string $schemeKey,
        string $schemeType,
        string $kopCode,
        int $months,
        int $filterId,
        float $firstInstallment,
        string $actorBindingHash
    ): string {
        $canonical = [
            'entry_point'        => OperationEntryPoint::CHECKOUT,
            'store_id'           => $storeId,
            'order_id'           => $orderId,
            'cart_fingerprint'   => $cartFingerprint,
            'currency'           => strtoupper($currencyCode),
            'order_total'        => self::normalizeAmount($orderTotal),
            'scheme_key'         => $schemeKey,
            'scheme_type'        => $schemeType,
            'kop_code'           => $kopCode,
            'months'             => $months,
            'filter_id'          => $filterId,
            'first_installment'  => self::normalizeAmount($firstInstallment),
            'actor_binding_hash' => $actorBindingHash,
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function normalizeAmount(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
