<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Crash-recovery correlation written atomically into oc_order.tracking at addOrder time.
 *
 * Format: mtuc:s{storeId}:a{attemptId} — scoped, non-secret, queryable, max 64 chars.
 * Not used for affiliate/marketing on module-owned financing orders.
 */
final class OrderRecoveryMarker
{
    private const PREFIX = 'mtuc:';

    public static function forAttempt(int $storeId, int $attemptId): string
    {
        if ($storeId <= 0 || $attemptId <= 0) {
            throw new OrderMaterializationException('Recovery marker requires positive store and attempt identifiers.');
        }

        $marker = self::PREFIX . 's' . $storeId . ':a' . $attemptId;
        if (strlen($marker) > 64) {
            throw new OrderMaterializationException('Recovery marker exceeds OpenCart tracking field length.');
        }

        return $marker;
    }

    public static function isModuleMarker(string $tracking): bool
    {
        return str_starts_with($tracking, self::PREFIX);
    }

    public static function parseAttemptId(string $tracking): ?int
    {
        if (!preg_match('/^' . preg_quote(self::PREFIX, '/') . 's\d+:a(\d+)$/', $tracking, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
