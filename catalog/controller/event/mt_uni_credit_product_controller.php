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

        $jsCatalog = \DIR_APPLICATION . 'view/javascript/mt_uni_credit/uni_credit_products.js';
        $jsExt = \DIR_EXTENSION . 'mt_uni_credit/catalog/view/javascript/mt_uni_credit/uni_credit_products.js';

        // Публичният URL винаги сочи към catalog/view/... — ако редактирате само extension/, копието в catalog остава старо и ?ver= не се сменя. Опресняваме при по-нов източник.
        $this->ensureMtUniCreditViewTreeFresh(
            \DIR_EXTENSION . 'mt_uni_credit/catalog/view/stylesheet/mt_uni_credit',
            \DIR_APPLICATION . 'view/stylesheet/mt_uni_credit',
            $cssExt,
            $cssCatalog,
            true
        );
        $this->ensureMtUniCreditViewTreeFresh(
            \DIR_EXTENSION . 'mt_uni_credit/catalog/view/javascript/mt_uni_credit',
            \DIR_APPLICATION . 'view/javascript/mt_uni_credit',
            $jsExt,
            $jsCatalog
        );

        $cssPath = is_file($cssCatalog) ? $cssCatalog : $cssExt;
        $jsPath = is_file($jsCatalog) ? $jsCatalog : $jsExt;

        $verCss = $this->mtUniStylesheetCacheBuster($cssPath);
        $verJs = is_file($jsPath) ? (string) filemtime($jsPath) : '0';

        $this->document->addStyle('catalog/view/stylesheet/mt_uni_credit/uni_credit_products.css?ver=' . $verCss);
        $this->document->addScript('catalog/view/javascript/mt_uni_credit/uni_credit_products.js?ver=' . $verJs);
    }

    /**
     * Най-новият mtime между основния CSS, roboto-condensed.css и локалните woff2 (за ?ver= и за решение дали да се копира от extension).
     */
    private function maxMtimeUniStylesheetBundle(string $mainCssPath): int
    {
        if (!is_file($mainCssPath)) {
            return 0;
        }

        $times = [(int) filemtime($mainCssPath)];
        $base = dirname($mainCssPath);
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

        return (int) max($times);
    }

    private function mtUniStylesheetCacheBuster(string $cssPath): string
    {
        $m = $this->maxMtimeUniStylesheetBundle($cssPath);

        return $m > 0 ? (string) $m : '0';
    }

    /**
     * Копира цялото поддърво от extension към catalog, ако източникът е по-нов.
     * При $useStylesheetBundleTimestamps сравнява max mtime на CSS + roboto + woff2 (като ?ver=).
     * Същата идея като syncCatalogPublicAssets() в админа, но само при нужда на продуктова страница.
     *
     * @param bool $useStylesheetBundleTimestamps само за папката stylesheet/mt_uni_credit
     */
    private function ensureMtUniCreditViewTreeFresh(
        string $fromDir,
        string $toDir,
        string $mainSrcFile,
        string $mainDestFile,
        bool $useStylesheetBundleTimestamps = false
    ): void {
        if (!is_file($mainSrcFile) || !is_dir($fromDir)) {
            return;
        }

        if ($useStylesheetBundleTimestamps) {
            $srcM = $this->maxMtimeUniStylesheetBundle($mainSrcFile);
            $destM = $this->maxMtimeUniStylesheetBundle($mainDestFile);
        } else {
            $srcM = (int) filemtime($mainSrcFile);
            $destM = is_file($mainDestFile) ? (int) filemtime($mainDestFile) : 0;
        }

        if ($srcM <= $destM) {
            return;
        }

        $toDir = rtrim($toDir, '/\\');
        if (!is_dir($toDir) && !@mkdir($toDir, 0775, true) && !is_dir($toDir)) {
            return;
        }

        $this->copyMtUniCreditViewTree($fromDir, $toDir);
    }

    private function copyMtUniCreditViewTree(string $from, string $to): void
    {
        $from = rtrim($from, '/\\') . '/';
        $to = rtrim($to, '/\\') . '/';

        try {
            $dir = new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS);
        } catch (\Exception) {
            return;
        }

        $it = new \RecursiveIteratorIterator($dir, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($it as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $pathname = $fileInfo->getPathname();
            $relative = substr($pathname, strlen($from));
            if ($relative === false || $relative === '') {
                continue;
            }

            $relative = str_replace('\\', '/', $relative);
            if (str_ends_with($relative, '.gitkeep')) {
                continue;
            }

            $dest = $to . $relative;
            $destDir = dirname($dest);
            if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                continue;
            }

            @copy($pathname, $dest);
        }
    }
}
