<?php

namespace Opencart\Admin\Model\Extension\MtUniCredit\Module;

use Opencart\System\Library\Extension\MtUniCredit\CanonicalShopUrlProvider;
use Opencart\System\Library\Extension\MtUniCredit\CpAdminHealthPresenter;
use Opencart\System\Library\Extension\MtUniCredit\CpAuthenticationException;
use Opencart\System\Library\Extension\MtUniCredit\CpException;
use Opencart\System\Library\Extension\MtUniCredit\CpServiceFactory;
use Opencart\System\Library\Extension\MtUniCredit\DeploymentHealthService;
use Opencart\System\Library\Extension\MtUniCredit\EventRegistry;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleLocalSettings;
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
            ModuleConstants::MODULE_SETTING_CODE . '_status' => 0,
            ModuleCredentialsRepository::UNICID_SETTING      => '',
            ModuleLocalSettings::ADVERTISING_ENABLED         => ModuleLocalSettings::DEFAULT_ADVERTISING_ENABLED,
            ModuleLocalSettings::DEBUG_ENABLED               => ModuleLocalSettings::DEFAULT_DEBUG_ENABLED,
            ModuleLocalSettings::PRODUCT_BUTTON_ACTION       => ModuleLocalSettings::DEFAULT_PRODUCT_BUTTON_ACTION,
            ModuleLocalSettings::BUTTON_TOP_SPACING          => ModuleLocalSettings::DEFAULT_BUTTON_TOP_SPACING,
            ModuleConstants::AWAITING_FINANCING_ORDER_STATUS_SETTING => 0,
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

    /**
     * Re-sync when any EventRegistry code is missing from oc_event (e.g. Phase 8 cart hooks
     * after a file deploy without Admin Save).
     *
     * @return list<string> missing codes that triggered the sync (empty when already complete)
     */
    public function ensureEventsSynchronized(): array
    {
        $this->load->model('setting/event');
        $installed = [];
        foreach (EventRegistry::eventCodes() as $code) {
            $row = $this->model_setting_event->getEventByCode($code);
            if ($row !== []) {
                $installed[] = $code;
            }
        }

        $missing = \Opencart\System\Library\Extension\MtUniCredit\EventRegistrationGap::missingCodes($installed);
        if ($missing === []) {
            return [];
        }

        $this->syncEvents();

        return $missing;
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
        [$sslUrl, $plainUrl] = $this->resolveCatalogUrls();

        return CpServiceFactory::create(
            $connection,
            $settings,
            $storeId,
            $sslUrl,
            $plainUrl
        );
    }

    /**
     * OpenCart default store often has empty config_ssl/config_url in oc_setting.
     * Fall back to admin HTTP(S)_CATALOG constants so CP login `name` is never blank.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveCatalogUrls(): array
    {
        $sslUrl = trim((string) ($this->config->get('config_ssl') ?? ''));
        $plainUrl = trim((string) ($this->config->get('config_url') ?? ''));

        if ($sslUrl === '' && defined('HTTPS_CATALOG')) {
            $sslUrl = (string) \HTTPS_CATALOG;
        }
        if ($plainUrl === '' && defined('HTTP_CATALOG')) {
            $plainUrl = (string) \HTTP_CATALOG;
        }
        if ($sslUrl === '' && $plainUrl !== '') {
            $sslUrl = $plainUrl;
        }

        return [$sslUrl, $plainUrl];
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
            [$sslUrl, $plainUrl] = $this->resolveCatalogUrls();
            $shopName = (new CanonicalShopUrlProvider())->resolve($sslUrl, $plainUrl);
            if ($shopName === '') {
                $this->writeRefreshLog('shop_url_missing');

                return ['error' => 'shop_url_missing'];
            }

            $services = $this->createCpServices();
            $credentials = $services['credentials'];

            if ($credentials->getUnicid($storeId) === '') {
                $this->writeRefreshLog('unicid_missing');

                return ['error' => 'unicid_missing'];
            }
            if (!$credentials->hasSecret($storeId)) {
                $this->writeRefreshLog('secret_missing');

                return ['error' => 'secret_missing'];
            }
            if (!$credentials->isSecretReadable($storeId)) {
                $this->writeRefreshLog('secret_unreadable');

                return ['error' => 'secret_unreadable'];
            }

            $shop = $services['shopConfiguration']->refreshRemote();
            $meta = $services['shopConfiguration']->getMetadata();
            $schemeCount = 0;
            if (isset($shop['coeff_list']) && is_array($shop['coeff_list'])) {
                $schemeCount = count($shop['coeff_list']);
            }

            $this->writeRefreshLog('bank_data_refreshed');

            return [
                'success'      => 'bank_data_refreshed',
                'fetched_at'   => isset($meta['fetched_at']) ? (string) $meta['fetched_at'] : null,
                'scheme_count' => $schemeCount,
                'cache_fresh'  => (bool) ($meta['is_fresh'] ?? true),
            ];
        } catch (CpAuthenticationException $exception) {
            $this->writeRefreshLog('authentication_failed');

            return ['error' => 'authentication_failed'];
        } catch (ShopSnapshotValidationException $exception) {
            $this->writeRefreshLog('shop_snapshot_invalid');

            return ['error' => 'shop_snapshot_invalid'];
        } catch (CpException $exception) {
            $code = $exception->isTransient() ? 'transient_failure' : 'request_failed';
            $this->writeRefreshLog($code);

            return ['error' => $code];
        } catch (\Throwable $exception) {
            $this->writeRefreshLog('request_failed:' . (new \ReflectionClass($exception))->getShortName());

            return ['error' => 'request_failed'];
        }
    }

    private function writeRefreshLog(string $classification): void
    {
        if (!isset($this->log) || !is_object($this->log) || !method_exists($this->log, 'write')) {
            return;
        }

        $this->log->write('mt_uni_credit.refreshBankData classification=' . $classification);
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
        $existing = $this->model_setting_setting->getSetting(ModuleConstants::MODULE_SETTING_CODE, $storeId);
        if (!is_array($existing)) {
            $existing = [];
        }

        // editSetting() deletes all rows for the module code — preserve secret/tokens/other keys.
        foreach ($existing as $key => $value) {
            if (!array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }

        $this->model_setting_setting->editSetting(ModuleConstants::MODULE_SETTING_CODE, $payload, $storeId);

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
