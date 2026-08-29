<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Content-change-sensitive cache busting for module-owned JS/CSS assets.
 *
 * Module release version ({@see ModuleConstants::VERSION}) remains a business/release
 * identifier. Asset URLs use filesystem mtime of each file so Cloudflare/browser caches
 * pick up edits without a module version bump or manual purge.
 */
final class ModuleAssetVersion
{
    /**
     * Version string for a module-relative asset path
     * (e.g. catalog/view/javascript/mt_uni_credit_product.js).
     */
    public static function forRelativePath(string $relativeWithinModule): string
    {
        $absolute = self::absolutePath($relativeWithinModule);
        if ($absolute === '' || !is_file($absolute)) {
            return ModuleConstants::VERSION;
        }

        $mtime = @filemtime($absolute);
        if ($mtime === false || $mtime <= 0) {
            return ModuleConstants::VERSION;
        }

        return (string) $mtime;
    }

    /**
     * OpenCart document href including extension/ prefix and ?ver=.
     */
    public static function href(string $relativeWithinModule): string
    {
        $relative = ltrim(str_replace('\\', '/', $relativeWithinModule), '/');

        return 'extension/' . ModuleConstants::EXTENSION_CODE . '/' . $relative
            . '?ver=' . self::forRelativePath($relative);
    }

    public static function absolutePath(string $relativeWithinModule): string
    {
        $relative = ltrim(str_replace('\\', '/', $relativeWithinModule), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return '';
        }

        return self::moduleRoot() . '/' . $relative;
    }

    public static function moduleRoot(): string
    {
        if (defined('DIR_EXTENSION') && is_string(DIR_EXTENSION) && DIR_EXTENSION !== '') {
            return rtrim(DIR_EXTENSION, '/\\') . '/' . ModuleConstants::EXTENSION_CODE;
        }

        // system/library → module root (tests / IDE without OpenCart config).
        return dirname(__DIR__, 2);
    }
}
