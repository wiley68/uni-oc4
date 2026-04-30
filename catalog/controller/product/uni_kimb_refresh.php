<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Product;

/**
 * AJAX: при промяна на редова сума — ако промо/стандарт KOP се различава от този при единична цена,
 * презарежда KIMB от банката и връща обновени скрити полета за uni_calculate.
 */
class UniKimbRefresh extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');

        $productId = (int) ($this->request->post['product_id'] ?? 0);
        $lineTotal = (float) str_replace(',', '.', (string) ($this->request->post['line_total'] ?? '0'));

        $this->load->model('extension/mt_uni_credit/module/product_panel');
        $out = $this->model_extension_mt_uni_credit_module_product_panel->refreshKimbHiddenFieldsForProductLine(
            $productId,
            $lineTotal
        );

        $this->response->setOutput(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
