<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;

class MtUniCreditProductController extends \Opencart\System\Engine\Controller
{
    private string $module = ModuleConstants::MODULE_SETTING_CODE;

    public function init(string &$route, array &$data, mixed &$output): void
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
    }
}
