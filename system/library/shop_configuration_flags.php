<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Shop configuration boolean flags from the CP snapshot.
 */
final class ShopConfigurationFlags
{
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
}
