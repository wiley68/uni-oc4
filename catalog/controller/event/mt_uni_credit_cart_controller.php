<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

/**
 * Class MtUniCreditCartController
 *
 * @package Opencart\Catalog\Controller\Extension\MtUniCredit\Event
 */
class MtUniCreditCartController extends \Opencart\System\Engine\Controller
{
    private string $module = 'module_mt_uni_credit';

    /**
     * Initializes the cart controller event - adds UniCredit block to the cart page
     */
    public function init(&$route, &$data): void
    {
        if ($route !== 'checkout/cart' || !$this->config->get($this->module . '_status')) {
            return;
        }

        $cssExt = \DIR_EXTENSION . 'mt_uni_credit/catalog/view/stylesheet/mt_uni_credit/uni_credit_cart.css';
        $cssCatalog = \DIR_APPLICATION . 'view/stylesheet/mt_uni_credit/uni_credit_cart.css';

        $jsExt = \DIR_EXTENSION . 'mt_uni_credit/catalog/view/javascript/mt_uni_credit/uni_credit_cart.js';
        $jsCatalog = \DIR_APPLICATION . 'view/javascript/mt_uni_credit/uni_credit_cart.js';

        // Ако extension asset-ът е по-нов, синхронизираме го в catalog/, откъдето се сервира.
        $this->syncAssetIfNewer($cssExt, $cssCatalog);
        $this->syncAssetIfNewer($jsExt, $jsCatalog);

        $cssPath = is_file($cssCatalog) ? $cssCatalog : $cssExt;
        $jsPath = is_file($jsCatalog) ? $jsCatalog : $jsExt;

        $verCss = is_file($cssPath) ? (string) filemtime($cssPath) : '0';
        $verJs = is_file($jsPath) ? (string) filemtime($jsPath) : '0';

        $this->document->addStyle('catalog/view/stylesheet/mt_uni_credit/uni_credit_cart.css?ver=' . $verCss);
        $this->document->addScript('catalog/view/javascript/mt_uni_credit/uni_credit_cart.js?ver=' . $verJs);
    }

    private function syncAssetIfNewer(string $src, string $dst): void
    {
        if (!is_file($src)) {
            return;
        }

        $shouldCopy = !is_file($dst) || filemtime($src) > filemtime($dst);
        if (!$shouldCopy) {
            return;
        }

        $dir = dirname($dst);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @copy($src, $dst);
    }
}
