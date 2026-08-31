<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * OpenCart storefront route helpers (homepage = common/home or default empty route).
 */
final class StorefrontRouteResolver
{
    public static function currentRoute(?string $route): string
    {
        return trim((string) $route);
    }

    public static function isHomepageRoute(?string $route): bool
    {
        $route = self::currentRoute($route);

        return $route === '' || $route === 'common/home';
    }
}
