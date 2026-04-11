<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Product;

require_once \DIR_EXTENSION . 'mt_uni_credit/system/library/uni_financial_rate.php';

/**
 * AJAX: месечни вноски и ГПР по срокове (аналог на PS getproduct.php).
 */
class UniCalculate extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        $json = [];
        $json['success'] = 'unsuccess';

        $uniPrice = (float) str_replace(',', '.', (string) ($this->request->post['uni_price'] ?? '0'));

        $months = [3, 4, 5, 6, 9, 10, 12, 18, 24, 30, 36];
        $kimb = [];
        foreach ($months as $m) {
            $kimb[$m] = (float) str_replace(',', '.', (string) ($this->request->post['uni_param_kimb_' . $m] ?? '0'));
        }

        if ($uniPrice > 0) {
            foreach ($months as $m) {
                $k = $kimb[$m];
                $mes = number_format($uniPrice * $k, 2, '.', '');
                $json['uni_mesecna_' . $m] = $mes;
                $uniGprm = (\MtUniCreditFinancialRate::periodicRate((float) $m, -1 * (float) $mes, $uniPrice) * (float) $m) / ((float) $m / 12.0);
                $json['uni_gpr_' . $m] = \MtUniCreditFinancialRate::formatGprPercentForDisplay((pow(1 + (float) $uniGprm / 12, 12) - 1) * 100);
            }
            $json['success'] = 'success';
        }

        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
