<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Shop configuration boolean flags from the CP snapshot.
 */
final class ShopConfigurationFlags
{
    /** @param array<string, mixed> $shop */
    public static function isTestEnvironment(array $shop): bool
    {
        return ((int) ($shop['uni_env'] ?? 1)) === 0;
    }

    /**
     * Secondary checkout process when uni_proces === 1
     * (inverted relative to the human process number).
     *
     * @param array<string, mixed> $shop
     */
    public static function isSecondaryProcess(array $shop): bool
    {
        return ((int) ($shop['uni_proces'] ?? 0)) === 1;
    }

    /** @param mixed $value */
    public static function isYesFlag($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'on', 'true'], true);
    }

    /** @param array<string, mixed> $shop */
    public static function usesSmartUcfCertificate(array $shop): bool
    {
        return self::isYesFlag($shop['uni_sertificat'] ?? 0);
    }
}
