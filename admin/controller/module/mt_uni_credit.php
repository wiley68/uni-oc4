<?php

namespace Opencart\Admin\Controller\Extension\MtUniCredit\Module;

class MtUniCredit extends \Opencart\System\Engine\Controller
{
    private array $error = [];
    private $path = 'extension/mt_uni_credit/module/mt_uni_credit';
    private $model = 'extension/mt_uni_credit/module/unicredit';
    private $module = 'module_mt_uni_credit';

    public function index(): void
    {
        $user_token = 'user_token=' . $this->session->data['user_token'];

        $this->load->language($this->path);

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] === 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('module_mt_uni_credit', $this->request->post);

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
        $data['error_warning'] = $this->error['warning'] ?? '';

        $this->load->model('extension/mt_uni_credit/module/unicredit');
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
    }

    public function uninstall(): void
    {
        $this->load->model('extension/mt_uni_credit/module/unicredit');
        $this->model_extension_mt_uni_credit_module_unicredit->uninstall();

        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting('module_mt_uni_credit');
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

    protected function init(): void
    {
        $oc_version = \defined('VERSION') ? (string) \constant('VERSION') : '4.0.2.0';
        $jet_separator = \version_compare($oc_version, '4.0.2', '>=') ? '.' : '|';
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
