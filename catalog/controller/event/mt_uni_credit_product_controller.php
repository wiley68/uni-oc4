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

        $verCss = $this->mtUniStylesheetCacheBuster($cssPath);
        $verJs = is_file($jsPath) ? (string) filemtime($jsPath) : '0';

        $this->document->addStyle('catalog/view/stylesheet/mt_uni_credit/uni_credit_products.css?ver=' . $verCss);
        $this->document->addScript('catalog/view/javascript/mt_uni_credit/uni_credit_products.js?ver=' . $verJs);
    }

    /**
     * Най-новият mtime между основния CSS, roboto-condensed.css и локалните woff2 (след смяна на шрифт да се смени ?ver=).
     */
    private function mtUniStylesheetCacheBuster(string $cssPath): string
    {
        if (!is_file($cssPath)) {
            return '0';
        }

        $times = [(int) filemtime($cssPath)];
        $base = dirname($cssPath);
        $rc = $base . '/roboto-condensed.css';
        if (is_file($rc)) {
            $times[] = (int) filemtime($rc);
        }
        $fontsDir = $base . '/fonts';
        if (is_dir($fontsDir)) {
            try {
                $it = new \FilesystemIterator($fontsDir, \FilesystemIterator::SKIP_DOTS);
            } catch (\Exception) {
                $it = null;
            }
            if ($it !== null) {
                foreach ($it as $fi) {
                    if ($fi->isFile() && str_ends_with(strtolower($fi->getFilename()), '.woff2')) {
                        $times[] = (int) $fi->getMTime();
                    }
                }
            }
        }

        return (string) max($times);
    }
}
