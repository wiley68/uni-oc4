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

        $data['action'] = $this->url->link($this->path, $user_token);
        $data['cancel'] = $this->url->link('marketplace/extension', $user_token . '&type=module');

        if (isset($this->request->post['module_mt_uni_credit_status'])) {
            $data['module_mt_uni_credit_status'] = $this->request->post['module_mt_uni_credit_status'];
        } else {
            $data['module_mt_uni_credit_status'] = $this->config->get('module_mt_uni_credit_status');
        }

        if (isset($this->request->post['module_mt_uni_credit_unicid'])) {
            $data['module_mt_uni_credit_unicid'] = $this->request->post['module_mt_uni_credit_unicid'];
        } else {
            $data['module_mt_uni_credit_unicid'] = $this->config->get('module_mt_uni_credit_unicid');
        }

        if (isset($this->request->post['module_mt_uni_credit_reklama'])) {
            $data['module_mt_uni_credit_reklama'] = $this->request->post['module_mt_uni_credit_reklama'];
        } else {
            $data['module_mt_uni_credit_reklama'] = $this->config->get('module_mt_uni_credit_reklama');
        }

        if (isset($this->request->post['module_mt_uni_credit_cart'])) {
            $data['module_mt_uni_credit_cart'] = $this->request->post['module_mt_uni_credit_cart'];
        } else {
            $data['module_mt_uni_credit_cart'] = $this->config->get('module_mt_uni_credit_cart');
        }

        if (isset($this->request->post['module_mt_uni_credit_debug'])) {
            $data['module_mt_uni_credit_debug'] = $this->request->post['module_mt_uni_credit_debug'];
        } else {
            $data['module_mt_uni_credit_debug'] = $this->config->get('module_mt_uni_credit_debug');
        }

        if (isset($this->request->post['module_mt_uni_credit_gap'])) {
            $data['module_mt_uni_credit_gap'] = $this->request->post['module_mt_uni_credit_gap'];
        } else {
            $data['module_mt_uni_credit_gap'] = $this->config->get('module_mt_uni_credit_gap') == null ? 0 : $this->config->get('module_mt_uni_credit_gap');
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view($this->path, $data));
    }

    public function install(): void
    {
        // Reserved for future install routines.
    }

    public function uninstall(): void
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting('module_mt_uni_credit');
    }

    protected function validate(): bool
    {
        if (!$this->user->hasPermission('modify', $this->path)) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }
}
