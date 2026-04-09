<?php

namespace Opencart\Admin\Controller\Extension\MtUniCredit\Module;

/**
 * @property object $model_setting_setting
 */
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
        $data['back'] = $this->url->link('marketplace/extension', $user_token . '&type=module');

        $data[$this->module . '_status'] = $this->config->get($this->module . '_status');
        $data[$this->module . '_unicid'] = $this->config->get($this->module . '_unicid');
        $data[$this->module . '_reklama'] = $this->config->get($this->module . '_reklama');
        $data[$this->module . '_cart'] = $this->config->get($this->module . '_cart');
        $data[$this->module . '_debug'] = $this->config->get($this->module . '_debug');
        $data[$this->module . '_gap'] = $this->config->get($this->module . '_gap') == null ? 0 : $this->config->get($this->module . '_gap');

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view($this->path, $data));
    }

    public function install(): void
    {
        $this->load->model('setting/setting');

        $this->model_setting_setting->editSetting($this->module, [
            $this->module . '_status' => 1,
            $this->module . '_reklama' => 0,
            $this->module . '_cart' => 0,
            $this->module . '_debug' => 0
        ]);
    }

    public function uninstall(): void
    {
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
        $gap = filter_var($gap_raw, FILTER_VALIDATE_INT);

        if ($gap === false || $gap <= 0) {
            $errors[] = $this->language->get('error_gap_positive_int');
        }

        if (!$json && $errors) {
            $json['error'] = implode('<br/>', $errors);
        }

        if (!$json) {
            $this->init();
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting($this->module, $this->request->post);
            $json['success'] = 'Настройките са променени успешно!';
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    protected function init(): void
    {
        //$this->load->model($this->model);
    }

    protected function validate(): bool
    {
        if (!$this->user->hasPermission('modify', $this->path)) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }
}
