<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Api;

/**
 * Публичен endpoint за опресняване на кеша (същият pipeline като админ бутона за refresh).
 * Маршрут: extension/mt_uni_credit/api/refreshcache.
 *
 * Удостоверяване (като PrestaShop unipayment/refreshcache):
 * - POST със суров JSON тяло; заглавки X-UniPayment-Timestamp и X-UniPayment-Signature
 * - Подпис: hash_hmac('sha256', $timestamp . '.' . $rawBody, $unicid) където $unicid е настроеният UNICID на магазина
 * - Тяло: {"unicid":"...","refresh":true} — unicid трябва да съвпада с настройката на модула
 *
 * Алтернатива: параметър/заглавка token (module_mt_uni_credit_cache_api_token) за ръчни извиквания.
 */
class Refreshcache extends \Opencart\System\Engine\Controller
{
    private const TIMESTAMP_SKEW_SECONDS = 300;

    private string $module = 'module_mt_uni_credit';

    public function index(): void
    {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $proto = (string) ($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1');

        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody)) {
            $rawBody = '';
        }

        $unicidConfig = trim((string) ($this->config->get($this->module . '_unicid') ?? ''));

        $tsHeader = $this->headerFromRequest('X-UniPayment-Timestamp');
        $sigHeader = $this->headerFromRequest('X-UniPayment-Signature');
        $hasBankHeaders = ($tsHeader !== '' && $sigHeader !== '');

        if ($hasBankHeaders) {
            if ($unicidConfig === '') {
                $this->response->addHeader($proto . ' 503 Service Unavailable');
                $this->response->setOutput(json_encode([
                    'result' => 'error',
                    'errors' => ['unicid_not_configured'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return;
            }

            if ($rawBody === '') {
                $this->response->addHeader($proto . ' 403 Forbidden');
                $this->response->setOutput(json_encode([
                    'result' => 'error',
                    'errors' => ['empty_body'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return;
            }

            if (!ctype_digit($tsHeader)) {
                $this->response->addHeader($proto . ' 403 Forbidden');
                $this->response->setOutput(json_encode([
                    'result' => 'error',
                    'errors' => ['invalid_timestamp'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return;
            }

            $tsInt = (int) $tsHeader;
            if ($tsInt <= 0 || abs(time() - $tsInt) > self::TIMESTAMP_SKEW_SECONDS) {
                $this->response->addHeader($proto . ' 403 Forbidden');
                $this->response->setOutput(json_encode([
                    'result' => 'error',
                    'errors' => ['timestamp_out_of_range'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return;
            }

            $expectedSig = hash_hmac('sha256', $tsHeader . '.' . $rawBody, $unicidConfig);
            if (!hash_equals($expectedSig, $sigHeader)) {
                $this->response->addHeader($proto . ' 403 Forbidden');
                $this->response->setOutput(json_encode([
                    'result' => 'error',
                    'errors' => ['invalid_signature'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return;
            }

            $decoded = json_decode($rawBody, true);
            if (!is_array($decoded)) {
                $this->response->addHeader($proto . ' 403 Forbidden');
                $this->response->setOutput(json_encode([
                    'result' => 'error',
                    'errors' => ['invalid_json'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return;
            }

            $bodyUnicid = trim((string) ($decoded['unicid'] ?? ''));
            if ($bodyUnicid === '' || !hash_equals($unicidConfig, $bodyUnicid)) {
                $this->response->addHeader($proto . ' 403 Forbidden');
                $this->response->setOutput(json_encode([
                    'result' => 'error',
                    'errors' => ['unicid_mismatch'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return;
            }

            $refresh = $decoded['refresh'] ?? null;
            if ($refresh !== true && $refresh !== 1 && $refresh !== '1') {
                $this->response->addHeader($proto . ' 403 Forbidden');
                $this->response->setOutput(json_encode([
                    'result' => 'error',
                    'errors' => ['refresh_not_true'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return;
            }

            $this->runPipelineAndRespond($proto, $unicidConfig);

            return;
        }

        // Ръчен режим: токен от настройките (GET/POST поле или X-Mt-Uni-Credit-Token).
        $expectedTok = (string) ($this->config->get($this->module . '_cache_api_token') ?? '');
        $givenTok = (string) ($this->request->get['token'] ?? $this->request->post['token'] ?? '');
        if ($givenTok === '' && $rawBody !== '') {
            $j = json_decode($rawBody, true);
            if (is_array($j) && isset($j['token'])) {
                $givenTok = trim((string) $j['token']);
            }
        }
        if ($givenTok === '' && !empty($this->request->server['HTTP_X_MT_UNI_CREDIT_TOKEN'])) {
            $givenTok = (string) $this->request->server['HTTP_X_MT_UNI_CREDIT_TOKEN'];
        }

        if ($expectedTok === '' || $givenTok === '' || !hash_equals($expectedTok, $givenTok)) {
            $this->response->addHeader($proto . ' 403 Forbidden');
            $this->response->setOutput(json_encode([
                'result' => 'error',
                'errors' => ['invalid_or_missing_token'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        if ($unicidConfig === '') {
            $this->response->addHeader($proto . ' 503 Service Unavailable');
            $this->response->setOutput(json_encode([
                'result' => 'error',
                'errors' => ['unicid_not_configured'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->runPipelineAndRespond($proto, $unicidConfig);
    }

    private function runPipelineAndRespond(string $proto, string $unicid): void
    {
        require_once \DIR_EXTENSION . 'mt_uni_credit/admin/model/module/unicredit_config.php';
        require_once \DIR_EXTENSION . 'mt_uni_credit/admin/model/module/unicredit.php';

        $model = new \Opencart\Admin\Model\Extension\MtUniCredit\Module\Unicredit($this->registry);
        $pipeline = $model->runBankPanelRefreshPipeline($unicid);

        $out = [
            'result' => $pipeline['result'],
            'kop_refreshed' => $pipeline['kop_refreshed'],
            'params_refreshed' => $pipeline['params_refreshed'],
            'coeff_purged' => $pipeline['coeff_purged'],
            'errors' => [],
        ];

        if ($pipeline['result'] === 'partial') {
            $out['errors'][] = 'bank_params_failed';
        }

        $this->response->setOutput(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function headerFromRequest(string $name): string
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
        $v = $this->request->server[$key] ?? null;
        if (is_string($v) && $v !== '') {
            return trim($v);
        }

        if (\function_exists('getallheaders')) {
            foreach (getallheaders() as $hName => $hVal) {
                if (strcasecmp((string) $hName, $name) === 0) {
                    return trim((string) $hVal);
                }
            }
        }

        return '';
    }
}
