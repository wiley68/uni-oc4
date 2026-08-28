<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * OpenCart 4.x minor-version compatibility (Phase 1).
 *
 * Event actions stored in oc_event must use the separator understood by Action on the
 * running version. HTTP/admin routes may still use either form; the loader normalizes | to .
 * for controller dispatch (see docs/CONTRACTS.md).
 */
final class OpenCartCompatibility
{
    public const EVENT_SEPARATOR_DOT_VERSION = '4.0.2';

    public static function eventMethodSeparator(?string $openCartVersion = null): string
    {
        $version = $openCartVersion ?? (defined('VERSION') ? (string) VERSION : '4.1.0.3');

        return version_compare($version, self::EVENT_SEPARATOR_DOT_VERSION, '>=') ? '.' : '|';
    }

    public static function eventAction(string $controllerRoute, string $method, ?string $openCartVersion = null): string
    {
        $route = trim($controllerRoute, " \t\n\r\0\x0B./|");
        $methodName = trim($method, " \t\n\r\0\x0B./|");

        return $route . self::eventMethodSeparator($openCartVersion) . $methodName;
    }

    /**
     * Admin/catalog URL route helper. OpenCart 4.1 accepts dotted method suffixes in links.
     */
    public static function adminRoute(string $controllerRoute, string $method): string
    {
        $route = trim($controllerRoute, " \t\n\r\0\x0B./|");
        $methodName = trim($method, " \t\n\r\0\x0B./|");

        return $route . '.' . $methodName;
    }
}
