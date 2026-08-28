<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Deterministic, scoped event definitions for this extension.
 *
 * Phase 1 registers no Product/Cart/Checkout events. Feature phases add entries here.
 *
 * @phpstan-type EventDefinition array{
 *     code: string,
 *     description: string,
 *     trigger: string,
 *     controller: string,
 *     method: string,
 *     status: bool,
 *     sort_order: int
 * }
 */
final class EventRegistry
{
    /**
     * @return list<EventDefinition>
     */
    public static function definitions(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public static function eventCodes(): array
    {
        $codes = [];
        foreach (self::definitions() as $definition) {
            $codes[] = $definition['code'];
        }

        return $codes;
    }

    /**
     * @return list<array{code: string, description: string, trigger: string, action: string, status: bool, sort_order: int}>
     */
    public static function openCartEventRows(?string $openCartVersion = null): array
    {
        $rows = [];
        foreach (self::definitions() as $definition) {
            $rows[] = [
                'code'        => $definition['code'],
                'description' => $definition['description'],
                'trigger'     => $definition['trigger'],
                'action'      => OpenCartCompatibility::eventAction(
                    $definition['controller'],
                    $definition['method'],
                    $openCartVersion
                ),
                'status'      => $definition['status'],
                'sort_order'  => $definition['sort_order'],
            ];
        }

        return $rows;
    }

    public static function isScopedEventCode(string $code): bool
    {
        return str_starts_with($code, ModuleConstants::MODULE_SETTING_CODE . '_');
    }
}
