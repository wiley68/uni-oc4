<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Product;

/**
 * AJAX: валидация на опции и суми за калкулатора (формат, съвместим с uni_credit_products.js).
 */
class UniOption extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        $this->load->language('checkout/cart');

        $json = [];

        $productId = (int) ($this->request->post['product_id'] ?? 0);
        if ($productId <= 0) {
            $json['error']['option'] = ['0' => $this->language->get('error_product')];
            $this->outputJson($json);

            return;
        }

        $this->load->model('catalog/product');
        $productInfo = $this->model_catalog_product->getProduct($productId);
        if (!$productInfo) {
            $json['error']['option'] = ['0' => $this->language->get('error_product')];
            $this->outputJson($json);

            return;
        }

        if ($productInfo['master_id']) {
            $productId = (int) $productInfo['master_id'];
            $productInfo = $this->model_catalog_product->getProduct($productId);
        }

        $option = [];
        if (isset($this->request->post['option'])) {
            $option = array_filter((array) $this->request->post['option']);
        }

        if (isset($productInfo['override']['variant'])) {
            $override = $productInfo['override']['variant'];
        } else {
            $override = [];
        }
        foreach ($productInfo['variant'] ?? [] as $key => $value) {
            if (array_key_exists($key, $override)) {
                $option[$key] = $value;
            }
        }

        $productOptions = $this->model_catalog_product->getOptions($productId);
        $errorOption = [];

        foreach ($productOptions as $productOption) {
            if ($productOption['required'] && empty($option[$productOption['product_option_id']])) {
                $errorOption[(string) $productOption['product_option_id']] = sprintf($this->language->get('error_required'), $productOption['name']);
            } elseif (($productOption['type'] === 'text') && !empty($productOption['validation']) && !empty($option[$productOption['product_option_id']])) {
                $optVal = (string) $option[$productOption['product_option_id']];
                $regexp = html_entity_decode((string) $productOption['validation'], ENT_QUOTES, 'UTF-8');
                if (filter_var($optVal, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => $regexp]]) === false) {
                    $errorOption[(string) $productOption['product_option_id']] = sprintf($this->language->get('error_regex'), $productOption['name']);
                }
            }
        }

        if ($errorOption !== []) {
            $json['error']['option'] = $errorOption;
            $this->outputJson($json);

            return;
        }

        $subscriptionPlanId = (int) ($this->request->post['subscription_plan_id'] ?? 0);
        $subscriptions = $this->model_catalog_product->getSubscriptions($productId);
        if ($subscriptions && (!$subscriptionPlanId || !in_array($subscriptionPlanId, array_column($subscriptions, 'subscription_plan_id')))) {
            $json['error']['option'] = ['0' => $this->language->get('error_subscription')];
            $this->outputJson($json);

            return;
        }

        $optionresult = [];
        foreach ($productOptions as $productOption) {
            if (!in_array($productOption['type'], ['select', 'radio', 'checkbox'], true)) {
                continue;
            }
            if (!isset($option[$productOption['product_option_id']])) {
                continue;
            }
            $raw = $option[$productOption['product_option_id']];
            $values = $this->model_catalog_product->getOptionValues($productId, (int) $productOption['product_option_id']);
            $valueRows = [];
            foreach ($values as $ov) {
                $base = (float) $ov['price'];
                if ($ov['price_prefix'] === '-') {
                    $base = -$base;
                }
                $withTax = (float) $this->tax->calculate($base, (int) $productInfo['tax_class_id'], $this->config->get('config_tax'));
                $inCurrency = (float) $this->currency->convert($withTax, $this->config->get('config_currency'), $this->session->data['currency']);
                $valueRows[] = [
                    'product_option_value_id' => (int) $ov['product_option_value_id'],
                    'price'                     => $inCurrency,
                ];
            }

            if ($productOption['type'] === 'checkbox') {
                $check = is_array($raw) ? $raw : (($raw !== '' && $raw !== null) ? [$raw] : []);
            } else {
                $check = $raw;
            }

            $optionresult[] = [
                'product_option_id_check' => $check,
                'product_option_value'    => $valueRows,
            ];
        }

        $json['success'] = true;
        $json['optionresult'] = $optionresult;
        $this->outputJson($json);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function outputJson(array $json): void
    {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
