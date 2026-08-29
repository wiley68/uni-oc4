<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Cart financing selection hash — fingerprint + scheme terms + actor. */
final class CartSelectionHash
{
    public static function hash(
        int $storeId,
        string $cartFingerprint,
        string $currencyCode,
        float $cartTotal,
        string $schemeKey,
        string $schemeType,
        string $kopCode,
        int $months,
        int $filterId,
        float $firstInstallment,
        string $actorBindingHash
    ): string {
        $canonical = [
            'entry_point'        => OperationEntryPoint::CART,
            'store_id'           => $storeId,
            'cart_fingerprint'   => $cartFingerprint,
            'currency'           => strtoupper($currencyCode),
            'cart_total'         => self::normalizeAmount($cartTotal),
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
