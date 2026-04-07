<?php
/**
 * @property object $model_setting_setting
 */
class ControllerModuleMtUniCredit extends Controller
{
    private array $error = [];

    public function index(): void
    {
        $this->load->language('module/mt_uni_credit');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] === 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('module_mt_uni_credit', $this->request->post);

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module'));
        }

        $data['error_warning'] = $this->error['warning'] ?? '';
        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_edit'] = $this->language->get('text_edit');
        $data['text_home'] = $this->language->get('text_home');
        $data['text_extension'] = $this->language->get('text_extension');
        $data['text_enabled'] = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');
        $data['entry_status'] = $this->language->get('entry_status');
        $data['entry_sort_order'] = $this->language->get('entry_sort_order');
        $data['button_save'] = $this->language->get('button_save');
        $data['button_cancel'] = $this->language->get('button_cancel');

        $data['breadcrumbs'] = [];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module')
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('module/mt_uni_credit', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['action'] = $this->url->link('module/mt_uni_credit', 'user_token=' . $this->session->data['user_token']);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');

        if (isset($this->request->post['module_mt_uni_credit_status'])) {
            $data['module_mt_uni_credit_status'] = $this->request->post['module_mt_uni_credit_status'];
        } else {
            $data['module_mt_uni_credit_status'] = $this->config->get('module_mt_uni_credit_status');
        }

        if (isset($this->request->post['module_mt_uni_credit_sort_order'])) {
            $data['module_mt_uni_credit_sort_order'] = $this->request->post['module_mt_uni_credit_sort_order'];
        } else {
            $data['module_mt_uni_credit_sort_order'] = $this->config->get('module_mt_uni_credit_sort_order');
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('module/mt_uni_credit', $data));
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
        if (!$this->user->hasPermission('modify', 'module/mt_uni_credit')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }
}
