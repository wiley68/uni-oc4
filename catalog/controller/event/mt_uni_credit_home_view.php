<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\CpServiceFactory;
use Opencart\System\Library\Extension\MtUniCredit\HomepageAdvertisingContextResolver;
use Opencart\System\Library\Extension\MtUniCredit\HomepageAdvertisingGate;
use Opencart\System\Library\Extension\MtUniCredit\HomepageAdvertisingPresenter;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleLocalSettings;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartDbConnection;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartModuleSettingStore;
use Opencart\System\Library\Extension\MtUniCredit\StorefrontMobileDetector;
use Opencart\System\Library\Extension\MtUniCredit\StorefrontRouteResolver;

/**
 * Homepage advertising markup — catalog/view/common/footer/after (homepage requests only).
 *
 * OpenCart 4.1.0.3 supplies: string &$route, array &$data, string &$output
 */
class MtUniCreditHomeView extends \Opencart\System\Engine\Controller
{
    public function afterFooter(string &$route, array &$data, string &$output): void
    {
        if ($route !== 'common/footer') {
            return;
        }

        $requestRoute = StorefrontRouteResolver::currentRoute($this->request->get['route'] ?? null);
        if (!StorefrontRouteResolver::isHomepageRoute($requestRoute)) {
            return;
        }

        $context = $this->resolveAdvertisingContext($requestRoute);
        if ($context === null) {
            return;
        }

        $this->load->language('extension/mt_uni_credit/module/mt_uni_credit_home');
        $viewData = [
            'mt_uni_credit_advertising' => $context,
            'text_float_alt'            => $this->language->get('text_float_alt'),
            'text_panel_label'          => $this->language->get('text_panel_label'),
            'text_panel_cta'            => $this->language->get('text_panel_cta'),
            'text_close'                => $this->language->get('text_close'),
        ];
        $fragment = $this->load->view('extension/mt_uni_credit/module/mt_uni_credit_homepage_advertising', $viewData);
        if (trim($fragment) === '') {
            return;
        }

        $output .= $fragment;
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
