<?php

namespace Opencart\Catalog\Model\Extension\MtUniCredit;

require_once \DIR_EXTENSION . 'mt_uni_credit/admin/model/module/unicredit_config.php';

use Opencart\Admin\Model\Extension\MtUniCredit\Module\UnicreditConfig;
use Opencart\System\Engine\Model;

/**
 * След потвърждение на поръчка: addorders + sucfOnlineSessionStart и данни за редирект към банката (OC3 логика).
 */
class UniBankRedirect extends Model
{
    private const EUR_BGN_RATE = 1.95583;

    private string $module = 'module_mt_uni_credit';

    /**
     * @param array<string, mixed> $handoff Полета от payment confirm (сесия)
     *
     * @return array{ok: bool, redirect?: string, data?: array<string, mixed>}
     */
    public function processHandoff(int $orderId, array $handoff): array
    {
        $this->load->language('extension/mt_uni_credit/uni_bank');

        if ($orderId <= 0) {
            return ['ok' => false, 'redirect' => ''];
        }

        $this->load->model('checkout/order');
        $this->load->model('extension/mt_uni_credit/module/product_panel');

        $order = $this->model_checkout_order->getOrder($orderId);
        if ($order === []) {
            return ['ok' => false, 'redirect' => ''];
        }

        $paramsuni = $this->model_extension_mt_uni_credit_module_product_panel->getUniParamsFromBankCached(false);
        if (!is_array($paramsuni) || (($paramsuni['uni_status'] ?? '') !== 'Yes')) {
            return ['ok' => false, 'redirect' => ''];
        }

        $uniLiveUrl = rtrim(UnicreditConfig::LIVE_URL, '/');
        $unicid = trim((string) $this->config->get($this->module . '_unicid'));

        $uniMesecna = (float) ($handoff['uni_mesecna'] ?? 0);
        $uniGpr = (float) ($handoff['uni_gpr'] ?? 0);
        $uniGlp = (float) ($handoff['uni_glp'] ?? 0);
        $uniVnoski = (int) ($handoff['uni_vnoski'] ?? 0);
        $uniParva = (float) ($handoff['uni_parva'] ?? 0);
        $uniKop = (string) ($handoff['uni_kop'] ?? '');
        $uniFnameGet = (string) ($handoff['uni_fname'] ?? '');
        $uniLnameGet = (string) ($handoff['uni_lname'] ?? '');
        $uniPhoneGet = (string) ($handoff['uni_phone'] ?? '');
        $uniPhone2Get = (string) ($handoff['uni_phone2'] ?? '');
        $uniEmailGet = (string) ($handoff['uni_email'] ?? '');
        $uniEgnGet = (string) ($handoff['uni_egn'] ?? '');
        $uniDescriptionGet = (string) ($handoff['uni_description'] ?? '');

        $uniFname = (string) ($order['firstname'] ?? '');
        $uniLname = (string) ($order['lastname'] ?? '');
        $uniPhone = (string) ($order['telephone'] ?? '');
        $uniEmail = (string) ($order['email'] ?? '');

        // Формата в чекаута изпраща тези полета в handoff; поръчката може да има празни firstname/telephone
        // (данните са само в коментар [UniCredit]). За банката ползваме попълненото във формата, иначе — от ордера.
        $uniFnameSend = $this->uniCustomerFieldPreferHandoff($uniFnameGet, $uniFname);
        $uniLnameSend = $this->uniCustomerFieldPreferHandoff($uniLnameGet, $uniLname);
        $uniPhoneSend = $this->uniCustomerFieldPreferHandoff($uniPhoneGet, $uniPhone);
        $uniEmailSend = $this->uniCustomerFieldPreferHandoff($uniEmailGet, $uniEmail);
        $uniBillingAddress = (string) ($order['payment_address_1'] ?? '');
        $uniBillingCity = (string) ($order['payment_city'] ?? '');
        $uniBillingCounty = (string) ($order['payment_zone'] ?? '');
        $uniShippingAddress = (string) ($order['shipping_address_1'] ?? '');
        $uniShippingCity = (string) ($order['shipping_city'] ?? '');
        $uniShippingCounty = (string) ($order['shipping_zone'] ?? '');

        $uniTotal = (float) ($order['total'] ?? 0);
        $currencyCode = (string) ($this->session->data['currency'] ?? 'BGN');
        $uniCurrencyCodeSend = 'BGN';
        $uniEur = (int) ($paramsuni['uni_eur'] ?? 0);

        switch ($uniEur) {
            case 0:
                break;
            case 1:
                $uniCurrencyCodeSend = 'BGN';
                if ($currencyCode === 'EUR') {
                    $uniTotal = (float) number_format($uniTotal * self::EUR_BGN_RATE, 2, '.', '');
                }
                break;
            case 2:
            case 3:
                $uniCurrencyCodeSend = 'EUR';
                if ($currencyCode === 'BGN') {
                    $uniTotal = (float) number_format($uniTotal / self::EUR_BGN_RATE, 2, '.', '');
                }
                break;
        }

        $this->load->model('catalog/product');
        $products = $this->model_checkout_order->getProducts($orderId);
        $uniItems = [];
        $ident = 0;

        foreach ($products as $product) {
            $productsCat = $this->model_catalog_product->getCategories((int) $product['product_id']);
            $uniItems[$ident] = [
                'name' => $this->sanitizeUniString((string) $product['name']),
                'code' => (string) $product['product_id'],
                'type' => '0',
                'count' => (int) $product['quantity'],
                'singlePrice' => 0.0,
            ];
            foreach ($productsCat as $productCat) {
                $uniItems[$ident]['type'] = (string) $productCat['category_id'];
            }

            $lineTotal = (float) $product['total'] + (float) $product['tax'];
            $qty = max(1, (int) $product['quantity']);
            $productsPTemp = $lineTotal / $qty;

            switch ($uniEur) {
                case 0:
                    break;
                case 1:
                    if ($currencyCode === 'EUR') {
                        $productsPTemp = ($productsPTemp * self::EUR_BGN_RATE);
                    }
                    break;
                case 2:
                case 3:
                    if ($currencyCode === 'BGN') {
                        $productsPTemp = ($productsPTemp / self::EUR_BGN_RATE);
                    }
                    break;
            }
            $uniItems[$ident]['singlePrice'] = (float) number_format($productsPTemp, 2, '.', '');
            ++$ident;
        }

        $devices = $this->detectDeviceBgLabel();

        $uniPost = [
            'orderId'    => $orderId,
            'orderTotal' => $uniTotal,
            'vnoska'     => $uniMesecna,
            'gpr'        => $uniGpr,
            'glp'        => $uniGlp,
            'vnoski'     => $uniVnoski,
            'parva'      => $uniParva,
            'devices'    => $devices,
            'currency'   => $uniCurrencyCodeSend,
            // Контролният панел (addorders) очаква други ключове от JSON към sucfOnlineSessionStart (clientFirstName, …).
            'customer'   => [
                'firstName'        => $this->sanitizeUniString($uniFnameSend),
                'lastName'         => $this->sanitizeUniString($uniLnameSend),
                'email'            => $this->sanitizeUniString($uniEmailSend),
                'phone'            => $this->sanitizeUniString($uniPhoneSend),
                'billingAddress'   => $this->sanitizeUniString($uniBillingAddress),
                'billingCity'      => $this->sanitizeUniString($uniBillingCity),
                'billingCounty'    => $this->sanitizeUniString($uniBillingCounty),
                'deliveryAddress'  => $this->sanitizeUniString($uniShippingAddress),
                'deliveryCity'     => $this->sanitizeUniString($uniShippingCity),
                'deliveryCounty'   => $this->sanitizeUniString($uniShippingCounty),
            ],
            'items' => $uniItems,
        ];

        if ((int) ($paramsuni['uni_testenv'] ?? 0) === 1) {
            $uniService = (string) ($paramsuni['uni_test_service'] ?? '');
            $uniApplication = (string) ($paramsuni['uni_test_application'] ?? '');
        } else {
            $uniService = (string) ($paramsuni['uni_production_service'] ?? '');
            $uniApplication = (string) ($paramsuni['uni_production_application'] ?? '');
        }

        $uniUser = html_entity_decode((string) ($paramsuni['uni_user'] ?? ''), ENT_QUOTES, 'UTF-8');
        $uniPassword = html_entity_decode((string) ($paramsuni['uni_password'] ?? ''), ENT_QUOTES, 'UTF-8');
        $useCert = (($paramsuni['uni_sertificat'] ?? '') === 'Yes');

        $addUrl = $uniLiveUrl . '/function/addorders.php?cid=' . rawurlencode($unicid);
        $addBody = http_build_query($uniPost);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $addUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $addBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_MAXREDIRS      => 2,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'cache-control: no-cache',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false || $httpCode !== 200) {
            return ['ok' => false, 'redirect' => ''];
        }

        $paramsuniadd = json_decode((string) $response, true);
        if (!is_array($paramsuniadd)) {
            return ['ok' => false, 'redirect' => ''];
        }

        $uniData = [
            'user'               => $uniUser,
            'pass'               => $uniPassword,
            'orderNo'            => $orderId,
            'clientFirstName'    => $this->sanitizeUniString($uniFnameSend),
            'clientLastName'     => $this->sanitizeUniString($uniLnameSend),
            'clientPhone'        => $this->sanitizeUniString($uniPhoneSend),
            'clientEmail'        => $this->sanitizeUniString($uniEmailSend),
            'clientDeliveryAddress' => $this->sanitizeUniString($uniShippingAddress),
            'onlineProductCode'  => $uniKop,
            'totalPrice'         => (float) number_format($uniTotal, 2, '.', ''),
            'initialPayment'     => $uniParva,
            'installmentCount'   => $uniVnoski,
            'monthlyPayment'     => $uniMesecna,
            'items'              => $uniItems,
        ];

        $uniApi = '';
        if (isset($paramsuniadd['status']) && $paramsuniadd['status'] === 'Yes') {
            $uniApi = $this->callSucfOnlineSessionStart(
                rtrim($uniService, '/') . '/',
                $uniData,
                $useCert,
                $orderId
            );
        }

        $resultHtml = '';
        if ((int) ($paramsuni['uni_proces2'] ?? 0) === 1) {
            $resultHtml = $this->buildProcess2ResultHtml(
                $orderId,
                $uniFnameGet,
                $uniLnameGet,
                $uniEgnGet,
                $uniPhoneGet,
                $uniPhone2Get,
                $uniEmailGet,
                $uniShippingAddress,
                $uniKop,
                $uniDescriptionGet,
                $uniItems,
                $uniEur,
                $currencyCode,
                $uniTotal,
                $uniParva,
                $uniVnoski,
                $uniMesecna,
                $uniGpr
            );
            $this->sendProcess2Mail($resultHtml, $paramsuni, $uniEmailGet);
        }

        $uniApplicationTrim = rtrim($uniApplication, '/');
        $uniBankReadyRedirect = (int) ($paramsuni['uni_proces1'] ?? 1) === 1
            && $uniApi !== ''
            && $uniApplicationTrim !== '';

        $data = [
            'uni_pause_txt'            => $this->language->get('text_uni_pause'),
            'uni_logo'                 => $this->resolveUniLogoUrl(),
            'uni_api'                  => $uniApi,
            'uni_application'          => $uniApplicationTrim,
            'uni_proces1'              => (int) ($paramsuni['uni_proces1'] ?? 1),
            'uni_proces2'              => (int) ($paramsuni['uni_proces2'] ?? 0),
            'uni_order'                => isset($paramsuniadd['newid']) ? (int) $paramsuniadd['newid'] : 0,
            'result'                   => $resultHtml,
            'uni_bank_ready_redirect'  => $uniBankReadyRedirect,
            'text_uni_bank_session_fail' => $this->language->get('text_uni_bank_session_fail'),
            'text_uni_bank_session_fail_hint' => $this->language->get('text_uni_bank_session_fail_hint'),
            'uni_continue_home'        => $this->url->link('common/home', 'language=' . $this->config->get('config_language')),
        ];

        return ['ok' => true, 'data' => $data];
    }

    private function sanitizeUniString(string $s): string
    {
        return str_replace(["'", "'"], '', $s);
    }

    /** Попълнена стойност от Uni формата в чекаута, иначе от записа на поръчката. */
    private function uniCustomerFieldPreferHandoff(string $handoff, string $fromOrder): string
    {
        return trim($handoff) !== '' ? $handoff : $fromOrder;
    }

    private function resolveUniLogoUrl(): string
    {
        if ($this->config->get('config_url')) {
            $base = (string) $this->config->get('config_url');
        } else {
            $base = defined('HTTP_SERVER') ? (string) constant('HTTP_SERVER') : '';
        }
        if ((bool) $this->config->get('config_secure')) {
            $ssl = $this->config->get('config_ssl');
            if ($ssl) {
                $base = (string) $ssl;
            }
        }

        return rtrim($base, '/') . '/catalog/view/stylesheet/mt_uni_credit/uni_logo.jpg';
    }

    private function detectDeviceBgLabel(): string
    {
        $useragent = isset($this->request->server['HTTP_USER_AGENT']) ? (string) $this->request->server['HTTP_USER_AGENT'] : '';

        if (
            preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i', $useragent)
            || preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i', substr($useragent, 0, 4))
        ) {
            return 'МОБИЛЕН ТЕЛЕФОН';
        }

        return 'НАСТОЛЕН КОМПЮТЪР';
    }

    /**
     * @param array<int, array<string, mixed>> $uniItems
     */
    private function buildProcess2ResultHtml(
        int $orderId,
        string $uniFnameGet,
        string $uniLnameGet,
        string $uniEgnGet,
        string $uniPhoneGet,
        string $uniPhone2Get,
        string $uniEmailGet,
        string $uniShippingAddress,
        string $uniKop,
        string $uniDescriptionGet,
        array $uniItems,
        int $uniEur,
        string $uniCurrencyCode,
        float $uniTotal,
        float $uniParva,
        int $uniVnoski,
        float $uniMesecna,
        float $uniGpr
    ): string {
        $result = '<span class="uni_result">' . $this->language->get('text_uni_result_title') . '</span><br /><br />';
        $result .= '<span class="uni_subresult">' . $this->language->get('text_uni_result_ok') . '</span><br /><br />';
        $result .= $this->language->get('text_uni_leasing_intro') . '<br /><br />';
        $result .= $this->language->get('text_uni_order_no') . ' ' . $orderId . '<br />';
        $result .= $this->language->get('text_uni_fname') . ' ' . htmlspecialchars($uniFnameGet, ENT_QUOTES, 'UTF-8') . '<br />';
        $result .= $this->language->get('text_uni_lname') . ' ' . htmlspecialchars($uniLnameGet, ENT_QUOTES, 'UTF-8') . '<br />';
        $result .= $this->language->get('text_uni_egn') . ' ' . htmlspecialchars($uniEgnGet, ENT_QUOTES, 'UTF-8') . '<br />';
        $result .= $this->language->get('text_uni_phone') . ' ' . htmlspecialchars($uniPhoneGet, ENT_QUOTES, 'UTF-8') . '<br />';
        $result .= $this->language->get('text_uni_phone2') . ' ' . htmlspecialchars($uniPhone2Get, ENT_QUOTES, 'UTF-8') . '<br />';
        $result .= $this->language->get('text_uni_email') . ' ' . htmlspecialchars($uniEmailGet, ENT_QUOTES, 'UTF-8') . '<br />';
        $result .= $this->language->get('text_uni_delivery') . ' ' . htmlspecialchars($uniShippingAddress, ENT_QUOTES, 'UTF-8') . '<br />';
        $result .= 'KOP: ' . htmlspecialchars($uniKop, ENT_QUOTES, 'UTF-8') . '<br />';
        $result .= $this->language->get('text_uni_comment') . ' ' . htmlspecialchars($uniDescriptionGet, ENT_QUOTES, 'UTF-8') . '<br />';

        foreach ($uniItems as $item) {
            $itemSinglePrice = (float) $item['singlePrice'];
            switch ($uniEur) {
                case 0:
                    break;
                case 1:
                    if ($uniCurrencyCode === 'EUR') {
                        $itemSinglePrice = (float) number_format($itemSinglePrice * self::EUR_BGN_RATE, 2, '.', '');
                    }
                    break;
                case 2:
                case 3:
                    if ($uniCurrencyCode === 'BGN') {
                        $itemSinglePrice = (float) number_format($itemSinglePrice / self::EUR_BGN_RATE, 2, '.', '');
                    }
                    break;
            }
            $result .= $this->language->get('text_uni_product_line')
                . htmlspecialchars((string) $item['code'], ENT_QUOTES, 'UTF-8')
                . ' , ' . htmlspecialchars((string) $item['name'], ENT_QUOTES, 'UTF-8')
                . ' , ' . (int) $item['count']
                . ' , ' . $itemSinglePrice . '<br />';
        }

        $uniObshta = (float) number_format((float) $uniVnoski * $uniMesecna, 2, '.', '');
        $uniTotalSecond = 0.0;
        $uniMesecnaSecond = 0.0;
        $uniObshtaSecond = 0.0;
        $uniSign = 'лева';
        $uniSignSecond = 'евро';
        switch ($uniEur) {
            case 0:
                break;
            case 1:
                $uniTotalSecond = (float) number_format($uniTotal / self::EUR_BGN_RATE, 2, '.', '');
                $uniMesecnaSecond = (float) number_format($uniMesecna / self::EUR_BGN_RATE, 2, '.', '');
                $uniObshtaSecond = (float) number_format($uniObshta / self::EUR_BGN_RATE, 2, '.', '');
                break;
            case 2:
                $uniTotalSecond = (float) number_format($uniTotal * self::EUR_BGN_RATE, 2, '.', '');
                $uniMesecnaSecond = (float) number_format($uniMesecna * self::EUR_BGN_RATE, 2, '.', '');
                $uniObshtaSecond = (float) number_format($uniObshta * self::EUR_BGN_RATE, 2, '.', '');
                $uniSign = 'евро';
                $uniSignSecond = 'лева';
                break;
            case 3:
                $uniSign = 'евро';
                $uniSignSecond = 'лева';
                break;
        }

        if ($uniTotalSecond == 0) {
            $result .= $this->language->get('text_uni_goods_price') . ' (' . $uniSign . '): ' . $uniTotal . '<br />';
        } else {
            $result .= $this->language->get('text_uni_goods_price') . ' (' . $uniSign . '/' . $uniSignSecond . '): ' . $uniTotal . ' / ' . $uniTotalSecond . '<br />';
        }
        $result .= $this->language->get('text_uni_first_pay') . ' (' . $uniSign . '): ' . $uniParva . '<br />';
        $result .= $this->language->get('text_uni_installments_count') . ' ' . $uniVnoski . '<br />';
        if ($uniMesecnaSecond == 0) {
            $result .= $this->language->get('text_uni_monthly') . ' (' . $uniSign . '): ' . $uniMesecna . '<br />';
        } else {
            $result .= $this->language->get('text_uni_monthly') . ' (' . $uniSign . '/' . $uniSignSecond . '): ' . $uniMesecna . ' / ' . $uniMesecnaSecond . '<br />';
        }
        $result .= $this->language->get('text_uni_gpr') . ' ' . $uniGpr . '<br />';
        if ($uniObshtaSecond == 0) {
            $result .= $this->language->get('text_uni_total_due') . ' (' . $uniSign . '): ' . $uniObshta . '<br />';
        } else {
            $result .= $this->language->get('text_uni_total_due') . ' (' . $uniSign . '/' . $uniSignSecond . '): ' . $uniObshta . ' / ' . $uniObshtaSecond . '<br />';
        }
        $result .= '<strong>' . $this->language->get('text_uni_expect_contact') . '</strong><br />';
        $result .= $this->language->get('text_uni_continue_shopping');

        return $result;
    }

    /**
     * @param array<string, mixed> $paramsuni
     */
    private function sendProcess2Mail(string $resultHtml, array $paramsuni, string $uniEmailGet): void
    {
        if (!$this->config->get('config_mail_engine')) {
            return;
        }

        $from = (string) $this->config->get('config_email');
        $sender = (string) $this->config->get('config_name');
        $subject = (string) $this->language->get('text_uni_mail_subject');
        $textBody = trim(strip_tags(str_replace(['<br />', '<br/>', '<br>'], PHP_EOL, $resultHtml)));
        $mailOption = [
            'parameter'     => $this->config->get('config_mail_parameter'),
            'smtp_hostname' => $this->config->get('config_mail_smtp_hostname'),
            'smtp_username' => $this->config->get('config_mail_smtp_username'),
            'smtp_password' => html_entity_decode((string) $this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8'),
            'smtp_port'     => $this->config->get('config_mail_smtp_port'),
            'smtp_timeout'  => $this->config->get('config_mail_smtp_timeout')
        ];

        $recipients = [];
        $customerEmail = trim($uniEmailGet);
        if ($customerEmail !== '') {
            $recipients[$customerEmail] = true;
        }
        $adminEmail = trim((string) $this->config->get('config_email'));
        if ($adminEmail !== '') {
            $recipients[$adminEmail] = true;
        }
        $moduleAdminEmail = trim((string) ($paramsuni['uni_email'] ?? ''));
        if ($moduleAdminEmail !== '') {
            $recipients[$moduleAdminEmail] = true;
        }

        foreach (array_keys($recipients) as $to) {
            try {
                $mailClass = '\Opencart\System\Library\Mail';
                $mail = new $mailClass((string) $this->config->get('config_mail_engine'), $mailOption);
                $mail->setTo($to);
                $mail->setFrom($from);
                $mail->setSender($sender);
                $mail->setSubject($subject);
                $mail->setText($textBody);
                $mail->setHtml($resultHtml);
                $mail->send();
            } catch (\Throwable $e) {
                // Не прекъсваме поръчката при mail грешка.
            }
        }
    }

    /**
     * @param array<string, mixed> $uniData
     */
    private function callSucfOnlineSessionStart(string $serviceUrl, array $uniData, bool $useCert, int $orderId): string
    {
        $url = rtrim($serviceUrl, '/') . '/sucfOnlineSessionStart';
        $jsonBody = json_encode($uniData, JSON_UNESCAPED_UNICODE);

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'cache-control: no-cache',
            ],
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 2,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ];

        if (defined('CURL_SSLVERSION_TLSv1_2')) {
            $opts[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }

        if ($useCert) {
            $this->load->model('extension/mt_uni_credit/module/product_panel');
            $paths = $this->model_extension_mt_uni_credit_module_product_panel->getUnicreditClientPemPaths();
            if ($paths === null) {
                return '';
            }
            $pass = $this->model_extension_mt_uni_credit_module_product_panel->getClientPemPassphrase();
            $opts[CURLOPT_SSLKEY] = $paths['key'];
            $opts[CURLOPT_SSLKEYPASSWD] = $pass;
            $opts[CURLOPT_SSLCERT] = $paths['cert'];
            $opts[CURLOPT_SSLCERTPASSWD] = $pass;
        }

        $ch = curl_init();
        curl_setopt_array($ch, $opts);
        $responseapi = curl_exec($ch);
        $err = curl_error($ch);

        if ((int) $this->config->get($this->module . '_debug') === 1) {
            $debugPath = \DIR_EXTENSION . 'mt_uni_credit/keys/uni_debug.json';
            $dir = dirname($debugPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
            $responseRaw = (string) $responseapi;
            $responseBlock = $responseRaw;
            $decodedResp = json_decode($responseRaw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedResp)) {
                $responseBlock = (string) json_encode($decodedResp, $jsonFlags);
            }
            $requestBlock = (string) json_encode($uniData, $jsonFlags);
            $stamp = date('Y-m-d H:i:s');
            $errLine = $err !== '' ? $err : '(няма)';
            $block = str_repeat('=', 72) . PHP_EOL
                . 'Време: ' . $stamp . PHP_EOL
                . str_repeat('-', 72) . PHP_EOL
                . 'cURL грешка:' . PHP_EOL
                . $errLine . PHP_EOL
                . PHP_EOL
                . 'Отговор (JSON):' . PHP_EOL
                . $responseBlock . PHP_EOL
                . PHP_EOL
                . 'Заявка (JSON body):' . PHP_EOL
                . $requestBlock . PHP_EOL
                . str_repeat('#', 72) . PHP_EOL
                . PHP_EOL;
            @file_put_contents($debugPath, $block, FILE_APPEND);
        }

        $apiObj = json_decode((string) $responseapi);
        if (is_object($apiObj) && !empty($apiObj->sucfOnlineSessionID)) {
            return (string) $apiObj->sucfOnlineSessionID;
        }

        return '';
    }
}
