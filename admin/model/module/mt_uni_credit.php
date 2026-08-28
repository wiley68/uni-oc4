<?php

namespace Opencart\Admin\Model\Extension\MtUniCredit\Module;

use Opencart\System\Library\Extension\MtUniCredit\EventRegistry;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;

/**
 * Phase 1 module model — settings defaults and event wiring only.
 */
class MtUniCredit extends \Opencart\System\Engine\Model
{
    /**
     * @return array<string, int|string>
     */
    public function getDefaultSettings(): array
    {
        return [
            ModuleConstants::MODULE_SETTING_CODE . '_status' => 0,
        ];
    }

    public function install(): void
    {
        $this->load->model('setting/setting');

        $code = ModuleConstants::MODULE_SETTING_CODE;
        $existing = $this->model_setting_setting->getSetting($code);

        if ($existing === []) {
            $this->model_setting_setting->editSetting($code, $this->getDefaultSettings());
        }

        $this->syncEvents();
    }

    public function uninstall(): void
    {
        $this->removeEvents();
    }

    public function syncEvents(): void
    {
        $this->load->model('setting/event');

        foreach (EventRegistry::eventCodes() as $code) {
            $this->model_setting_event->deleteEventByCode($code);
        }

        foreach (EventRegistry::openCartEventRows(defined('VERSION') ? VERSION : null) as $event) {
            $this->model_setting_event->deleteEventByCode($event['code']);
            $this->model_setting_event->addEvent($event);
        }
    }

    public function removeEvents(): void
    {
        $this->load->model('setting/event');

        foreach (EventRegistry::eventCodes() as $code) {
            $this->model_setting_event->deleteEventByCode($code);
        }
    }

    /**
     * @return array{extension_code: string, version: string, events_registered: int, module_enabled: bool}
     */
    public function getHealthSummary(): array
    {
        return [
            'extension_code'    => ModuleConstants::EXTENSION_CODE,
            'version'           => ModuleConstants::VERSION,
            'events_registered' => count(EventRegistry::eventCodes()),
            'module_enabled'    => (bool) $this->config->get(ModuleConstants::MODULE_SETTING_CODE . '_status'),
        ];
    }
}
