<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * OpenCart 4.1.0.3 event callback arity contracts (from system/engine/loader.php).
 *
 * Catalog DB triggers use a `catalog/` prefix that startup/event strips before register().
 */
final class OpenCartEventCallbackContract
{
    public const CONTROLLER_BEFORE = 'controller_before';
    public const CONTROLLER_AFTER = 'controller_after';
    public const VIEW_BEFORE = 'view_before';
    public const VIEW_AFTER = 'view_after';

    /**
     * Argument count supplied by Event::trigger() for each family.
     *
     * @return array<string, int>
     */
    public static function arityByFamily(): array
    {
        return [
            self::CONTROLLER_BEFORE => 2,
            self::CONTROLLER_AFTER  => 3,
            self::VIEW_BEFORE       => 4,
            self::VIEW_AFTER        => 3,
        ];
    }

    /**
     * Classify a DB or runtime trigger (with or without catalog/ prefix).
     */
    public static function familyFromTrigger(string $trigger): ?string
    {
        $normalized = self::normalizeTrigger($trigger);

        if (preg_match('#^controller/.+/before$#', $normalized) === 1) {
            return self::CONTROLLER_BEFORE;
        }
        if (preg_match('#^controller/.+/after$#', $normalized) === 1) {
            return self::CONTROLLER_AFTER;
        }
        if (preg_match('#^view/.+/before$#', $normalized) === 1) {
            return self::VIEW_BEFORE;
        }
        if (preg_match('#^view/.+/after$#', $normalized) === 1) {
            return self::VIEW_AFTER;
        }

        return null;
    }

    public static function expectedArity(string $trigger): int
    {
        $family = self::familyFromTrigger($trigger);
        if ($family === null) {
            throw new \InvalidArgumentException('Unsupported OpenCart event trigger: ' . $trigger);
        }

        return self::arityByFamily()[$family];
    }

    /**
     * Strip catalog/ prefix the same way catalog/controller/startup/event.php does.
     */
    public static function normalizeTrigger(string $trigger): string
    {
        $trigger = trim($trigger, '/');
        if (str_starts_with($trigger, 'catalog/')) {
            return substr($trigger, strlen('catalog/'));
        }

        return $trigger;
    }

    /**
     * Dummy positional values matching Event::trigger() arity (not live references).
     *
     * @return list<mixed>
     */
    public static function sampleArgsForFamily(string $family): array
    {
        return match ($family) {
            self::CONTROLLER_BEFORE => ['product/product', []],
            self::CONTROLLER_AFTER  => ['product/product', [], ''],
            self::VIEW_BEFORE       => ['product/product', [], '', ''],
            self::VIEW_AFTER        => ['product/product', [], ''],
            default => throw new \InvalidArgumentException('Unknown event family: ' . $family),
        };
    }
}