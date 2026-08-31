<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\ModuleAssetVersion;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ProductBuyHandoffTrace;

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

        // TEMPORARY 09E: ?mtuc_trace=1 enables handoff Network markers for this session.
        ProductBuyHandoffTrace::captureRequest(
            $this->session->data,
            $this->request->get ?? [],
            $this->request->post ?? []
        );

        // Local Roboto Condensed @font-face before Product UI stylesheet (filemtime cache-bust).
        $this->document->addStyle(ModuleAssetVersion::href('catalog/view/stylesheet/mt_uni_credit_fonts.css'));
        $this->document->addStyle(ModuleAssetVersion::href('catalog/view/stylesheet/mt_uni_credit_product.css'));
        // Footer: product fragment is injected in body; header scripts run too early.
        $this->document->addScript(
            ModuleAssetVersion::href('catalog/view/javascript/mt_uni_credit_redirect.js'),
            'footer'
        );
        $this->document->addScript(
            ModuleAssetVersion::href('catalog/view/javascript/mt_uni_credit_product.js'),
            'footer'
        );
        if (ProductBuyHandoffTrace::isEnabled($this->session->data)) {
            $this->document->addScript(
                ModuleAssetVersion::href('catalog/view/javascript/mt_uni_credit_09e_trace.js'),
                'footer'
            );
            $this->response->addHeader('X-Mtuc-Trace: PRODUCT_PAGE_TRACE_ON;build=' . ProductBuyHandoffTrace::BUILD);
        }

        $debug = (bool) $this->config->get(\Opencart\System\Library\Extension\MtUniCredit\ModuleLocalSettings::DEBUG_ENABLED);
        \Opencart\System\Library\Extension\MtUniCredit\ProductVisibilityDebugLog::write(
            $this->log,
            $debug,
            'Product assets event executed route=' . $route
        );
    }
}
