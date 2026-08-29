<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\ModuleAssetVersion;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;

/**
 * Cart page asset registration — catalog/controller/checkout/cart/before.
 *
 * OpenCart 4.1.0.3 supplies: string &$route, array &$args
 */
class MtUniCreditCartController extends \Opencart\System\Engine\Controller
{
    private string $module = ModuleConstants::MODULE_SETTING_CODE;

    public function init(string &$route, array &$args): void
    {
        if ($route !== 'checkout/cart') {
            return;
        }
        if (!$this->config->get($this->module . '_status')) {
            return;
        }

        // Reuse Product visual system (shared CSS) + thin cart refresh JS.
        $this->document->addStyle(ModuleAssetVersion::href('catalog/view/stylesheet/mt_uni_credit_fonts.css'));
        $this->document->addStyle(ModuleAssetVersion::href('catalog/view/stylesheet/mt_uni_credit_product.css'));
        $this->document->addStyle(ModuleAssetVersion::href('catalog/view/stylesheet/mt_uni_credit_cart.css'));
        $this->document->addScript(
            ModuleAssetVersion::href('catalog/view/javascript/mt_uni_credit_cart.js'),
            'footer'
        );
    }
}
