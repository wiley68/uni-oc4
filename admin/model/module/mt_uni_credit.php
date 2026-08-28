<?php

namespace Opencart\Admin\Model\Extension\MtUniCredit\Module;

use Opencart\System\Library\Extension\MtUniCredit\CpAdminHealthPresenter;
use Opencart\System\Library\Extension\MtUniCredit\CpAuthenticationException;
use Opencart\System\Library\Extension\MtUniCredit\CpException;
use Opencart\System\Library\Extension\MtUniCredit\CpServiceFactory;
use Opencart\System\Library\Extension\MtUniCredit\DeploymentHealthService;
use Opencart\System\Library\Extension\MtUniCredit\EventRegistry;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartDbConnection;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartModuleSettingStore;
use Opencart\System\Library\Extension\MtUniCredit\ShopSnapshotValidationException;

/**
 * Module model — settings, deployment health, CP auth/cache (Phase 4).
 */
class MtUniCredit extends \Opencart\System\Engine\Model
{
    /**
     * @return array<string, int|string>
     */
    public function getDefaultSettings(): array
    {
        return [
            ModuleConstants::MODULE_SETTING_CODE . '_status'  => 0,
            ModuleCredentialsRepository::UNICID_SETTING       => '',
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

        $connection = new OpenCartDbConnection($this->db, DB_PREFIX);
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
     * Safe admin health summary — never includes secrets, PEM, bearer tokens, or passphrases.
     *
     * @return array<string, mixed>
     */
    public function getHealthSummary(): array
    {
        $deployment = (new DeploymentHealthService())->evaluate();
        $cp = $this->getCpHealthSummary();

        return array_merge([
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
        ], $cp);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCpHealthSummary(): array
    {
        if (!defined('DB_PREFIX')) {
            return $this->emptyCpHealth();
        }

        try {
            return $this->createCpServices()['presenter']->present();
        } catch (\Throwable $exception) {
            return $this->emptyCpHealth();
        }
    }

    /**
     * @return array{
     *     credentials: ModuleCredentialsRepository,
     *     tokens: \Opencart\System\Library\Extension\MtUniCredit\CpTokenRepository,
     *     client: \Opencart\System\Library\Extension\MtUniCredit\ControlPanelClient,
     *     shopConfiguration: \Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationService,
     *     presenter: CpAdminHealthPresenter,
     *     credentialChange: \Opencart\System\Library\Extension\MtUniCredit\CredentialChangeHandler
     * }
     */
    public function createCpServices(): array
    {
        if (!defined('DB_PREFIX')) {
            throw new \RuntimeException('Database prefix is not defined.');
        }

        $storeId = (int) ($this->config->get('config_store_id') ?? 0);
        $connection = new OpenCartDbConnection($this->db, DB_PREFIX);
        $settings = new OpenCartModuleSettingStore($connection);

        return CpServiceFactory::create(
            $connection,
            $settings,
            $storeId,
            (string) ($this->config->get('config_ssl') ?? ''),
            (string) ($this->config->get('config_url') ?? '')
        );
    }

    /**
     * Operator-facing bank data refresh — credentials validated, CP auth transparent.
     *
     * @return array{
     *     success?: string,
     *     error?: string,
     *     fetched_at?: string|null,
     *     scheme_count?: int,
     *     cache_fresh?: bool
     * }
     */
    public function refreshBankData(): array
    {
        $storeId = (int) ($this->config->get('config_store_id') ?? 0);

        try {
            $services = $this->createCpServices();
            $credentials = $services['credentials'];

            if ($credentials->getUnicid($storeId) === '') {
                return ['error' => 'unicid_missing'];
            }
            if (!$credentials->hasSecret($storeId)) {
                return ['error' => 'secret_missing'];
            }
            if (!$credentials->isSecretReadable($storeId)) {
                return ['error' => 'secret_unreadable'];
            }

            $shop = $services['shopConfiguration']->refreshRemote();
            $meta = $services['shopConfiguration']->getMetadata();
            $schemeCount = 0;
            if (isset($shop['coeff_list']) && is_array($shop['coeff_list'])) {
                $schemeCount = count($shop['coeff_list']);
            }

            return [
                'success'      => 'bank_data_refreshed',
                'fetched_at'   => isset($meta['fetched_at']) ? (string) $meta['fetched_at'] : null,
                'scheme_count' => $schemeCount,
                'cache_fresh'  => (bool) ($meta['is_fresh'] ?? true),
            ];
        } catch (CpAuthenticationException $exception) {
            return ['error' => 'authentication_failed'];
        } catch (ShopSnapshotValidationException $exception) {
            return ['error' => 'shop_snapshot_invalid'];
        } catch (CpException $exception) {
            return ['error' => $exception->isTransient() ? 'transient_failure' : 'request_failed'];
        } catch (\Throwable $exception) {
            return ['error' => 'request_failed'];
        }
    }

    /**
     * @return array{success?: string, error?: string, auth_state?: string}
     * @deprecated Internal/test helper — not exposed in operator UI.
     */
    public function cpConnect(): array
    {
        $result = $this->refreshBankData();
        if (isset($result['success'])) {
            return ['success' => 'connected'];
        }

        return isset($result['error']) ? ['error' => $result['error']] : ['error' => 'request_failed'];
    }

    /**
     * @return array{success?: string, error?: string}
     * @deprecated Prefer {@see refreshBankData()}.
     */
    public function cpRefreshShop(): array
    {
        $result = $this->refreshBankData();
        if (isset($result['success'])) {
            return ['success' => 'shop_refreshed'];
        }

        return isset($result['error']) ? ['error' => $result['error']] : ['error' => 'request_failed'];
    }

    /**
     * @return array{success?: string, error?: string}
     * @deprecated Internal — not exposed in operator UI.
     */
    public function cpDisconnect(): array
    {
        try {
            $services = $this->createCpServices();
            try {
                $services['client']->logout();
            } catch (\Throwable $exception) {
                // Local invalidation happens in logout() finally; network errors are safe to report.
                return ['success' => 'disconnected_local', 'error' => 'remote_logout_failed'];
            }

            return ['success' => 'disconnected'];
        } catch (\Throwable $exception) {
            return ['error' => 'request_failed'];
        }
    }

    public function saveModuleSettings(array $payload, ?string $plainSecret, bool $secretFieldSubmitted): void
    {
        $storeId = (int) ($this->config->get('config_store_id') ?? 0);
        $services = $this->createCpServices();
        $previousUnicid = $services['credentials']->getUnicid($storeId);
        unset($payload[ModuleCredentialsRepository::SECRET_SETTING]);

        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting(ModuleConstants::MODULE_SETTING_CODE, $payload);

        $secretChanged = false;
        if ($secretFieldSubmitted && $plainSecret !== null && $plainSecret !== '') {
            $services['credentials']->saveSecret($storeId, $plainSecret);
            $secretChanged = true;
        }

        $newUnicid = trim((string) ($payload[ModuleCredentialsRepository::UNICID_SETTING] ?? ''));
        if ($newUnicid !== $previousUnicid || $secretChanged) {
            $services['credentialChange']->onCredentialsChanged($previousUnicid, $newUnicid);
        }
    }

    /** @return array<string, mixed> */
    private function emptyCpHealth(): array
    {
        return [
            'cp_host'              => null,
            'unicid'               => null,
            'secret_configured' => false,
            'secret_readable'   => true,
            'auth_state'           => 'missing_credentials',
            'token_expires_at'     => null,
            'token_expired'        => false,
            'cache_present'        => false,
            'cache_fetched_at'     => null,
            'cache_expires_at'     => null,
            'cache_fresh'          => false,
        ];
    }
}
