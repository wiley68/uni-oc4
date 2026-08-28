<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Resolves the extension package root (mt_uni_credit/) deterministically.
 */
final class ExtensionRoot
{
    public static function path(): string
    {
        return dirname(__DIR__, 2);
    }
}
