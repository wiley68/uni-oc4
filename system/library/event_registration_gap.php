<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Detects EventRegistry codes that are not present in an installed oc_event code list.
 *
 * Phase 8 Cart visibility failed when EventRegistry defined cart hooks but Admin Save
 * had not yet written them to oc_event — Product events alone were registered.
 */
final class EventRegistrationGap
{
    /**
     * @param list<string> $installedCodes
     * @return list<string>
     */
    public static function missingCodes(array $installedCodes): array
    {
        $installed = [];
        foreach ($installedCodes as $code) {
            $installed[(string) $code] = true;
        }

        $missing = [];
        foreach (EventRegistry::eventCodes() as $code) {
            if (!isset($installed[$code])) {
                $missing[] = $code;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    public static function requiredCartEventCodes(): array
    {
        return [
            ModuleConstants::MODULE_SETTING_CODE . '_before_cart_controller',
            ModuleConstants::MODULE_SETTING_CODE . '_after_cart_view',
        ];
    }
}
