<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

/**
 * Начална страница: плаващ банер — PS8 unipanel.tpl + unipanel.css + unipanel.js.
 */
class MtUniCreditContentTop extends \Opencart\System\Engine\Controller
{
    private string $module = 'module_mt_uni_credit';

    public function init(&$route, &$data): void
    {
        if (!$this->isHomeLayoutContext()) {
            return;
        }

        if (!$this->config->get($this->module . '_status') || !$this->config->get($this->module . '_reklama')) {
            return;
        }

        $assign = $this->fetchUnipanelAssign();
        if ($assign === null) {
            return;
        }

        $cssCatalog = \DIR_APPLICATION . 'view/stylesheet/mt_uni_credit/unipanel.css';
        $cssExt = \DIR_EXTENSION . 'mt_uni_credit/catalog/view/stylesheet/mt_uni_credit/unipanel.css';
        $cssPath = is_file($cssCatalog) ? $cssCatalog : $cssExt;

        $jsCatalog = \DIR_APPLICATION . 'view/javascript/mt_uni_credit/unipanel.js';
        $jsExt = \DIR_EXTENSION . 'mt_uni_credit/catalog/view/javascript/mt_uni_credit/unipanel.js';
        $jsPath = is_file($jsCatalog) ? $jsCatalog : $jsExt;

        $verCss = is_file($cssPath) ? (string) filemtime($cssPath) : '0';
        $verJs = is_file($jsPath) ? (string) filemtime($jsPath) : '0';

        $this->document->addStyle('catalog/view/stylesheet/mt_uni_credit/unipanel.css?ver=' . $verCss);
        $this->document->addScript('catalog/view/javascript/mt_uni_credit/unipanel.js?ver=' . $verJs);
    }

    public function addHtml(&$route, &$data, &$output): void
    {
        if (!$this->isHomeLayoutContext()) {
            return;
        }

        if (!$this->config->get($this->module . '_status') || !$this->config->get($this->module . '_reklama')) {
            return;
        }

        $assign = $this->fetchUnipanelAssign();
        if ($assign === null) {
            return;
        }

        $this->load->language('extension/mt_uni_credit/unipanel');

        $viewData = array_merge($assign, [
            'text_rek_float_alt'  => $this->language->get('text_rek_float_alt'),
            'text_rek_panel_alt'  => $this->language->get('text_rek_panel_alt'),
            'text_rek_link_title' => $this->language->get('text_rek_link_title'),
            'text_rek_link_text'  => $this->language->get('text_rek_link_text'),
        ]);

        $html = $this->load->view('extension/mt_uni_credit/unipanel', $viewData);

        $hook = '{% for module in modules %}';
        $position = strpos($output, $hook);
        if ($position !== false) {
            $output = substr($output, 0, $position) . $html . substr($output, $position);
        } else {
            $output = $html . $output;
        }
    }

    /**
     * Начална страница: празен route или common/home (вкл. SEO).
     */
    private function isHomeLayoutContext(): bool
    {
        $route = isset($this->request->get['route']) ? (string) $this->request->get['route'] : '';

        return $route === '' || $route === 'common/home';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchUnipanelAssign(): ?array
    {
        $sslUrl = (string) ($this->config->get('config_ssl') ?: $this->config->get('config_url'));
        $shopSslBase = rtrim($sslUrl, '/');
        $userAgent = isset($this->request->server['HTTP_USER_AGENT']) ? (string) $this->request->server['HTTP_USER_AGENT'] : '';

        $this->load->model('extension/mt_uni_credit/module/product_panel');

        return $this->model_extension_mt_uni_credit_module_product_panel->buildAssignForUnipanel($shopSslBase, $userAgent);
    }
}
