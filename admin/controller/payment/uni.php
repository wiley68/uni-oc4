<?php

namespace Opencart\Admin\Controller\Extension\MtUniCredit\Payment;

class Uni extends \Opencart\System\Engine\Controller
{
    private string $path = 'extension/mt_uni_credit/payment/uni';

    public function index(): void
    {
        $this->load->language($this->path);

        $this->document->setTitle($this->language->get('heading_title'));

        $user_token = 'user_token=' . $this->session->data['user_token'];

        $data['breadcrumbs'] = [];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', $user_token)
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', $user_token . '&type=payment')
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link($this->path, $user_token)
        ];

        $data['save'] = $this->url->link($this->path . '.save', $user_token);
        $data['back'] = $this->url->link('marketplace/extension', $user_token . '&type=payment');

        $data['payment_uni_order_status_id'] = (int) $this->config->get('payment_uni_order_status_id');

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['payment_uni_geo_zone_id'] = (int) $this->config->get('payment_uni_geo_zone_id');

        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $data['payment_uni_status'] = $this->config->get('payment_uni_status');
        $data['payment_uni_sort_order'] = $this->config->get('payment_uni_sort_order');

        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_edit'] = $this->language->get('text_edit');
        $data['text_all_zones'] = $this->language->get('text_all_zones');
        $data['entry_order_status'] = $this->language->get('entry_order_status');
        $data['entry_geo_zone'] = $this->language->get('entry_geo_zone');
        $data['entry_status'] = $this->language->get('entry_status');
        $data['entry_sort_order'] = $this->language->get('entry_sort_order');
        $data['button_save'] = $this->language->get('button_save');
        $data['button_back'] = $this->language->get('button_back');

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view($this->path, $data));
    }

    public function save(): void
    {
        $this->load->language($this->path);

        $json = [];

        if (!$this->user->hasPermission('modify', $this->path)) {
            $json['error'] = $this->language->get('error_permission');
        }

        if (!$json) {
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_uni', $this->request->post);
            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}
