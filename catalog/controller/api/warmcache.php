<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Api;

require_once \DIR_EXTENSION . 'mt_uni_credit/admin/model/module/unicredit_config.php';

use Opencart\Admin\Model\Extension\MtUniCredit\Module\UnicreditConfig;

/**
 * Public AJAX endpoint за client-side fallback warm-up на банковия кеш.
 * Извиква се от продуктовия шаблон (mt_uni_credit_product.twig) САМО когато
 * `fastcgi_finish_request` не е достъпен и данните са били сервирани stale.
 *
 * Защити:
 * - Не връща данни от банката — само статус на изпълнението.
 * - Защитен с DB lock в product_panel::performBackgroundRefresh (1 фонов refresh / unicid / 30s).
 * - Не приема URL, кредитни данни или KOP — всичко се чете от настройките на магазина и от каталога.
 *
 * Маршрут: extension/mt_uni_credit/api/warmcache
 */
class Warmcache extends \Opencart\System\Engine\Controller
{
    private string $module = UnicreditConfig::MODULE_SETTING_KEY;

    public function index(): void
    {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');

        if (!$this->config->get($this->module . '_status')) {
            $this->response->setOutput(json_encode(['ok' => false, 'reason' => 'module_disabled']));

            return;
        }

        $unicid = trim((string) $this->config->get($this->module . '_unicid'));
        if ($unicid === '') {
            $this->response->setOutput(json_encode(['ok' => false, 'reason' => 'unicid_not_configured']));

            return;
        }

        $productId = (int) ($this->request->post['product_id'] ?? 0);
        $lineTotal = (float) str_replace(',', '.', (string) ($this->request->post['line_total'] ?? '0'));
        $userAgent = isset($this->request->server['HTTP_USER_AGENT']) ? (string) $this->request->server['HTTP_USER_AGENT'] : '';

        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $this->load->model('extension/mt_uni_credit/module/product_panel');
        $result = $this->model_extension_mt_uni_credit_module_product_panel->performBackgroundRefresh(
            $productId,
            $userAgent,
            $lineTotal
        );

        $this->response->setOutput(json_encode([
            'ok'               => true,
            'ran'              => (bool) ($result['ran'] ?? false),
            'refreshed_params' => (bool) ($result['refreshed_params'] ?? false),
            'refreshed_kimb'   => (bool) ($result['refreshed_kimb'] ?? false),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
