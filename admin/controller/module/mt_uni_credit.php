<?php

namespace Opencart\Admin\Controller\Extension\MtUniCredit\Module;

class MtUniCredit extends \Opencart\System\Engine\Controller
{
    private array $error = [];
    private $path = 'extension/mt_uni_credit/module/mt_uni_credit';
    private $model = 'extension/mt_uni_credit/module/unicredit';
    private $module = 'module_mt_uni_credit';
    private $event_content_top = 'extension/mt_uni_credit/event/mt_uni_credit_content_top';
    private $event_product_controller = 'extension/mt_uni_credit/event/mt_uni_credit_product_controller';
    private $event_product_view = 'extension/mt_uni_credit/event/mt_uni_credit_product_view';

    public function index(): void
    {
        $user_token = 'user_token=' . $this->session->data['user_token'];

        $this->load->language($this->path);

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] === 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('module_mt_uni_credit', $this->request->post);

            $this->syncCatalogPublicAssets();

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('marketplace/extension', $user_token . '&type=module'));
        }

        $data['uni_text_edit'] = $this->language->get('uni_text_edit');
        $data['uni_text_edit_kop'] = $this->language->get('uni_text_edit_kop');
        $data['uni_text_module'] = $this->language->get('uni_text_module');
        $data['uni_text_enabled'] = $this->language->get('uni_text_enabled');
        $data['uni_text_disabled'] = $this->language->get('uni_text_disabled');
        $data['heading_title'] = $this->language->get('heading_title');
        $data['uni_entry_status'] = $this->language->get('uni_entry_status');
        $data['uni_entry_status_small'] = $this->language->get('uni_entry_status_small');
        $data['uni_entry_unicid'] = $this->language->get('uni_entry_unicid');
        $data['uni_entry_unicid_small'] = $this->language->get('uni_entry_unicid_small');
        $data['uni_entry_reklama'] = $this->language->get('uni_entry_reklama');
        $data['uni_entry_reklama_small'] = $this->language->get('uni_entry_reklama_small');
        $data['uni_entry_cart'] = $this->language->get('uni_entry_cart');
        $data['uni_entry_cart_small'] = $this->language->get('uni_entry_cart_small');
        $data['uni_entry_debug'] = $this->language->get('uni_entry_debug');
        $data['uni_entry_debug_small'] = $this->language->get('uni_entry_debug_small');
        $data['uni_entry_gap'] = $this->language->get('uni_entry_gap');
        $data['uni_entry_gap_small'] = $this->language->get('uni_entry_gap_small');
        $data['uni_button_save'] = $this->language->get('uni_button_save');
        $data['uni_button_cancel'] = $this->language->get('uni_button_cancel');
        $data['uni_kop_section_title'] = $this->language->get('uni_kop_section_title');
        $data['uni_kop_section_hint'] = $this->language->get('uni_kop_section_hint');
        $data['uni_kop_col_id'] = $this->language->get('uni_kop_col_id');
        $data['uni_kop_col_name'] = $this->language->get('uni_kop_col_name');
        $data['uni_kop_col_kop'] = $this->language->get('uni_kop_col_kop');
        $data['uni_kop_col_promo'] = $this->language->get('uni_kop_col_promo');
        $data['uni_kop_empty'] = $this->language->get('uni_kop_empty');
        $data['uni_button_save_kop'] = $this->language->get('uni_button_save_kop');
        $data['uni_button_refresh_kop'] = $this->language->get('uni_button_refresh_kop');
        $data['uni_kop_refresh_hint'] = $this->language->get('uni_kop_refresh_hint');
        $data['error_warning'] = $this->error['warning'] ?? '';

        $this->load->model($this->model);
        $data['kop_mappings'] = $this->model_extension_mt_uni_credit_module_unicredit->getTopLevelCategoryMappings();

        $data['breadcrumbs'] = [];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', $user_token)
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', $user_token . '&type=module')
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link($this->path, $user_token)
        ];

        $data['save'] = $this->url->link($this->path . '|save', $user_token);
        $data['save_kop'] = $this->url->link($this->path . '|saveKop', $user_token);
        $data['refresh_kop'] = $this->url->link($this->path . '|refreshKop', $user_token);
        $data['back'] = $this->url->link('marketplace/extension', $user_token . '&type=module');

        $data[$this->module . '_status'] = $this->config->get($this->module . '_status');
        $data[$this->module . '_unicid'] = $this->config->get($this->module . '_unicid');
        $data[$this->module . '_reklama'] = $this->config->get($this->module . '_reklama');
        $data[$this->module . '_cart'] = $this->config->get($this->module . '_cart');
        $data[$this->module . '_debug'] = $this->config->get($this->module . '_debug');
        $gap_cfg = $this->config->get($this->module . '_gap');
        $data[$this->module . '_gap'] = ($gap_cfg === null || $gap_cfg === '') ? 0 : (int) $gap_cfg;

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view($this->path, $data));
    }

    public function install(): void
    {
        if ($this->user->hasPermission('modify', $this->path)) {
            $this->init();
        }

        $this->load->model('setting/setting');

        $this->model_setting_setting->editSetting($this->module, [
            $this->module . '_status' => 1,
            $this->module . '_reklama' => 0,
            $this->module . '_cart' => 0,
            $this->module . '_debug' => 0,
            $this->module . '_gap' => 0
        ]);

        $this->load->model('extension/mt_uni_credit/module/unicredit');
        $this->model_extension_mt_uni_credit_module_unicredit->install();

        $this->syncCatalogPublicAssets();
    }

    public function uninstall(): void
    {
        $this->load->model('extension/mt_uni_credit/module/unicredit');
        $this->model_extension_mt_uni_credit_module_unicredit->uninstall();

        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting('module_mt_uni_credit');

        if ($this->user->hasPermission('modify', $this->path)) {
            $this->load->model('setting/event');
            $this->model_setting_event->deleteEventByCode($this->module . '_before_product_controller');
            $this->model_setting_event->deleteEventByCode($this->module . '_after_product_view');
            $this->model_setting_event->deleteEventByCode($this->module . '_before_content_top');
            $this->model_setting_event->deleteEventByCode($this->module . '_after_content_top_view');
        }

        $this->removeCatalogPublicAssets();
    }

    public function save(): void
    {
        $this->load->language($this->path);

        $json = [];
        $errors = [];

        if (!$this->user->hasPermission('modify', $this->path)) {
            $json['error'] = $this->language->get('error_permission');
        }

        $unicid = isset($this->request->post[$this->module . '_unicid']) ? trim((string)$this->request->post[$this->module . '_unicid']) : '';

        if ($unicid === '') {
            $errors[] = $this->language->get('error_unicid_required');
        }

        $gap_raw = $this->request->post[$this->module . '_gap'] ?? '';
        $gap_str = is_string($gap_raw) ? trim($gap_raw) : (string) $gap_raw;

        if ($gap_str === '') {
            $gap = 0;
        } else {
            $gap = filter_var($gap_str, FILTER_VALIDATE_INT);
            if ($gap === false || $gap < 0) {
                $errors[] = $this->language->get('error_gap_non_negative_int');
            }
        }

        if (!$json && $errors) {
            $json['error'] = implode('<br/>', $errors);
        }

        if (!$json) {
            $this->request->post[$this->module . '_gap'] = $gap;
            $this->init();
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting($this->module, $this->request->post);

            $this->syncCatalogPublicAssets();

            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function saveKop(): void
    {
        $this->load->language($this->path);

        $json = [];

        if (!$this->user->hasPermission('modify', $this->path)) {
            $json['error'] = $this->language->get('error_permission');
        }

        $kop_map = $this->request->post['kop_map'] ?? [];
        if (!is_array($kop_map)) {
            $kop_map = [];
        }

        $errors = $this->validateKopMapPost($kop_map);

        if (!$json && $errors) {
            $json['error'] = implode('<br/>', $errors);
        }

        if (!$json) {
            $this->load->model('extension/mt_uni_credit/module/unicredit');
            $this->model_extension_mt_uni_credit_module_unicredit->saveKopMappingsFromPost($kop_map);
            $json['success'] = $this->language->get('text_success_kop');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * AJAX: нулира kimb/stats по главни категории, чисти стар coeff кеш, опреснява getparameters в api_cache.
     * Редът на операциите е като в PrestaShop модула (refreshKopMapping).
     */
    public function refreshKop(): void
    {
        $this->load->language($this->path);

        $json = [
            'result'           => 'error',
            'kop_refreshed'    => false,
            'params_refreshed' => false,
            'coeff_purged'     => 0,
            'errors'           => [],
        ];

        if (!$this->user->hasPermission('modify', $this->path)) {
            $json['errors'][] = $this->language->get('error_permission');
            $json['error'] = $this->language->get('error_permission');
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));

            return;
        }

        $unicid = trim((string) ($this->config->get($this->module . '_unicid') ?? ''));
        if ($unicid === '') {
            $msg = $this->language->get('error_unicid_required');
            $json['errors'][] = $msg;
            $json['error'] = $msg;
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));

            return;
        }

        $this->load->model('extension/mt_uni_credit/module/unicredit');
        $model = $this->model_extension_mt_uni_credit_module_unicredit;

        $json['coeff_purged'] = $model->purgeCoeffCacheOlderThanToday();

        $model->refreshKopMappingsResetStats();
        $json['kop_refreshed'] = true;

        $params = $model->fetchUniParamsFromBankAndCache($unicid, true);
        $paramsOk = is_array($params);
        $json['params_refreshed'] = $paramsOk;

        if ($paramsOk) {
            $json['result'] = 'success';
            $json['success'] = $this->language->get('text_refresh_kop_full');
        } else {
            $json['result'] = 'partial';
            $json['errors'][] = $this->language->get('error_refresh_bank_params');
            $json['success'] = $this->language->get('text_refresh_kop_partial');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Публикува публичните ресурси на модула в DIR_CATALOG (за HTTP достъп).
     *
     * Източник (само тук държите снимки/CSS/JS в git):
     * - DIR_EXTENSION/mt_uni_credit/catalog/view/stylesheet/mt_uni_credit/ — всички raster (png/jpg/…) + CSS, подпапка с уникално име срещу конфликти с други модули
     * - DIR_EXTENSION/mt_uni_credit/catalog/view/javascript/mt_uni_credit/ — JS
     *
     * Цел:
     * - DIR_CATALOG/view/stylesheet/mt_uni_credit/
     * - DIR_CATALOG/view/javascript/mt_uni_credit/
     *
     * Извиква се при install(), при запис на настройки (save / POST от index).
     */
    protected function syncCatalogPublicAssets(): void
    {
        $catalogRoot = \defined('DIR_CATALOG')
            ? (string) \constant('DIR_CATALOG')
            : rtrim(\dirname(\DIR_APPLICATION), '/') . '/catalog/';
        $catalogRoot = rtrim($catalogRoot, '/') . '/';

        if (!is_dir($catalogRoot)) {
            return;
        }

        $srcBase = \DIR_EXTENSION . 'mt_uni_credit/catalog/view/';
        $pairs = [
            ['stylesheet/mt_uni_credit', 'view/stylesheet/mt_uni_credit'],
            ['javascript/mt_uni_credit', 'view/javascript/mt_uni_credit'],
        ];

        foreach ($pairs as [$relSrc, $relDst]) {
            $from = $srcBase . $relSrc;
            if (!is_dir($from)) {
                continue;
            }

            $to = $catalogRoot . $relDst;
            if (!is_dir($to) && !@mkdir($to, 0775, true) && !is_dir($to)) {
                continue;
            }

            $this->copyCatalogViewTree($from, $to);
        }
    }

    /**
     * Рекурсивно копира файлове от $from в $to (подпапки и PNG до CSS).
     */
    protected function copyCatalogViewTree(string $from, string $to): void
    {
        $from = rtrim($from, '/\\') . '/';
        $to = rtrim($to, '/\\') . '/';

        try {
            $dir = new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS);
        } catch (\Exception) {
            return;
        }

        /** @var \RecursiveDirectoryIterator $dir */
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

    /**
     * Премахва публично копираните ресурси от syncCatalogPublicAssets() (само папки с име mt_uni_credit).
     */
    protected function removeCatalogPublicAssets(): void
    {
        $catalogRoot = \defined('DIR_CATALOG')
            ? (string) \constant('DIR_CATALOG')
            : rtrim(\dirname(\DIR_APPLICATION), '/') . '/catalog/';
        $catalogRoot = rtrim($catalogRoot, '/\\') . '/';

        if (!is_dir($catalogRoot)) {
            return;
        }

        foreach (['view/stylesheet/mt_uni_credit', 'view/javascript/mt_uni_credit'] as $rel) {
            $this->deleteMtUniCreditPublicDirectory($catalogRoot . $rel);
        }
    }

    /**
     * Рекурсивно изтрива директория, само ако последният сегмент е точно mt_uni_credit.
     */
    protected function deleteMtUniCreditPublicDirectory(string $path): void
    {
        $path = rtrim($path, '/\\');
        if ($path === '' || !is_dir($path) || basename($path) !== 'mt_uni_credit') {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
        } catch (\Exception) {
            return;
        }

        foreach ($iterator as $item) {
            $full = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }

    protected function init(): void
    {
        $this->load->language($this->path);

        $oc_version = \defined('VERSION') ? (string) \constant('VERSION') : '4.0.2.0';
        $uni_separator = \version_compare($oc_version, '4.0.2', '>=') ? '.' : '|';

        $moduleName = $this->language->get('heading_title');
        $descController = sprintf($this->language->get('uni_event_description_product_controller'), $moduleName);
        $descView = sprintf($this->language->get('uni_event_description_product_view'), $moduleName);
        $descContentTopController = sprintf($this->language->get('uni_event_description_content_top_controller'), $moduleName);
        $descContentTopView = sprintf($this->language->get('uni_event_description_content_top_view'), $moduleName);

        $this->load->model('setting/event');

        // Event hooks за content_top - добавяне на рекламна информация на началната страница
        $this->model_setting_event->deleteEventByCode($this->module . '_before_content_top');
        $this->model_setting_event->addEvent([
            'code' => $this->module . '_before_content_top',
            'description' => $descContentTopController,
            'trigger' => 'catalog/controller/common/content_top/before',
            'action' => $this->event_content_top . $uni_separator . 'init',
            'status' => true,
            'sort_order' => 0
        ]);

        $this->model_setting_event->deleteEventByCode($this->module . '_after_content_top_view');
        $this->model_setting_event->addEvent([
            'code' => $this->module . '_after_content_top_view',
            'description' => $descContentTopView,
            'trigger' => 'catalog/view/common/content_top/after',
            'action' => $this->event_content_top . $uni_separator . 'addHtml',
            'status' => true,
            'sort_order' => 0
        ]);

        $this->model_setting_event->deleteEventByCode($this->module . '_before_product_controller');
        $this->model_setting_event->addEvent([
            'code'        => $this->module . '_before_product_controller',
            'description' => $descController,
            'trigger'     => 'catalog/controller/product/product/before',
            'action'      => $this->event_product_controller . $uni_separator . 'init',
            'status'      => true,
            'sort_order'  => 0
        ]);

        $this->model_setting_event->deleteEventByCode($this->module . '_after_product_view');
        $this->model_setting_event->addEvent([
            'code'        => $this->module . '_after_product_view',
            'description' => $descView,
            'trigger'     => 'catalog/view/product/product/after',
            'action'      => $this->event_product_view . $uni_separator . 'init',
            'status'      => true,
            'sort_order'  => 0
        ]);

        $this->load->model('user/user_group');
        $groups = $this->model_user_user_group->getUserGroups();

        foreach ($groups as $group) {
            $this->model_user_user_group->addPermission($group['user_group_id'], 'access', $this->event_product_controller);
            $this->model_user_user_group->addPermission($group['user_group_id'], 'access', $this->event_product_view);
            $this->model_user_user_group->addPermission($group['user_group_id'], 'access', $this->event_content_top);
        }
    }

    protected function validate(): bool
    {
        if (!$this->user->hasPermission('modify', $this->path)) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }

    /**
     * @param array<int|string, mixed> $kop_map
     *
     * @return list<string>
     */
    protected function validateKopMapPost(array $kop_map): array
    {
        $this->load->model('extension/mt_uni_credit/module/unicredit');
        $allowed = $this->model_extension_mt_uni_credit_module_unicredit->getTopLevelCategoryIds();
        $allowedFlip = array_flip($allowed);
        $maxLen = \Opencart\Admin\Model\Extension\MtUniCredit\Module\Unicredit::KOP_MAX_LENGTH;
        $err = [];

        foreach ($kop_map as $cidKey => $fields) {
            $cid = (int) $cidKey;
            if ($cid <= 0 || !isset($allowedFlip[$cid]) || !is_array($fields)) {
                continue;
            }

            $kop = isset($fields['kop']) ? trim((string) $fields['kop']) : '';
            $promo = isset($fields['promo']) ? trim((string) $fields['promo']) : '';

            $kopLabel = sprintf($this->language->get('uni_kop_field_standard'), $cid);
            $promoLabel = sprintf($this->language->get('uni_kop_field_promo'), $cid);

            if ($kop !== '') {
                if (mb_strlen($kop) > $maxLen) {
                    $err[] = sprintf($this->language->get('error_kop_too_long'), $kopLabel, (string) $maxLen);
                } elseif (preg_match('/[\x00-\x1F\x7F]/u', $kop)) {
                    $err[] = sprintf($this->language->get('error_kop_control_chars'), $kopLabel);
                }
            }
            if ($promo !== '') {
                if (mb_strlen($promo) > $maxLen) {
                    $err[] = sprintf($this->language->get('error_kop_too_long'), $promoLabel, (string) $maxLen);
                } elseif (preg_match('/[\x00-\x1F\x7F]/u', $promo)) {
                    $err[] = sprintf($this->language->get('error_kop_control_chars'), $promoLabel);
                }
            }
        }

        return $err;
    }
}
