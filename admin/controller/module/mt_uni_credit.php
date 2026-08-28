<?php

namespace Opencart\Admin\Controller\Extension\MtUniCredit\Module;

use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartCompatibility;

/**
 * Admin module shell — configuration, install/uninstall, deployment + bank-data refresh.
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

        $token = 'user_token=' . $this->session->data['user_token'];
        $data['save'] = $this->url->link(OpenCartCompatibility::adminRoute($this->route, 'save'), $token);
        $data['refresh_bank_data'] = $this->url->link(OpenCartCompatibility::adminRoute($this->route, 'refreshBankData'), $token);
        $data['back'] = $this->url->link('marketplace/extension', $token . '&type=module');

        $statusKey = $this->settingCode . '_status';
        $unicidKey = ModuleCredentialsRepository::UNICID_SETTING;
        $data[$statusKey] = (int) $this->config->get($statusKey);
        $data[$unicidKey] = (string) $this->config->get($unicidKey);

        $this->load->model('extension/mt_uni_credit/module/mt_uni_credit');
        $health = $this->model_extension_mt_uni_credit_module_mt_uni_credit->getHealthSummary();
        $health['environment_status_label'] = $this->healthStatusLabel((string) $health['environment_status']);
        $health['control_panel_status_label'] = $this->healthStatusLabel((string) $health['control_panel_status']);
        $health['secrets_status_label'] = $this->healthStatusLabel((string) $health['secrets_status']);
        $health['certificate_status_label'] = $this->healthStatusLabel((string) $health['certificate_status']);
        $health['private_key_status_label'] = $this->healthStatusLabel((string) $health['private_key_status']);
        $health['certificate_validity_label'] = $this->healthStatusLabel((string) $health['certificate_validity']);
        $health['certificate_key_match_label'] = $this->healthStatusLabel((string) $health['certificate_key_match']);
        $health['auth_state_label'] = $this->authStateLabel((string) ($health['auth_state'] ?? 'missing_credentials'));
        $data['health'] = $health;

        $data['success'] = '';
        if (isset($this->session->data['success'])) {
            $data['success'] = (string) $this->session->data['success'];
            unset($this->session->data['success']);
        }
        $data['error_warning'] = '';
        if (isset($this->session->data['error'])) {
            $data['error_warning'] = (string) $this->session->data['error'];
            unset($this->session->data['error']);
        }

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
        $data['text_bank_actions'] = $this->language->get('text_bank_actions');
        $data['text_bank_actions_help'] = $this->language->get('text_bank_actions_help');
        $data['text_cp_auth_state'] = $this->language->get('text_cp_auth_state');
        $data['text_cp_token_expires'] = $this->language->get('text_cp_token_expires');
        $data['text_cp_cache_present'] = $this->language->get('text_cp_cache_present');
        $data['text_cp_cache_fetched_at'] = $this->language->get('text_cp_cache_fetched_at');
        $data['text_cp_cache_expires_at'] = $this->language->get('text_cp_cache_expires_at');
        $data['text_cp_cache_fresh'] = $this->language->get('text_cp_cache_fresh');
        $data['entry_unicid'] = $this->language->get('entry_unicid');
        $data['entry_secret'] = $this->language->get('entry_secret');
        $data['help_secret'] = $this->language->get('help_secret');
        $data['help_journal_unavailable'] = $this->language->get('help_journal_unavailable');
        $data['has_secret'] = (bool) ($health['secret_configured'] ?? false);
        $data['secret_readable'] = (bool) ($health['secret_readable'] ?? true);
        $data['text_secret_configured'] = $this->language->get('text_secret_configured');
        $data['text_secret_keep_current'] = $this->language->get('text_secret_keep_current');
        $data['button_save'] = $this->language->get('button_save');
        $data['button_back'] = $this->language->get('button_back');
        $data['button_refresh_bank_data'] = $this->language->get('button_refresh_bank_data');
        $data['button_download_journal'] = $this->language->get('button_download_journal');

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

    private function authStateLabel(string $state): string
    {
        $key = 'text_auth_state_' . $state;
        $label = $this->language->get($key);

        return is_string($label) && $label !== $key ? $label : $state;
    }

    public function save(): void
    {
        $this->load->language($this->route);

        $json = [];
        $payload = [];
        $plainSecret = null;
        $secretFieldSubmitted = false;

        if (!$this->user->hasPermission('modify', $this->route)) {
            $json['error'] = $this->language->get('error_permission');
        }

        if (!$json) {
            $this->load->model('extension/mt_uni_credit/module/mt_uni_credit');

            $allowed = $this->model_extension_mt_uni_credit_module_mt_uni_credit->getDefaultSettings();
            $payload = [];
            foreach (array_keys($allowed) as $key) {
                if (!isset($this->request->post[$key])) {
                    continue;
                }
                if ($key === ModuleCredentialsRepository::UNICID_SETTING) {
                    $payload[$key] = trim((string) $this->request->post[$key]);
                    continue;
                }
                $payload[$key] = (int) $this->request->post[$key];
            }

            $secretKey = ModuleCredentialsRepository::SECRET_SETTING;
            $secretFieldSubmitted = array_key_exists($secretKey, $this->request->post);
            $plainSecret = $secretFieldSubmitted ? trim((string) $this->request->post[$secretKey]) : null;

            if (trim((string) ($this->request->post[ModuleCredentialsRepository::UNICID_SETTING] ?? '')) === '') {
                $json['error'] = $this->language->get('error_unicid_required');
            }

            if (!$json && $secretFieldSubmitted && $plainSecret === '') {
                $health = $this->model_extension_mt_uni_credit_module_mt_uni_credit->getCpHealthSummary();
                if (empty($health['secret_configured'])) {
                    $json['error'] = $this->language->get('error_secret_required');
                }
            }
        }

        if (!$json) {
            $this->model_extension_mt_uni_credit_module_mt_uni_credit->saveModuleSettings(
                $payload,
                $plainSecret,
                $secretFieldSubmitted
            );
            $this->model_extension_mt_uni_credit_module_mt_uni_credit->syncEvents();

            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Operator action: refresh bank/shop configuration (transparent CP auth).
     */
    public function refreshBankData(): void
    {
        $this->load->language($this->route);
        $token = 'user_token=' . $this->session->data['user_token'];
        $redirect = $this->url->link($this->route, $token);

        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->redirect($redirect);

            return;
        }

        if (!$this->user->hasPermission('modify', $this->route)) {
            $this->session->data['error'] = $this->language->get('error_permission');
            $this->response->redirect($redirect);

            return;
        }

        $this->load->model('extension/mt_uni_credit/module/mt_uni_credit');
        $result = $this->model_extension_mt_uni_credit_module_mt_uni_credit->refreshBankData();

        if (isset($result['success'])) {
            $message = $this->language->get('text_bank_data_refreshed');
            if (!empty($result['fetched_at'])) {
                $message .= ' ' . sprintf(
                    $this->language->get('text_bank_data_refreshed_at'),
                    (string) $result['fetched_at']
                );
            }
            if (isset($result['scheme_count']) && is_int($result['scheme_count'])) {
                $message .= ' ' . sprintf(
                    $this->language->get('text_bank_data_scheme_count'),
                    $result['scheme_count']
                );
            }
            $this->session->data['success'] = trim($message);
        } elseif (isset($result['error'])) {
            $errorKey = 'error_bank_' . $result['error'];
            $label = $this->language->get($errorKey);
            $this->session->data['error'] = ($label !== $errorKey)
                ? $label
                : $this->language->get('error_bank_request_failed');
        } else {
            $this->session->data['error'] = $this->language->get('error_bank_request_failed');
        }

        $this->response->redirect($redirect);
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
