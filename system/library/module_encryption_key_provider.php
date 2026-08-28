<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Stable installation-scoped key material for encrypting module settings at rest.
 *
 * Mirrors the role of PrestaShop {@see _NEW_COOKIE_KEY_} in uni-ps9: auto-derived from the
 * deployed OpenCart installation, not an operator-managed deployment secret file.
 */
final class ModuleEncryptionKeyProvider
{
    private const DERIVATION_SUFFIX = 'mt_uni_credit_module_encryption_v1';

    public function resolveKeyMaterial(): string
    {
        if (defined('DB_PREFIX') && defined('DB_DATABASE') && defined('DIR_STORAGE')) {
            return \DB_PREFIX . '|' . \DB_DATABASE . '|' . \DIR_STORAGE . '|' . self::DERIVATION_SUFFIX;
        }

        return self::testKeyMaterial();
    }

    public static function testKeyMaterial(): string
    {
        return 'phase4-test-installation|' . self::DERIVATION_SUFFIX;
    }
}
