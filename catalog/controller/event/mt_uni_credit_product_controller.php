<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

/**
 * Зарежда CSS/JS за продуктов UniCredit блок с cache-busting по filemtime.
 */
class MtUniCreditProductController extends \Opencart\System\Engine\Controller
{
    private string $module = 'module_mt_uni_credit';

    public function init(&$route, &$data): void
    {
        if ($route !== 'product/product' || !$this->config->get($this->module . '_status')) {
            return;
        }

        $cssCatalog = \DIR_APPLICATION . 'view/stylesheet/mt_uni_credit/uni_credit_products.css';
        $cssExt = \DIR_EXTENSION . 'mt_uni_credit/catalog/view/stylesheet/mt_uni_credit/uni_credit_products.css';
        $cssPath = is_file($cssCatalog) ? $cssCatalog : $cssExt;

        $jsCatalog = \DIR_APPLICATION . 'view/javascript/mt_uni_credit/uni_credit_products.js';
        $jsExt = \DIR_EXTENSION . 'mt_uni_credit/catalog/view/javascript/mt_uni_credit/uni_credit_products.js';
        $jsPath = is_file($jsCatalog) ? $jsCatalog : $jsExt;

        $verCss = is_file($cssPath) ? (string) filemtime($cssPath) : '0';
        $verJs = is_file($jsPath) ? (string) filemtime($jsPath) : '0';

        $this->document->addStyle('catalog/view/stylesheet/mt_uni_credit/uni_credit_products.css?ver=' . $verCss);
        $this->document->addScript('catalog/view/javascript/mt_uni_credit/uni_credit_products.js?ver=' . $verJs);
    }
}
