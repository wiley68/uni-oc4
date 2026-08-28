<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;

/**
 * Product page asset registration — catalog/controller/product/product/before.
 *
 * OpenCart 4.1.0.3 supplies: string &$route, array &$args
 */
class MtUniCreditProductController extends \Opencart\System\Engine\Controller
{
    private string $module = ModuleConstants::MODULE_SETTING_CODE;

    public function init(string &$route, array &$args): void
    {
        if ($route !== 'product/product') {
            return;
        }
        if (!$this->config->get($this->module . '_status')) {
            return;
        }

        $version = ModuleConstants::VERSION;
        $this->document->addStyle('extension/mt_uni_credit/catalog/view/stylesheet/mt_uni_credit_product.css?ver=' . $version);
        $this->document->addScript('extension/mt_uni_credit/catalog/view/javascript/mt_uni_credit_product.js?ver=' . $version);

        $debug = (bool) $this->config->get(\Opencart\System\Library\Extension\MtUniCredit\ModuleLocalSettings::DEBUG_ENABLED);
        \Opencart\System\Library\Extension\MtUniCredit\ProductVisibilityDebugLog::write(
            $this->log,
            $debug,
            'Product assets event executed route=' . $route
        );
    }
}
