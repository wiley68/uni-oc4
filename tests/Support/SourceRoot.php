<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

final class SourceRoot
{
    public static function openCart(): ?string
    {
        $root = getenv('OPENCART_ROOT') ?: '';
        if ($root !== '' && is_file($root . '/index.php')) {
            return $root;
        }

        return null;
    }

    public static function jet(): ?string
    {
        $root = getenv('JET_OC4_ROOT') ?: '';
        if ($root !== '' && is_file($root . '/install.json')) {
            return $root;
        }

        return null;
    }
}
