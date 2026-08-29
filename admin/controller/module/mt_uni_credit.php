<?php

namespace Opencart\Admin\Controller\Extension\MtUniCredit\Module;

use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleLocalSettings;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartCompatibility;

/**
 * Admin module configuration — local settings + bank-data refresh.
 */
class MtUniCredit extends \Opencart\System\Engine\Controller
{
    private string $route = ModuleConstants::ADMIN_ROUTE;

    private string $settingCode = ModuleConstants::MODULE_SETTING_CODE;

    public function index(): void
    {
        $this->load->language($this->route);
        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('extension/mt_uni_credit/module/mt_uni_credit');
        // Heal oc_event gaps after file deploys that add EventRegistry codes without Save.
        $this->model_extension_mt_uni_credit_module_mt_uni_credit->ensureEventsSynchronized();

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

        $defaults = $this->model_extension_mt_uni_credit_module_mt_uni_credit->getDefaultSettings();
        foreach ($defaults as $key => $default) {
            $stored = $this->config->get($key);
            if ($key === ModuleCredentialsRepository::UNICID_SETTING) {
                $data[$key] = (string) ($stored ?? $default);
                continue;
            }
            if ($key === ModuleLocalSettings::PRODUCT_BUTTON_ACTION) {
                $data[$key] = ModuleLocalSettings::normalizeProductButtonAction((string) ($stored ?? $default));
                continue;
            }
            if ($key === ModuleLocalSettings::BUTTON_TOP_SPACING) {
                $data[$key] = ModuleLocalSettings::normalizeButtonTopSpacing($stored ?? $default);
                continue;
            }
            if ($key === ModuleConstants::AWAITING_FINANCING_ORDER_STATUS_SETTING) {
                $data[$key] = (int) ($stored ?? $default);
                continue;
            }
            $data[$key] = (int) ($stored ?? $default);
        }

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['payment_order_status_id'] = (int) $this->config->get('payment_mt_uni_credit_order_status_id');

        $health = $this->model_extension_mt_uni_credit_module_mt_uni_credit->getCpHealthSummary();
        $data['has_secret'] = (bool) ($health['secret_configured'] ?? false);

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

        $data['entry_status'] = $this->language->get('entry_status');
        $data['entry_unicid'] = $this->language->get('entry_unicid');
        $data['entry_secret'] = $this->language->get('entry_secret');
        $data['entry_advertising_enabled'] = $this->language->get('entry_advertising_enabled');
        $data['entry_debug_enabled'] = $this->language->get('entry_debug_enabled');
        $data['entry_product_button_action'] = $this->language->get('entry_product_button_action');
        $data['entry_button_top_spacing'] = $this->language->get('entry_button_top_spacing');
        $data['entry_awaiting_financing_order_status'] = $this->language->get('entry_awaiting_financing_order_status');
        $data['help_unicid'] = $this->language->get('help_unicid');
        $data['help_secret'] = $this->language->get('help_secret');
        $data['help_advertising_enabled'] = $this->language->get('help_advertising_enabled');
        $data['help_debug_enabled'] = $this->language->get('help_debug_enabled');
        $data['help_product_button_action'] = $this->language->get('help_product_button_action');
        $data['help_button_top_spacing'] = $this->language->get('help_button_top_spacing');
        $data['help_awaiting_financing_order_status'] = $this->language->get('help_awaiting_financing_order_status');
        $data['help_journal_unavailable'] = $this->language->get('help_journal_unavailable');
        $data['text_secret_keep_current'] = $this->language->get('text_secret_keep_current');
        $data['text_awaiting_use_payment'] = $this->language->get('text_awaiting_use_payment');
        $data['button_save'] = $this->language->get('button_save');
        $data['button_back'] = $this->language->get('button_back');
        $data['button_refresh_bank_data'] = $this->language->get('button_refresh_bank_data');
        $data['button_download_journal'] = $this->language->get('button_download_journal');
        $data['product_button_actions'] = [
            [
                'value' => ModuleLocalSettings::BUTTON_ACTION_ADD_TO_CART,
                'label' => $this->language->get('text_product_button_add_to_cart'),
            ],
            [
                'value' => ModuleLocalSettings::BUTTON_ACTION_BUY,
                'label' => $this->language->get('text_product_button_buy'),
            ],
        ];

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view($this->route, $data));
    }

    public function save(): void
    {
        $this->load->language($this->route);

        $json = [];

        if (!$this->user->hasPermission('modify', $this->route)) {
            $json['error'] = $this->language->get('error_permission');
        }

        if (!$json) {
            $this->load->model('extension/mt_uni_credit/module/mt_uni_credit');
            $allowed = $this->model_extension_mt_uni_credit_module_mt_uni_credit->getDefaultSettings();
            $payload = [];
            foreach (array_keys($allowed) as $key) {
                if (!array_key_exists($key, $this->request->post)) {
                    continue;
                }
                if ($key === ModuleCredentialsRepository::UNICID_SETTING) {
                    $payload[$key] = trim((string) $this->request->post[$key]);
                    continue;
                }
                if ($key === ModuleLocalSettings::PRODUCT_BUTTON_ACTION) {
                    $payload[$key] = ModuleLocalSettings::normalizeProductButtonAction(
                        (string) $this->request->post[$key]
                    );
                    continue;
                }
                if ($key === ModuleLocalSettings::BUTTON_TOP_SPACING) {
                    $payload[$key] = ModuleLocalSettings::normalizeButtonTopSpacing($this->request->post[$key]);
                    continue;
                }
                if ($key === ModuleConstants::AWAITING_FINANCING_ORDER_STATUS_SETTING) {
                    $payload[$key] = max(0, (int) $this->request->post[$key]);
                    continue;
                }
                $payload[$key] = ModuleLocalSettings::normalizeFlag($this->request->post[$key]);
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

            if (!$json) {
                $this->model_extension_mt_uni_credit_module_mt_uni_credit->saveModuleSettings(
                    $payload,
                    $plainSecret,
                    $secretFieldSubmitted
                );
                $this->model_extension_mt_uni_credit_module_mt_uni_credit->syncEvents();
                $json['success'] = $this->language->get('text_success');
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

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
