<?php

namespace Opencart\Admin\Model\Extension\MtUniCredit\Module;

use Opencart\System\Library\Extension\MtUniCredit\DeploymentHealthService;
use Opencart\System\Library\Extension\MtUniCredit\EventRegistry;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;

/**
 * Module model — settings defaults, event wiring, deployment health summary.
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

        $this->installPersistenceSchema();
        $this->syncEvents();
    }

    public function uninstall(): void
    {
        $this->removeEvents();
    }

    private function installPersistenceSchema(): void
    {
        if (!defined('DB_PREFIX')) {
            return;
        }

        $connection = new \Opencart\System\Library\Extension\MtUniCredit\OpenCartDbConnection($this->db, DB_PREFIX);
        (new \Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller($connection))->installAll();
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
     * Safe admin health summary — never includes secrets, PEM, or passphrases.
     *
     * @return array<string, mixed>
     */
    public function getHealthSummary(): array
    {
        $deployment = (new DeploymentHealthService())->evaluate();

        return [
            'extension_code'          => ModuleConstants::EXTENSION_CODE,
            'version'                 => ModuleConstants::VERSION,
            'events_registered'       => count(EventRegistry::eventCodes()),
            'module_enabled'          => (bool) $this->config->get(ModuleConstants::MODULE_SETTING_CODE . '_status'),
            'environment_status'      => $deployment['environment']['status'],
            'control_panel_status'    => $deployment['control_panel']['status'],
            'control_panel_configured' => $deployment['control_panel']['configured'],
            'control_panel_host'      => $deployment['control_panel']['host'],
            'secrets_status'          => $deployment['secrets']['status'],
            'certificate_status'      => $deployment['certificate']['status'],
            'private_key_status'      => $deployment['private_key']['status'],
            'certificate_validity'    => $deployment['certificate_validity']['status'],
            'certificate_not_after'   => $deployment['certificate_validity']['not_after'],
            'certificate_key_match'   => $deployment['certificate_key_match']['status'],
            'deployment_ready'        => $deployment['deployment_ready'],
        ];
    }
}
