<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Safe storefront/product visibility diagnostics — never logs secrets, tokens, or PII.
 */
final class ProductVisibilityDebugLog
{
    public static function write(object $log, bool $debugEnabled, string $message): void
    {
        if (!$debugEnabled || $message === '') {
            return;
        }
        if (!method_exists($log, 'write')) {
            return;
        }

        $log->write('mt_uni_credit.product_visibility: ' . $message);
    }
}
