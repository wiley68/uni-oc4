<?php

namespace Opencart\Admin\Controller\Extension\MtUniCredit\Module;

use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartCompatibility;

/**
 * Admin module shell — configuration, install/uninstall, deployment health.
 */
class MtUniCredit extends \Opencart\System\Engine\Controller
{
    private string $route = ModuleConstants::ADMIN_ROUTE;

    private string $settingCode = ModuleConstants::MODULE_SETTING_CODE;

    public function index(): void
    {
        $this->load->language($this->route);

        $this->document->setTitle($this->language->get('heading_title'));

        $data['breadcrumbs'] = [];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token']),
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module'),
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link($this->route, 'user_token=' . $this->session->data['user_token']),
        ];

        $data['save'] = $this->url->link(
            OpenCartCompatibility::adminRoute($this->route, 'save'),
            'user_token=' . $this->session->data['user_token']
        );
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');

        $statusKey = $this->settingCode . '_status';
        $data[$statusKey] = (int) $this->config->get($statusKey);

        $this->load->model('extension/mt_uni_credit/module/mt_uni_credit');
        $health = $this->model_extension_mt_uni_credit_module_mt_uni_credit->getHealthSummary();
        $health['environment_status_label'] = $this->healthStatusLabel((string) $health['environment_status']);
        $health['control_panel_status_label'] = $this->healthStatusLabel((string) $health['control_panel_status']);
        $health['secrets_status_label'] = $this->healthStatusLabel((string) $health['secrets_status']);
        $health['certificate_status_label'] = $this->healthStatusLabel((string) $health['certificate_status']);
        $health['private_key_status_label'] = $this->healthStatusLabel((string) $health['private_key_status']);
        $health['certificate_validity_label'] = $this->healthStatusLabel((string) $health['certificate_validity']);
        $health['certificate_key_match_label'] = $this->healthStatusLabel((string) $health['certificate_key_match']);
        $data['health'] = $health;

        $data['text_health_placeholder'] = $this->language->get('text_health_placeholder');
        $data['text_cp_endpoint'] = $this->language->get('text_cp_endpoint');
        $data['text_environment_config'] = $this->language->get('text_environment_config');
        $data['text_secret_config'] = $this->language->get('text_secret_config');
        $data['text_certificate'] = $this->language->get('text_certificate');
        $data['text_private_key'] = $this->language->get('text_private_key');
        $data['text_certificate_validity'] = $this->language->get('text_certificate_validity');
        $data['text_certificate_not_after'] = $this->language->get('text_certificate_not_after');
        $data['text_certificate_key_match'] = $this->language->get('text_certificate_key_match');
        $data['text_deployment_ready'] = $this->language->get('text_deployment_ready');
        $data['text_yes'] = $this->language->get('text_yes');
        $data['text_no'] = $this->language->get('text_no');
        $data['text_health_status_missing'] = $this->language->get('text_health_status_missing');

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view($this->route, $data));
    }

    private function healthStatusLabel(string $status): string
    {
        $key = 'text_health_status_' . $status;
        $label = $this->language->get($key);

        return is_string($label) && $label !== $key ? $label : $status;
    }

    public function save(): void
    {
        $this->load->language($this->route);

        $json = [];

        if (!$this->user->hasPermission('modify', $this->route)) {
            $json['error'] = $this->language->get('error_permission');
        }

        if (!$json) {
            $this->load->model('setting/setting');
            $this->load->model('extension/mt_uni_credit/module/mt_uni_credit');

            $allowed = $this->model_extension_mt_uni_credit_module_mt_uni_credit->getDefaultSettings();
            $payload = [];
            foreach (array_keys($allowed) as $key) {
                if (isset($this->request->post[$key])) {
                    $payload[$key] = (int) $this->request->post[$key];
                }
            }

            $this->model_setting_setting->editSetting($this->settingCode, $payload);
            $this->model_extension_mt_uni_credit_module_mt_uni_credit->syncEvents();

            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function install(): void
    {
        if (!$this->user->hasPermission('modify', $this->route)) {
            return;
        }

        $this->load->model('extension/mt_uni_credit/module/mt_uni_credit');
        $this->model_extension_mt_uni_credit_module_mt_uni_credit->install();
    }

    public function uninstall(): void
    {
        if (!$this->user->hasPermission('modify', $this->route)) {
            return;
        }

        $this->load->model('extension/mt_uni_credit/module/mt_uni_credit');
        $this->model_extension_mt_uni_credit_module_mt_uni_credit->uninstall();
    }
}
