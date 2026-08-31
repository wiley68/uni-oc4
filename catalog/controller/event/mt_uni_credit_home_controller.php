<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\CpServiceFactory;
use Opencart\System\Library\Extension\MtUniCredit\HomepageAdvertisingContextResolver;
use Opencart\System\Library\Extension\MtUniCredit\HomepageAdvertisingGate;
use Opencart\System\Library\Extension\MtUniCredit\HomepageAdvertisingPresenter;
use Opencart\System\Library\Extension\MtUniCredit\ModuleAssetVersion;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleLocalSettings;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartDbConnection;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartModuleSettingStore;
use Opencart\System\Library\Extension\MtUniCredit\StorefrontMobileDetector;
use Opencart\System\Library\Extension\MtUniCredit\StorefrontRouteResolver;

/**
 * Homepage advertising assets — catalog/controller/common/home/before.
 *
 * OpenCart 4.1.0.3 supplies: string &$route, array &$args
 */
class MtUniCreditHomeController extends \Opencart\System\Engine\Controller
{
    public function beforeHome(string &$route, array &$args): void
    {
        if ($route !== 'common/home') {
            return;
        }

        if ($this->resolveAdvertisingContext('common/home') === null) {
            return;
        }

        $this->document->addStyle(ModuleAssetVersion::href('catalog/view/stylesheet/mt_uni_credit_fonts.css'));
        $this->document->addStyle(ModuleAssetVersion::href('catalog/view/stylesheet/mt_uni_credit_homepage_advertising.css'));
        $this->document->addScript(
            ModuleAssetVersion::href('catalog/view/javascript/mt_uni_credit_homepage_advertising.js'),
            'footer'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveAdvertisingContext(string $route): ?array
    {
        if (!defined('DB_PREFIX')) {
            return null;
        }

        $moduleEnabled = (bool) $this->config->get(ModuleConstants::MODULE_SETTING_CODE . '_status');
        $advertisingEnabled = ModuleLocalSettings::normalizeFlag(
            $this->config->get(ModuleLocalSettings::ADVERTISING_ENABLED)
        ) === 1;

        $storeId = (int) ($this->config->get('config_store_id') ?? 0);
        $connection = new OpenCartDbConnection($this->db, DB_PREFIX);
        $settings = new OpenCartModuleSettingStore($connection);
        $services = CpServiceFactory::create(
            $connection,
            $settings,
            $storeId,
            (string) ($this->config->get('config_ssl') ?? ''),
            (string) ($this->config->get('config_url') ?? '')
        );

        $resolver = new HomepageAdvertisingContextResolver(
            new HomepageAdvertisingGate(),
            new HomepageAdvertisingPresenter(new HomepageAdvertisingGate()),
            $services['shopConfiguration'],
            $services['credentials'],
            $storeId
        );

        return $resolver->resolve(
            StorefrontRouteResolver::isHomepageRoute($route),
            $moduleEnabled,
            $advertisingEnabled,
            StorefrontMobileDetector::isMobile((string) ($this->request->server['HTTP_USER_AGENT'] ?? '')),
            $this->defaultLogoUrl()
        );
    }

    private function defaultLogoUrl(): string
    {
        return 'extension/' . ModuleConstants::EXTENSION_CODE . '/catalog/view/image/product/uni_logo.svg';
    }
}
