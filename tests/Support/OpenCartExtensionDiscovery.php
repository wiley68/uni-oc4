<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

/**
 * Mirrors OpenCart 4.1 admin extension discovery globs (admin4/controller/extension/*).
 */
final class OpenCartExtensionDiscovery
{
    /** @var list<string> */
    public const ADMIN_CONTROLLER_SCAN_TYPES = [
        'module',
        'payment',
        'dashboard',
        'currency',
        'marketplace',
        'fraud',
        'captcha',
        'total',
        'analytics',
        'feed',
        'theme',
        'report',
        'language',
        'other',
        'shipping',
    ];

    /**
     * @return list<string> Basenames of discovered component codes (e.g. mt_uni_credit, index).
     */
    public static function discoveredModuleCodes(string $extensionRoot): array
    {
        return self::discoveredCodesForType($extensionRoot, 'module');
    }

    /**
     * @return list<string>
     */
    public static function discoveredCodesForType(string $extensionRoot, string $type): array
    {
        $pattern = rtrim($extensionRoot, '/') . '/admin/controller/' . $type . '/*.php';
        $files = glob($pattern) ?: [];
        $codes = [];
        foreach ($files as $file) {
            $codes[] = basename($file, '.php');
        }
        sort($codes);

        return $codes;
    }

    /**
     * @return list<string> Relative paths like admin/controller/module/index.php
     */
    public static function genericIndexPhpInScannedControllerDirs(string $extensionRoot): array
    {
        $found = [];
        $base = rtrim($extensionRoot, '/');
        foreach (self::ADMIN_CONTROLLER_SCAN_TYPES as $type) {
            $path = $base . '/admin/controller/' . $type . '/index.php';
            if (is_file($path)) {
                $found[] = 'admin/controller/' . $type . '/index.php';
            }
        }

        return $found;
    }
}
