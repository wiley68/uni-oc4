<?php

namespace Opencart\Catalog\Model\Extension\MtUniCredit\Module;

require_once \DIR_EXTENSION . 'mt_uni_credit/admin/model/module/unicredit_config.php';
require_once \DIR_EXTENSION . 'mt_uni_credit/system/library/uni_financial_rate.php';

use Opencart\Admin\Model\Extension\MtUniCredit\Module\UnicreditConfig;
use Opencart\System\Engine\Model;

/**
 * Логика за продуктов UniCredit блок (аналог на PrestaShop ProductAdditionalInfoBlockService).
 */
class ProductPanel extends Model
{

    /** @var list<int> */
    private const KIMB_BANK_INSTALLMENT_COUNTS = [3, 4, 5, 6, 9, 10, 12, 18, 24, 30, 36];

    /** Ключ в stats JSON: редова сума (сесийна валута), за която са изчислени последните KIMB — за преход промо ↔ стандарт. */
    private const STAT_LINE_TOTAL_USED_FOR_KIMB = '_uni_line_total_used';

    /** @var list<int> */
    private const PRODUCT_INSTALLMENT_MONTHS = [3, 4, 5, 6, 9, 10, 12, 15, 18, 24, 30, 36];

    private const CLIENT_PEM_PASSPHRASE = '1234';

    private string $module = UnicreditConfig::MODULE_SETTING_KEY;

    /**
     * Публичен URL-път (след shop base). Файловете идват от extension/.../view/stylesheet/mt_uni_credit/ и се копират в catalog/ при install/запис на модула.
     */
    private const PUBLIC_STYLESHEET_ASSETS = '/catalog/view/stylesheet/mt_uni_credit';

    /**
     * @param array<string, string> $texts Езикови низове за UI (desktop/mobile етикети и пр.)
     * @param float                 $initialQuantity начална бройка (GET quantity / minimum); за KOP/промо и KIMB се ползва редова сума = ед. цена × бройка
     *
     * @return array<string, mixed>|null null = не показвай блока
     */
    public function buildAssignForProductPage(
        int $productId,
        float $displayPrice,
        string $currencyCode,
        string $userAgent,
        string $shopSslBase,
        string $calculateUrlJs,
        string $optionCheckUrlJs,
        string $cartAddUrlJs,
        string $cartPageUrlJs,
        array $texts,
        string $installmentIntentSaveUrlJs = '',
        string $checkoutPageUrlJs = '',
        float $initialQuantity = 1.0
    ): ?array {
        $uniStatus = (int) $this->config->get($this->module . '_status');
        if ($uniStatus <= 0 || ($currencyCode !== 'EUR' && $currencyCode !== 'BGN')) {
            return null;
        }

        $unicid = trim((string) $this->config->get($this->module . '_unicid'));
        if ($unicid === '') {
            return null;
        }

        $paramsuni = $this->fetchUniParamsFromBankAndCache($unicid, false);
        if (!is_array($paramsuni) || (($paramsuni['uni_status'] ?? '') !== 'Yes')) {
            return null;
        }

        $productCategoryRoots = $this->getProductRootCategoryIdsOrdered($productId);
        if ($productCategoryRoots === []) {
            return null;
        }

        $uniCategoriesKop = $this->loadKopMappingFromDb();
        $uniKey = $this->findKopRowIndexForProductCategories($uniCategoriesKop, $productCategoryRoots);
        if ($uniKey === false) {
            return null;
        }

        $unitDisplayPrice = $displayPrice;
        $qty = $initialQuantity > 0 ? $initialQuantity : 1.0;
        if ($qty > 99999.0) {
            $qty = 99999.0;
        }
        $lineTotalForCoefficients = $unitDisplayPrice * $qty;

        $uniShemaCurrent = (int) ($paramsuni['uni_shema_current'] ?? 12);
        $uniService = (int) ($paramsuni['uni_testenv'] ?? 0) === 1
            ? (string) ($paramsuni['uni_test_service'] ?? '')
            : (string) ($paramsuni['uni_production_service'] ?? '');
        $uniUser = html_entity_decode((string) ($paramsuni['uni_user'] ?? ''), ENT_QUOTES, 'UTF-8');
        $uniPassword = html_entity_decode((string) ($paramsuni['uni_password'] ?? ''), ENT_QUOTES, 'UTF-8');
        $useCert = (($paramsuni['uni_sertificat'] ?? '') === 'Yes');

        $uniKop = $this->resolveKopCode($uniCategoriesKop, $uniKey, $paramsuni, $lineTotalForCoefficients, $uniShemaCurrent);
        if ($uniKop === '') {
            return null;
        }

        $deviceis = $this->detectDevice($userAgent);
        $paramsunicalc = $this->fetchUniCalculationFromBankAndCache($unicid, $deviceis, false);
        if (empty($paramsunicalc) || !is_array($paramsunicalc)) {
            return null;
        }

        $row = &$uniCategoriesKop[$uniKey];
        $uniParamKimb = $row['kimb'];
        $stats = isset($row['stats']) && is_array($row['stats']) ? $row['stats'] : [];

        $uniParamKimbTime = ($row['kimb_time'] ?? '') === '' ? 0 : (int) $row['kimb_time'];
        $currentTime = time() - 86400;

        if ($currentTime > $uniParamKimbTime) {
            if ($this->canUseBankCoeffApi($uniService, $uniUser, $uniPassword)) {
                if (!isset($row['stats']) || !is_array($row['stats'])) {
                    $row['stats'] = [];
                }
                foreach (self::KIMB_BANK_INSTALLMENT_COUNTS as $cnt) {
                    $kopForCnt = $this->resolveKopCode($uniCategoriesKop, $uniKey, $paramsuni, $lineTotalForCoefficients, $cnt);
                    if ($kopForCnt === '') {
                        continue;
                    }
                    $fetched = $this->fetchCoeffWithFileCache($uniService, $uniUser, $uniPassword, $kopForCnt, $cnt, $useCert);
                    if ($fetched !== null && $fetched['kimb'] > 0) {
                        $row['stats']['kimb_' . $cnt] = (string) $fetched['kimb'];
                        $row['stats']['glp_' . $cnt] = (string) $fetched['glp'];
                    }
                }
                $kimbForCurrent = $this->kimbFromStatsForInstallments($row['stats'], $uniShemaCurrent);
                $row['kimb'] = $kimbForCurrent > 0 ? (string) $kimbForCurrent : '';
                $row['kimb_time'] = (string) time();
                $row['stats'][self::STAT_LINE_TOTAL_USED_FOR_KIMB] = number_format(
                    $lineTotalForCoefficients,
                    2,
                    '.',
                    ''
                );
                $this->persistKopRuntimeData($row);
            }
            $stats = isset($row['stats']) && is_array($row['stats']) ? $row['stats'] : [];
            $kimb = $this->kimbFromStatsForInstallments($stats, $uniShemaCurrent);
            if ($kimb <= 0) {
                $kimb = (float) str_replace(',', '.', (string) $row['kimb']);
            }
        } else {
            $kimb = (float) str_replace(',', '.', (string) ($uniParamKimb ?? ''));
        }

        $stats = isset($row['stats']) && is_array($row['stats']) ? $row['stats'] : [];
        $kg = $this->kimbGlpArraysFromStats($stats);
        $kimbArr = $kg['kimb'];
        $glpArr = $kg['glp'];

        $uniEur = (int) $paramsuni['uni_eur'];
        $uniLineAmount = $lineTotalForCoefficients;

        $uniMesecna = number_format($uniLineAmount * $kimb, 2, '.', '');
        $uniMesecna3 = number_format($uniLineAmount * (float) ($kimbArr['3'] ?? ''), 2, '.', '');
        $uniMesecna4 = number_format($uniLineAmount * (float) ($kimbArr['4'] ?? ''), 2, '.', '');
        $uniMesecna5 = number_format($uniLineAmount * (float) ($kimbArr['5'] ?? ''), 2, '.', '');
        $uniMesecna6 = number_format($uniLineAmount * (float) ($kimbArr['6'] ?? ''), 2, '.', '');
        $uniMesecna9 = number_format($uniLineAmount * (float) ($kimbArr['9'] ?? ''), 2, '.', '');
        $uniMesecna10 = number_format($uniLineAmount * (float) ($kimbArr['10'] ?? ''), 2, '.', '');
        $uniMesecna12 = number_format($uniLineAmount * (float) ($kimbArr['12'] ?? ''), 2, '.', '');
        $uniMesecna15 = number_format($uniLineAmount * (float) ($kimbArr['15'] ?? ''), 2, '.', '');
        $uniMesecna18 = number_format($uniLineAmount * (float) ($kimbArr['18'] ?? ''), 2, '.', '');
        $uniMesecna24 = number_format($uniLineAmount * (float) ($kimbArr['24'] ?? ''), 2, '.', '');
        $uniMesecna30 = number_format($uniLineAmount * (float) ($kimbArr['30'] ?? ''), 2, '.', '');
        $uniMesecna36 = number_format($uniLineAmount * (float) ($kimbArr['36'] ?? ''), 2, '.', '');

        switch ($uniEur) {
            case 1:
                if ($currencyCode === 'EUR') {
                    $uniMesecna = (string) ((float) $uniMesecna * UnicreditConfig::EUR_BGN_RATE);
                }
                break;
            case 2:
            case 3:
                if ($currencyCode === 'BGN') {
                    $uniMesecna = (string) ((float) $uniMesecna / UnicreditConfig::EUR_BGN_RATE);
                }
                break;
        }

        $uniMesecnaSecond = '0';
        $uniPriceSecond = '0';
        $uniSign = 'лева';
        $uniSignSecond = 'евро';
        switch ($uniEur) {
            case 0:
                break;
            case 1:
                $uniPriceSecond = number_format($uniLineAmount / UnicreditConfig::EUR_BGN_RATE, 2, '.', '');
                $uniMesecnaSecond = number_format((float) $uniMesecna / UnicreditConfig::EUR_BGN_RATE, 2, '.', '');
                $uniSign = 'лева';
                $uniSignSecond = 'евро';
                break;
            case 2:
                $uniPriceSecond = number_format($uniLineAmount * UnicreditConfig::EUR_BGN_RATE, 2, '.', '');
                $uniMesecnaSecond = number_format((float) $uniMesecna * UnicreditConfig::EUR_BGN_RATE, 2, '.', '');
                $uniSign = 'евро';
                $uniSignSecond = 'лева';
                break;
            case 3:
                $uniPriceSecond = '0';
                $uniMesecnaSecond = '0';
                $uniSign = 'евро';
                $uniSignSecond = 'лева';
                break;
        }

        $uniMinstojnost = (float) ($paramsuni['uni_minstojnost'] ?? 0);
        $uniMaxstojnost = (float) ($paramsuni['uni_maxstojnost'] ?? 0);
        $uniZaglavie = (string) ($paramsuni['uni_zaglavie'] ?? '');
        $uniVnoska = (string) ($paramsuni['uni_vnoska'] ?? 'No');
        $uniReklamaUrl = (string) ($paramsunicalc['uni_reklama_url'] ?? '');
        $base = rtrim($shopSslBase, '/');
        $img = $base . self::PUBLIC_STYLESHEET_ASSETS;
        $uniPicture = $img . '/uni.png';
        $uniMiniLogo = $img . '/uni_mini_logo.png';
        $uniProces1 = (int) ($paramsuni['uni_proces1'] ?? 0);

        $mesecnaByMonth = [
            3 => $uniMesecna3,
            4 => $uniMesecna4,
            5 => $uniMesecna5,
            6 => $uniMesecna6,
            9 => $uniMesecna9,
            10 => $uniMesecna10,
            12 => $uniMesecna12,
            15 => $uniMesecna15,
            18 => $uniMesecna18,
            24 => $uniMesecna24,
            30 => $uniMesecna30,
            36 => $uniMesecna36,
        ];
        $uniProductInstallmentOptions = [];
        foreach (self::PRODUCT_INSTALLMENT_MONTHS as $m) {
            $mesecnaStr = (string) ($mesecnaByMonth[$m] ?? '0');
            $configOn = (int) ($paramsuni['uni_meseci_' . $m] ?? 0) !== 0;
            $mesecnaNum = (float) str_replace(',', '.', $mesecnaStr);
            $uniProductInstallmentOptions[] = [
                'months'           => $m,
                'show_in_select'   => $configOn && $mesecnaNum > 0,
            ];
        }

        $uniKimbHiddenFields = $this->buildUniKimbHiddenFieldsFromStatsArray($stats);

        $classes = $this->resolveUiTexts($texts);

        $gapRaw = $this->config->get($this->module . '_gap');
        $uniGap = ($gapRaw === null || $gapRaw === '') ? 0 : (int) $gapRaw;

        $assign = array_merge([
            'uni_cart'                        => (int) $this->config->get($this->module . '_cart'),
            'uni_csrf_token'                  => '',
            'uni_prepare_installmentcheckout_url' => $installmentIntentSaveUrlJs,
            'uni_checkout_url'                => $checkoutPageUrlJs,
            'uni_get_product_link'            => $calculateUrlJs,
            'uni_option_check_url'            => $optionCheckUrlJs,
            'uni_cart_add_url'                => $cartAddUrlJs,
            'uni_cart_page_url'               => $cartPageUrlJs,
            'uni_kimb_hidden_fields'          => $uniKimbHiddenFields,
            'uni_eur'                         => $uniEur,
            'uni_currency_code'               => $currencyCode,
            'uni_sign'                        => $uniSign,
            'uni_sign_second'                 => $uniSignSecond,
            'uni_eur_bgn_rate'                => UnicreditConfig::EUR_BGN_RATE,
            'uni_zaglavie'                    => $uniZaglavie,
            'uni_vnoska'                      => $uniVnoska,
            'uni_mesecna'                     => $uniMesecna,
            'uni_product_id'                  => $productId,
            'uni_reklama_url'                 => $uniReklamaUrl,
            'uni_mod_version'                 => UnicreditConfig::MODULE_VERSION,
            'uni_picture'                     => $uniPicture,
            'uni_mesecna_second'              => $uniMesecnaSecond,
            'uni_price_second'                => $uniPriceSecond,
            'uni_unit_price'                  => $unitDisplayPrice,
            'uni_price'                       => $uniLineAmount,
            'uni_shema_current'               => $uniShemaCurrent,
            'uni_product_installment_options' => $uniProductInstallmentOptions,
            'uni_mini_logo'                   => $uniMiniLogo,
            'uni_gap'                         => $uniGap,
            'uni_proces1'                     => $uniProces1,
            'uni_js_shop_strings'             => json_encode([
                'cartAddFailed'         => $texts['js_cart_add_failed'] ?? '',
                'storeError'          => $texts['js_store_error'] ?? '',
                'installmentIntentFailed' => $texts['js_installment_intent_failed'] ?? '',
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        ], $classes);

        $shouldDisplay = $uniLineAmount <= $uniMaxstojnost && $uniLineAmount >= $uniMinstojnost;
        $assign['uni_minstojnost'] = $uniMinstojnost;
        $assign['uni_maxstojnost'] = $uniMaxstojnost;
        $assign['uni_show_credit_block'] = $shouldDisplay;

        return $assign;
    }

    /**
     * Данни за плаващ рекламен банер на началната страница (същите ключове като PS8 unipanel.tpl).
     *
     * @return array{
     *     uni_status_cp: string,
     *     uni_container_status: string,
     *     deviceis: string,
     *     uni_logo: string,
     *     uni_picture: string,
     *     uni_backurl: string,
     *     uni_container_txt1: string,
     *     uni_container_txt2: string
     * }|null
     * uni_logo / uni_picture: URL към catalog/view/stylesheet/mt_uni_credit/uni_logo.jpg и unim.png.
     * uni_reklama_url, uni_container_txt1, uni_container_txt2: от getparameters ($paramsuni).
     * uni_container_status в assign: от getcalculation ($paramsunicalc), по подразбиране Yes.
     * От getparameters също се чете uni_container_status (Yes/No); при No рекламата не се показва.
     */
    public function buildAssignForUnipanel(string $shopSslBase, string $userAgent): ?array
    {
        $uniStatus = (int) $this->config->get($this->module . '_status');
        $reklama = (int) $this->config->get($this->module . '_reklama');
        if ($uniStatus <= 0 || $reklama <= 0) {
            return null;
        }

        $unicid = trim((string) $this->config->get($this->module . '_unicid'));
        if ($unicid === '') {
            return null;
        }

        $paramsuni = $this->fetchUniParamsFromBankAndCache($unicid, false);
        if (
            !is_array($paramsuni)
            || (($paramsuni['uni_status'] ?? '') !== 'Yes')
            || (($paramsuni['uni_container_status'] ?? 'Yes') !== 'Yes')
        ) {
            return null;
        }

        $deviceis = $this->detectDevice($userAgent);
        $paramsunicalc = $this->fetchUniCalculationFromBankAndCache($unicid, $deviceis, false);
        if (!is_array($paramsunicalc) || $paramsunicalc === []) {
            return null;
        }

        $base = rtrim($shopSslBase, '/');
        $uniReklamaUrl = (string) ($paramsuni['uni_backurl'] ?? '');
        $img = $base . self::PUBLIC_STYLESHEET_ASSETS;
        $uniLogo = $img . '/uni_logo.jpg';
        $uniPicture = $img . '/unim.png';
        $containerStatus = (string) ($paramsunicalc['uni_container_status'] ?? 'Yes');

        return [
            'uni_status_cp'          => (string) ($paramsuni['uni_status'] ?? 'No'),
            'uni_container_status'   => $containerStatus,
            'deviceis'               => $deviceis,
            'uni_logo'               => $uniLogo,
            'uni_picture'            => $uniPicture,
            'uni_backurl'            => $uniReklamaUrl,
            'uni_container_txt1'     => (string) ($paramsuni['uni_container_txt1'] ?? ''),
            'uni_container_txt2'     => (string) ($paramsuni['uni_container_txt2'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $paramsuni Кеширани bank params (getparameters).
     */
    public function isCheckoutInstallmentMonthAllowed(array $paramsuni, int $months): bool
    {
        if ($months <= 0 || !in_array($months, self::PRODUCT_INSTALLMENT_MONTHS, true)) {
            return false;
        }

        return (int) ($paramsuni['uni_meseci_' . $months] ?? 0) !== 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUniParamsFromBankCached(bool $forceReload = false): ?array
    {
        $unicid = trim((string) $this->config->get($this->module . '_unicid'));
        if ($unicid === '') {
            return null;
        }

        return $this->fetchUniParamsFromBankAndCache($unicid, $forceReload);
    }

    /**
     * @return array{key: string, cert: string}|null
     */
    public function getUnicreditClientPemPaths(): ?array
    {
        return $this->ensureClientPemMaterial();
    }

    public function getClientPemPassphrase(): string
    {
        return self::CLIENT_PEM_PASSPHRASE;
    }

    /**
     * Данни за фрагмента на плащане в чекаута (аналог на PS8 hookPaymentOptions + Smarty assign).
     *
     * @return array<string, mixed>
     */
    public function buildAssignForCheckoutPayment(int $paymentUniEnabled): array
    {
        $currencyCode = (string) ($this->session->data['currency'] ?? 'BGN');
        $cartTotalRaw = $this->cart->hasProducts() ? (float) $this->cart->getTotal() : 0.0;

        $baseOut = [
            'uni_status'            => 'No',
            'uni_total'             => $cartTotalRaw,
            'uni_minstojnost'       => 0.0,
            'uni_maxstojnost'       => 0.0,
            'uni_sign'              => 'лева',
            'uni_sign_second'       => 'евро',
            'uni_eur_bgn_rate'      => UnicreditConfig::EUR_BGN_RATE,
            'uni_eur'               => 0,
            'uni_price_second'      => '0',
            'uni_liveurl'           => rtrim(UnicreditConfig::LIVE_URL, '/'),
            'uni_unicid'            => trim((string) $this->config->get($this->module . '_unicid')),
            'uni_mod_version'       => UnicreditConfig::MODULE_VERSION,
            'uni_proces1'           => 0,
            'uni_proces2'           => 0,
            'uni_first_vnoska'      => 'No',
            'uni_firstname'         => '',
            'uni_lastname'          => '',
            'uni_phone'             => '',
            'uni_email'             => '',
            'uni_shema_current'     => 12,
            'uni_product_cat_id'    => 0,
            'uni_product_category_ids' => '',
            'uni_promo'             => '',
            'uni_promo_data'        => '',
            'uni_promo_meseci_znak' => '',
            'uni_promo_meseci'      => '',
            'uni_promo_price'       => '',
            'uni_service'           => '',
            'uni_user'              => '',
            'uni_password'          => '',
            'uni_sertificat'        => 'No',
            'uni_terms_href'        => rtrim(UnicreditConfig::LIVE_URL, '/') . '/css/uni_uslovia.pdf',
        ];

        foreach (self::PRODUCT_INSTALLMENT_MONTHS as $m) {
            $baseOut['uni_meseci_' . $m] = false;
        }

        if ($paymentUniEnabled !== 1 || !$this->cart->hasProducts()) {
            return $baseOut;
        }

        $uniStatusMod = (int) $this->config->get($this->module . '_status');
        if ($uniStatusMod <= 0 || ($currencyCode !== 'EUR' && $currencyCode !== 'BGN')) {
            return $baseOut;
        }

        $unicid = trim((string) $this->config->get($this->module . '_unicid'));
        if ($unicid === '') {
            return $baseOut;
        }

        $paramsuni = $this->fetchUniParamsFromBankAndCache($unicid, false);
        if (!is_array($paramsuni) || (($paramsuni['uni_status'] ?? '') !== 'Yes')) {
            return $baseOut;
        }

        $mergedRoots = $this->getMergedRootCategoryIdsForCart();
        if ($mergedRoots === []) {
            return $baseOut;
        }

        $uniCategoriesKop = $this->loadKopMappingFromDb();
        $uniKey = $this->findKopRowIndexForProductCategories($uniCategoriesKop, $mergedRoots);
        if ($uniKey === false) {
            return $baseOut;
        }

        $uniEur = (int) ($paramsuni['uni_eur'] ?? 0);
        $uniPrice = $cartTotalRaw;
        $uniPrice = $this->applyUniEurToCartTotal($uniPrice, $uniEur, $currencyCode);
        $secondary = $this->buildUniSecondaryPriceAndSigns($uniPrice, $uniEur, $currencyCode);

        $uniMinstojnost = (float) ($paramsuni['uni_minstojnost'] ?? 0);
        $uniMaxstojnost = (float) ($paramsuni['uni_maxstojnost'] ?? 0);
        if ($uniPrice < $uniMinstojnost || $uniPrice > $uniMaxstojnost) {
            $out = $baseOut;
            $out['uni_status'] = 'Yes';
            $out['uni_minstojnost'] = $uniMinstojnost;
            $out['uni_maxstojnost'] = $uniMaxstojnost;
            $out['uni_total'] = $uniPrice;
            $out['uni_eur'] = $uniEur;
            $out['uni_sign'] = $secondary['uni_sign'];
            $out['uni_sign_second'] = $secondary['uni_sign_second'];
            $out['uni_price_second'] = $secondary['uni_price_second'];
            $out['uni_proces1'] = (int) ($paramsuni['uni_proces1'] ?? 0);
            $out['uni_proces2'] = (int) ($paramsuni['uni_proces2'] ?? 0);
            $out['uni_first_vnoska'] = (string) ($paramsuni['uni_first_vnoska'] ?? 'No');
            foreach (self::PRODUCT_INSTALLMENT_MONTHS as $m) {
                $out['uni_meseci_' . $m] = $this->isCheckoutInstallmentMonthAllowed($paramsuni, $m);
            }

            return $out;
        }

        $preferredCookie = isset($_COOKIE[UnicreditConfig::BROWSER_COOKIE_CHECKOUT_INSTALLMENTS])
            ? (int) $_COOKIE[UnicreditConfig::BROWSER_COOKIE_CHECKOUT_INSTALLMENTS]
            : 0;
        $sessionMonths = (int) ($this->session->data['mt_uni_credit_installment_months'] ?? 0);
        $bankDefaultShema = (int) ($paramsuni['uni_shema_current'] ?? 12);

        $uniShemaCurrent = $bankDefaultShema;
        if ($sessionMonths > 0 && $this->isCheckoutInstallmentMonthAllowed($paramsuni, $sessionMonths)) {
            $uniShemaCurrent = $sessionMonths;
        } elseif ($preferredCookie > 0 && $this->isCheckoutInstallmentMonthAllowed($paramsuni, $preferredCookie)) {
            $uniShemaCurrent = $preferredCookie;
        } else {
            $uniShemaCurrent = $this->pickDefaultCheckoutInstallmentMonth($paramsuni, $bankDefaultShema);
        }

        $uniFirstname = '';
        $uniLastname = '';
        $uniEmail = '';
        $uniPhone = '';

        if ($this->customer->isLogged()) {
            $uniFirstname = (string) $this->customer->getFirstName();
            $uniLastname = (string) $this->customer->getLastName();
            $uniEmail = (string) $this->customer->getEmail();
            $uniPhone = (string) $this->customer->getTelephone();
        } elseif (isset($this->session->data['customer'])) {
            $c = $this->session->data['customer'];
            $uniFirstname = (string) ($c['firstname'] ?? '');
            $uniLastname = (string) ($c['lastname'] ?? '');
            $uniEmail = (string) ($c['email'] ?? '');
            $uniPhone = (string) ($c['telephone'] ?? '');
        }

        if ($uniPhone === '' && !empty($this->session->data['shipping_address']['telephone'])) {
            $uniPhone = (string) $this->session->data['shipping_address']['telephone'];
        }

        $out = $baseOut;
        $out['uni_status'] = 'Yes';
        $out['uni_total'] = $uniPrice;
        $out['uni_minstojnost'] = $uniMinstojnost;
        $out['uni_maxstojnost'] = $uniMaxstojnost;
        $out['uni_eur'] = $uniEur;
        $out['uni_sign'] = $secondary['uni_sign'];
        $out['uni_sign_second'] = $secondary['uni_sign_second'];
        $out['uni_price_second'] = $secondary['uni_price_second'];
        $out['uni_proces1'] = (int) ($paramsuni['uni_proces1'] ?? 0);
        $out['uni_proces2'] = (int) ($paramsuni['uni_proces2'] ?? 0);
        $out['uni_first_vnoska'] = (string) ($paramsuni['uni_first_vnoska'] ?? 'No');
        $out['uni_firstname'] = $uniFirstname;
        $out['uni_lastname'] = $uniLastname;
        $out['uni_phone'] = $uniPhone;
        $out['uni_email'] = $uniEmail;
        $out['uni_shema_current'] = $uniShemaCurrent;
        $out['uni_product_cat_id'] = (int) ($mergedRoots[0] ?? 0);
        $out['uni_product_category_ids'] = implode(',', array_map('strval', $mergedRoots));
        $out['uni_promo'] = (string) ($paramsuni['uni_promo'] ?? '');
        $out['uni_promo_data'] = (string) ($paramsuni['uni_promo_data'] ?? '');
        $out['uni_promo_meseci_znak'] = (string) ($paramsuni['uni_promo_meseci_znak'] ?? '');
        $out['uni_promo_meseci'] = (string) ($paramsuni['uni_promo_meseci'] ?? '');
        $out['uni_promo_price'] = (string) ($paramsuni['uni_promo_price'] ?? '');
        $out['uni_service'] = (int) ($paramsuni['uni_testenv'] ?? 0) === 1
            ? (string) ($paramsuni['uni_test_service'] ?? '')
            : (string) ($paramsuni['uni_production_service'] ?? '');
        $out['uni_user'] = html_entity_decode((string) ($paramsuni['uni_user'] ?? ''), ENT_QUOTES, 'UTF-8');
        $out['uni_password'] = html_entity_decode((string) ($paramsuni['uni_password'] ?? ''), ENT_QUOTES, 'UTF-8');
        $out['uni_sertificat'] = (string) ($paramsuni['uni_sertificat'] ?? 'No');

        foreach (self::PRODUCT_INSTALLMENT_MONTHS as $m) {
            $out['uni_meseci_' . $m] = $this->isCheckoutInstallmentMonthAllowed($paramsuni, $m);
        }

        return $out;
    }

    /**
     * AJAX преизчисляване в чекаута (аналог на OC3 calculateUni); коефициенти от банка с DB кеш.
     *
     * @param array<string, mixed> $post
     *
     * @return array<string, mixed>
     */
    public function calculateUniCheckoutAjax(array $post): array
    {
        $currencyCode = (string) ($this->session->data['currency'] ?? 'BGN');
        $empty = [
            'success'                   => '',
            'uni_obshto'                => '0.00',
            'uni_obshto_second'         => 0,
            'uni_mesecna'               => '0.00',
            'uni_mesecna_second'        => 0,
            'uni_glp'                   => '0.00',
            'uni_obshtozaplashtane'     => '0.00',
            'uni_obshtozaplashtane_second' => 0,
            'uni_gpr'                   => '0.00',
            'uni_kop'                   => '',
        ];

        if (!$this->cart->hasProducts()) {
            return $empty;
        }

        $uniStatusMod = (int) $this->config->get($this->module . '_status');
        if ($uniStatusMod <= 0 || ($currencyCode !== 'EUR' && $currencyCode !== 'BGN')) {
            return $empty;
        }

        $unicid = trim((string) $this->config->get($this->module . '_unicid'));
        if ($unicid === '') {
            return $empty;
        }

        $paramsuni = $this->fetchUniParamsFromBankAndCache($unicid, false);
        if (!is_array($paramsuni) || (($paramsuni['uni_status'] ?? '') !== 'Yes')) {
            return $empty;
        }

        $mergedRoots = $this->getMergedRootCategoryIdsForCart();
        if ($mergedRoots === []) {
            return $empty;
        }

        $uniCategoriesKop = $this->loadKopMappingFromDb();
        $uniKey = $this->findKopRowIndexForProductCategories($uniCategoriesKop, $mergedRoots);
        if ($uniKey === false) {
            return $empty;
        }

        $uniEur = (int) ($paramsuni['uni_eur'] ?? 0);
        $serverCartTotal = (float) $this->cart->getTotal();
        $serverCartTotal = $this->applyUniEurToCartTotal($serverCartTotal, $uniEur, $currencyCode);

        $uniMinstojnost = (float) ($paramsuni['uni_minstojnost'] ?? 0);
        $uniMaxstojnost = (float) ($paramsuni['uni_maxstojnost'] ?? 0);
        if ($serverCartTotal < $uniMinstojnost || $serverCartTotal > $uniMaxstojnost) {
            return $empty;
        }

        $postedPrice = isset($post['uni_total_price']) ? (float) str_replace(',', '.', (string) $post['uni_total_price']) : 0.0;
        $uniPrice = (abs($serverCartTotal - $postedPrice) > 0.05) ? $serverCartTotal : $postedPrice;

        $meseci = isset($post['uni_meseci']) ? (int) $post['uni_meseci'] : 0;
        if (!$this->isCheckoutInstallmentMonthAllowed($paramsuni, $meseci)) {
            return $empty;
        }

        $uniKop = $this->resolveKopCode($uniCategoriesKop, $uniKey, $paramsuni, $uniPrice, $meseci);
        if ($uniKop === '') {
            return $empty;
        }

        $uniService = (int) ($paramsuni['uni_testenv'] ?? 0) === 1
            ? (string) ($paramsuni['uni_test_service'] ?? '')
            : (string) ($paramsuni['uni_production_service'] ?? '');
        $uniUser = html_entity_decode((string) ($paramsuni['uni_user'] ?? ''), ENT_QUOTES, 'UTF-8');
        $uniPassword = html_entity_decode((string) ($paramsuni['uni_password'] ?? ''), ENT_QUOTES, 'UTF-8');
        $useCert = (($paramsuni['uni_sertificat'] ?? '') === 'Yes');

        if (!$this->canUseBankCoeffApi($uniService, $uniUser, $uniPassword)) {
            return $empty;
        }

        $coeff = $this->fetchCoeffWithFileCache($uniService, $uniUser, $uniPassword, $uniKop, $meseci, $useCert);
        if ($coeff === null || $coeff['kimb'] <= 0) {
            return $empty;
        }

        $kimb = $coeff['kimb'];
        $glp = number_format($coeff['glp'], 2, '.', '');

        $uniParva = isset($post['uni_parva']) ? (float) str_replace(',', '.', (string) $post['uni_parva']) : 0.0;
        if ($uniParva < 0 || $uniParva > $uniPrice) {
            return $empty;
        }

        $uniObshto = round($uniPrice - $uniParva, 2);
        if ($uniObshto < 0) {
            $uniObshto = 0.0;
        }

        $uniMesecna = round($uniObshto * $kimb, 2);
        $uniObshtozaplashtane = round($uniMesecna * $meseci, 2);

        $rateMonthly = \MtUniCreditFinancialRate::periodicRate((float) $meseci, -1 * $uniMesecna, $uniObshto);
        $uniGprm = $rateMonthly * 12.0;
        $uniGpr = abs((pow(1 + $uniGprm / 12, 12) - 1) * 100);
        $uniGpr = round($uniGpr, 2);
        if ($uniGpr <= 0.1) {
            $uniGpr = 0.0;
        }
        $uniGprDisplay = number_format($uniGpr, 2, '.', '');

        $sec = $this->buildSecondaryAmountsForCalculate($uniObshto, $uniMesecna, $uniObshtozaplashtane, $uniEur);

        return [
            'success'                   => 'success',
            'uni_obshto'                => number_format($uniObshto, 2, '.', ''),
            'uni_obshto_second'         => $sec['obshto_second'],
            'uni_mesecna'               => number_format($uniMesecna, 2, '.', ''),
            'uni_mesecna_second'        => $sec['mesecna_second'],
            'uni_glp'                   => $glp,
            'uni_obshtozaplashtane'     => number_format($uniObshtozaplashtane, 2, '.', ''),
            'uni_obshtozaplashtane_second' => $sec['obshtozaplashtane_second'],
            'uni_gpr'                   => $uniGprDisplay,
            'uni_kop'                   => $uniKop,
        ];
    }

    /**
     * @return list<int>
     */
    private function getMergedRootCategoryIdsForCart(): array
    {
        $merged = [];
        $seen = [];
        foreach ($this->cart->getProducts() as $p) {
            $pid = (int) ($p['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $roots = $this->getProductRootCategoryIdsOrdered($pid);
            foreach ($roots as $rid) {
                $rid = (int) $rid;
                if ($rid > 0 && !isset($seen[$rid])) {
                    $seen[$rid] = true;
                    $merged[] = $rid;
                }
            }
        }

        return $merged;
    }

    private function pickDefaultCheckoutInstallmentMonth(array $paramsuni, int $preferredFromBank): int
    {
        if ($this->isCheckoutInstallmentMonthAllowed($paramsuni, $preferredFromBank)) {
            return $preferredFromBank;
        }
        foreach (self::PRODUCT_INSTALLMENT_MONTHS as $m) {
            if ($this->isCheckoutInstallmentMonthAllowed($paramsuni, $m)) {
                return $m;
            }
        }

        return 12;
    }

    private function applyUniEurToCartTotal(float $uniTotal, int $uniEur, string $currencyCode): float
    {
        switch ($uniEur) {
            case 1:
                if ($currencyCode === 'EUR') {
                    return $uniTotal * UnicreditConfig::EUR_BGN_RATE;
                }
                break;
            case 2:
            case 3:
                if ($currencyCode === 'BGN') {
                    return $uniTotal / UnicreditConfig::EUR_BGN_RATE;
                }
                break;
        }

        return $uniTotal;
    }

    /**
     * @return array{uni_sign: string, uni_sign_second: string, uni_price_second: string}
     */
    private function buildUniSecondaryPriceAndSigns(float $uniPrice, int $uniEur, string $currencyCode): array
    {
        $uniPriceSecond = '0';
        $uniSign = 'лева';
        $uniSignSecond = 'евро';
        switch ($uniEur) {
            case 0:
                break;
            case 1:
                $uniPriceSecond = number_format($uniPrice / UnicreditConfig::EUR_BGN_RATE, 2, '.', '');
                break;
            case 2:
                $uniPriceSecond = number_format($uniPrice * UnicreditConfig::EUR_BGN_RATE, 2, '.', '');
                $uniSign = 'евро';
                $uniSignSecond = 'лева';
                break;
            case 3:
                $uniSign = 'евро';
                $uniSignSecond = 'лева';
                break;
        }

        return [
            'uni_sign'          => $uniSign,
            'uni_sign_second'   => $uniSignSecond,
            'uni_price_second'  => $uniPriceSecond,
        ];
    }

    /**
     * @return array{obshto_second: float|string, mesecna_second: float|string, obshtozaplashtane_second: float|string}
     */
    private function buildSecondaryAmountsForCalculate(float $uniObshto, float $uniMesecna, float $uniObshtozaplashtane, int $uniEur): array
    {
        $uniObshtoSecond = 0;
        $uniMesecnaSecond = 0;
        $uniObshtozaplashtaneSecond = 0;
        switch ($uniEur) {
            case 0:
                break;
            case 1:
                $uniObshtoSecond = number_format($uniObshto / UnicreditConfig::EUR_BGN_RATE, 2, '.', '');
                $uniMesecnaSecond = number_format($uniMesecna / UnicreditConfig::EUR_BGN_RATE, 2, '.', '');
                $uniObshtozaplashtaneSecond = number_format($uniObshtozaplashtane / UnicreditConfig::EUR_BGN_RATE, 2, '.', '');
                break;
            case 2:
                $uniObshtoSecond = number_format($uniObshto * UnicreditConfig::EUR_BGN_RATE, 2, '.', '');
                $uniMesecnaSecond = number_format($uniMesecna * UnicreditConfig::EUR_BGN_RATE, 2, '.', '');
                $uniObshtozaplashtaneSecond = number_format($uniObshtozaplashtane * UnicreditConfig::EUR_BGN_RATE, 2, '.', '');
                break;
            case 3:
                break;
        }

        return [
            'obshto_second'            => $uniObshtoSecond,
            'mesecna_second'           => $uniMesecnaSecond,
            'obshtozaplashtane_second' => $uniObshtozaplashtaneSecond,
        ];
    }

    /**
     * @param array<string, mixed> $stats
     *
     * @return array{kimb: array<string, string>, glp: array<string, string>}
     */
    private function kimbGlpArraysFromStats(array $stats): array
    {
        $months = [3, 4, 5, 6, 9, 10, 12, 15, 18, 24, 30, 36];
        $kimb = [];
        $glp = [];
        foreach ($months as $m) {
            $kimb[(string) $m] = (string) ($stats['kimb_' . $m] ?? '');
            $glp[(string) $m] = (string) ($stats['glp_' . $m] ?? '');
        }

        return ['kimb' => $kimb, 'glp' => $glp];
    }

    /**
     * Скрити полета за KIMB/GLP по срокове (като assign за продуктовия блок).
     *
     * @param array<string, mixed> $stats
     *
     * @return list<array{m: int, glp: string, kimb: string}>
     */
    private function buildUniKimbHiddenFieldsFromStatsArray(array $stats): array
    {
        $kg = $this->kimbGlpArraysFromStats($stats);
        $kimbArr = $kg['kimb'];
        $glpArr = $kg['glp'];
        $uniKimbHiddenFields = [];
        foreach (self::KIMB_BANK_INSTALLMENT_COUNTS as $m) {
            $ms = (string) $m;
            $uniKimbHiddenFields[] = [
                'm'    => $m,
                'glp'  => (string) ($glpArr[$ms] ?? ''),
                'kimb' => (string) ($kimbArr[$ms] ?? ''),
            ];
        }

        return $uniKimbHiddenFields;
    }

    /**
     * При промяна на редова сума (бройка/опции): ако за някой срок стандартният/промо KOP
     * при тази сума се различава от този при единичната цена, презарежда коефициентите от банката
     * и обновява кеша в DB; иначе връща текущите stats без банково повикване.
     *
     * @return array{success: bool, refreshed?: bool, uni_kimb_hidden_fields?: list<array{m: int, glp: string, kimb: string}>}
     */
    public function refreshKimbHiddenFieldsForProductLine(int $productId, float $lineTotalDisplayCurrency): array
    {
        if ($productId <= 0 || $lineTotalDisplayCurrency <= 0) {
            return ['success' => false];
        }

        $uniStatus = (int) $this->config->get($this->module . '_status');
        if ($uniStatus <= 0) {
            return ['success' => false];
        }

        $unicid = trim((string) $this->config->get($this->module . '_unicid'));
        if ($unicid === '') {
            return ['success' => false];
        }

        $paramsuni = $this->fetchUniParamsFromBankAndCache($unicid, false);
        if (!is_array($paramsuni) || (($paramsuni['uni_status'] ?? '') !== 'Yes')) {
            return ['success' => false];
        }

        $currencyCode = (string) ($this->session->data['currency'] ?? 'BGN');
        if ($currencyCode !== 'EUR' && $currencyCode !== 'BGN') {
            return ['success' => false];
        }

        $this->load->model('catalog/product');
        $productInfo = $this->model_catalog_product->getProduct($productId);
        if (!$productInfo) {
            return ['success' => false];
        }

        $rawPrice = (float) ($productInfo['special'] ?: $productInfo['price']);
        $taxed = (float) $this->tax->calculate($rawPrice, (int) $productInfo['tax_class_id'], $this->config->get('config_tax'));
        $unitDisplayPrice = (float) $this->currency->convert($taxed, $this->config->get('config_currency'), $currencyCode);

        $productCategoryRoots = $this->getProductRootCategoryIdsOrdered($productId);
        if ($productCategoryRoots === []) {
            return ['success' => false];
        }

        $uniCategoriesKop = $this->loadKopMappingFromDb();
        $uniKey = $this->findKopRowIndexForProductCategories($uniCategoriesKop, $productCategoryRoots);
        if ($uniKey === false) {
            return ['success' => false];
        }

        $row = &$uniCategoriesKop[$uniKey];
        $stats = isset($row['stats']) && is_array($row['stats']) ? $row['stats'] : [];

        $uniShemaCurrent = (int) ($paramsuni['uni_shema_current'] ?? 12);
        $uniService = (int) ($paramsuni['uni_testenv'] ?? 0) === 1
            ? (string) ($paramsuni['uni_test_service'] ?? '')
            : (string) ($paramsuni['uni_production_service'] ?? '');
        $uniUser = html_entity_decode((string) ($paramsuni['uni_user'] ?? ''), ENT_QUOTES, 'UTF-8');
        $uniPassword = html_entity_decode((string) ($paramsuni['uni_password'] ?? ''), ENT_QUOTES, 'UTF-8');
        $useCert = (($paramsuni['uni_sertificat'] ?? '') === 'Yes');

        $lastLineFloat = $this->lineTotalUsedForStoredKimbMeta($stats, $unitDisplayPrice);

        $mustRefetch = false;
        foreach (self::KIMB_BANK_INSTALLMENT_COUNTS as $cnt) {
            $kopNow = $this->resolveKopCode($uniCategoriesKop, $uniKey, $paramsuni, $lineTotalDisplayCurrency, $cnt);
            $kopLast = $this->resolveKopCode($uniCategoriesKop, $uniKey, $paramsuni, $lastLineFloat, $cnt);
            if ($kopNow !== $kopLast) {
                $mustRefetch = true;

                break;
            }
        }

        if (
            !$mustRefetch
            && $this->canUseBankCoeffApi($uniService, $uniUser, $uniPassword)
            && $this->statsHasAnyKimbSlot($stats)
            && $this->storedKimbDriftsFromBankCacheForLine(
                $stats,
                $uniCategoriesKop,
                $uniKey,
                $paramsuni,
                $lineTotalDisplayCurrency,
                $uniUser,
                $useCert
            )
        ) {
            $mustRefetch = true;
        }

        if (!$mustRefetch) {
            return [
                'success'                => true,
                'refreshed'              => false,
                'uni_kimb_hidden_fields' => $this->buildUniKimbHiddenFieldsFromStatsArray($stats),
            ];
        }

        if (!$this->canUseBankCoeffApi($uniService, $uniUser, $uniPassword)) {
            return [
                'success'                => true,
                'refreshed'              => false,
                'uni_kimb_hidden_fields' => $this->buildUniKimbHiddenFieldsFromStatsArray($stats),
            ];
        }

        if (!isset($row['stats']) || !is_array($row['stats'])) {
            $row['stats'] = [];
        }
        foreach (self::KIMB_BANK_INSTALLMENT_COUNTS as $cnt) {
            $kopForCnt = $this->resolveKopCode($uniCategoriesKop, $uniKey, $paramsuni, $lineTotalDisplayCurrency, $cnt);
            if ($kopForCnt === '') {
                continue;
            }
            $fetched = $this->fetchCoeffWithFileCache($uniService, $uniUser, $uniPassword, $kopForCnt, $cnt, $useCert);
            if ($fetched !== null && $fetched['kimb'] > 0) {
                $row['stats']['kimb_' . $cnt] = (string) $fetched['kimb'];
                $row['stats']['glp_' . $cnt] = (string) $fetched['glp'];
            }
        }
        $kimbForCurrent = $this->kimbFromStatsForInstallments($row['stats'], $uniShemaCurrent);
        $row['kimb'] = $kimbForCurrent > 0 ? (string) $kimbForCurrent : '';
        $row['kimb_time'] = (string) time();
        $row['stats'][self::STAT_LINE_TOTAL_USED_FOR_KIMB] = number_format(
            $lineTotalDisplayCurrency,
            2,
            '.',
            ''
        );
        $this->persistKopRuntimeData($row);
        $statsAfter = isset($row['stats']) && is_array($row['stats']) ? $row['stats'] : [];

        return [
            'success'                => true,
            'refreshed'              => true,
            'uni_kimb_hidden_fields' => $this->buildUniKimbHiddenFieldsFromStatsArray($statsAfter),
        ];
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function lineTotalUsedForStoredKimbMeta(array $stats, float $unitDisplayPrice): float
    {
        $raw = $stats[self::STAT_LINE_TOTAL_USED_FOR_KIMB] ?? null;
        if ($raw === null || trim((string) $raw) === '') {
            return $unitDisplayPrice;
        }
        $f = (float) str_replace(',', '.', (string) $raw);

        return $f > 0 ? $f : $unitDisplayPrice;
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function statsHasAnyKimbSlot(array $stats): bool
    {
        foreach (self::KIMB_BANK_INSTALLMENT_COUNTS as $m) {
            $raw = trim((string) ($stats['kimb_' . $m] ?? ''));
            if ($raw !== '' && (float) str_replace(',', '.', $raw) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Записаните KIMB в stats съвпадат ли с кеша за KOP, избран по текущата редова сума (лови стари промо KIMB при връщане под репер без meta).
     *
     * @param array<string, mixed>              $stats
     * @param array<int, array<string, mixed>> $uniCategoriesKop
     * @param array<string, mixed>              $paramsuni
     */
    private function storedKimbDriftsFromBankCacheForLine(
        array $stats,
        array $uniCategoriesKop,
        int|string $uniKey,
        array $paramsuni,
        float $lineTotalDisplayCurrency,
        string $uniUser,
        bool $useCert
    ): bool {
        foreach (self::KIMB_BANK_INSTALLMENT_COUNTS as $cnt) {
            $kop = $this->resolveKopCode($uniCategoriesKop, $uniKey, $paramsuni, $lineTotalDisplayCurrency, $cnt);
            if ($kop === '') {
                continue;
            }
            $cached = $this->readBankCoeffCache($uniUser, $kop, $cnt, $useCert);
            if ($cached === null) {
                continue;
            }
            $storedRaw = trim((string) ($stats['kimb_' . $cnt] ?? ''));
            if ($storedRaw === '') {
                continue;
            }
            $stored = (float) str_replace(',', '.', $storedRaw);
            if ($stored <= 0) {
                continue;
            }
            if (abs($cached['kimb'] - $stored) > 1.0e-5) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function getProductRootCategoryIdsOrdered(int $productId): array
    {
        $this->load->model('catalog/product');
        $this->load->model('catalog/category');
        $rows = $this->model_catalog_product->getCategories($productId);
        $ordered = [];
        $seen = [];
        foreach ($rows as $row) {
            $cid = (int) ($row['category_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $root = $this->resolveRootCategoryId($cid);
            if ($root <= 0 || isset($seen[$root])) {
                continue;
            }
            $seen[$root] = true;
            $ordered[] = $root;
        }
        return $ordered;
    }

    private function resolveRootCategoryId(int $categoryId): int
    {
        $this->load->model('catalog/category');
        $seen = [];
        while ($categoryId > 0 && !isset($seen[$categoryId])) {
            $seen[$categoryId] = true;
            $cat = $this->model_catalog_category->getCategory($categoryId);
            if (!$cat) {
                return 0;
            }
            if ((int) $cat['parent_id'] === 0) {
                return $categoryId;
            }
            $categoryId = (int) $cat['parent_id'];
        }

        return 0;
    }

    /**
     * @param array<int, array<string, mixed>> $uniCategoriesKop
     * @param list<int>                        $productCategoryIds
     *
     * @return int|string|false
     */
    private function findKopRowIndexForProductCategories(array $uniCategoriesKop, array $productCategoryIds): int|string|false
    {
        if ($uniCategoriesKop === [] || $productCategoryIds === []) {
            return false;
        }
        $catIds = array_column($uniCategoriesKop, 'category_id');
        $catIdsAsString = array_map('strval', $catIds);
        foreach ($productCategoryIds as $idCategory) {
            $idCategory = (int) $idCategory;
            $idx = array_search($idCategory, $catIds, true);
            if ($idx === false) {
                $idx = array_search((string) $idCategory, $catIdsAsString, true);
            }
            if ($idx !== false) {
                return $idx;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $uniCategoriesKop
     * @param array<string, mixed>              $paramsuni
     */
    private function resolveKopCode(array $uniCategoriesKop, int|string $uniKey, array $paramsuni, float $uniPrice, int $uniShemaCurrent): string
    {
        $row = $uniCategoriesKop[$uniKey];
        $uniPromoData = $paramsuni['uni_promo_data'] ?? '';
        $currDate = date('Y-m-d H:i');
        $dateTo = date('Y-m-d H:i', strtotime((string) $uniPromoData));
        $udata = $currDate <= $dateTo;
        $uniPromo = $paramsuni['uni_promo'] ?? '';
        $uniPromoMeseciZnak = $paramsuni['uni_promo_meseci_znak'] ?? '';
        $uniPromoMeseci = $paramsuni['uni_promo_meseci'] ?? '';
        $uniPromoPrice = $paramsuni['uni_promo_price'] ?? '';

        if ($uniPromo === 'Yes' && $udata) {
            if ($uniPromoMeseciZnak === 'eq') {
                $uniPromoMeseciArr = explode(',', (string) $uniPromoMeseci);
                if ($uniPrice >= (float) $uniPromoPrice && in_array($uniShemaCurrent, $uniPromoMeseciArr, false)) {
                    $kop = $row['promo'] ?? '';
                    if ($kop === '' || $kop === null) {
                        $kop = $row['kop'] ?? '';
                    }

                    return (string) $kop;
                }

                return (string) ($row['kop'] ?? '');
            }
            $uniPromoMeseciArr = explode(',', (string) $uniPromoMeseci);
            $first = $uniPromoMeseciArr[0] ?? '0';
            if ($uniPrice >= (float) $uniPromoPrice && $uniShemaCurrent >= (int) $first) {
                $kop = $row['promo'] ?? '';
                if ($kop === '' || $kop === null) {
                    $kop = $row['kop'] ?? '';
                }

                return (string) $kop;
            }

            return (string) ($row['kop'] ?? '');
        }

        return (string) ($row['kop'] ?? '');
    }

    private function detectDevice(string $useragent): string
    {
        if (
            preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i', $useragent)
            || preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i', substr($useragent, 0, 4))
        ) {
            return 'mobile';
        }

        return 'pc';
    }

    /**
     * @param array<string, string> $texts
     *
     * @return array<string, string>
     */
    private function resolveUiTexts(array $texts): array
    {
        return [
            'uni_meseci_txt'           => $texts['months_desktop'] ?? '',
            'uni_vnoska_txt'           => $texts['installment_desktop'] ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function kimbFromStatsForInstallments(array $stats, int $installments): float
    {
        $key = 'kimb_' . $installments;
        if (!isset($stats[$key])) {
            return 0.0;
        }
        $raw = trim((string) $stats[$key]);
        if ($raw === '') {
            return 0.0;
        }

        return (float) str_replace(',', '.', $raw);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadKopMappingFromDb(): array
    {
        $table = $this->table(UnicreditConfig::TABLE_KOP_MAPPING);
        $q = $this->db->query("SELECT `category_id`, `kop`, `promo`, `kimb`, `kimb_time`, `stats` FROM `{$table}`");
        $out = [];
        foreach ($q->rows as $dbRow) {
            $statsRaw = (string) ($dbRow['stats'] ?? '');
            $statsDecoded = json_decode($statsRaw, true);
            $out[] = [
                'category_id' => (int) ($dbRow['category_id'] ?? 0),
                'kop'         => (string) ($dbRow['kop'] ?? ''),
                'promo'       => (string) ($dbRow['promo'] ?? ''),
                'kimb'        => (string) ($dbRow['kimb'] ?? ''),
                'kimb_time'   => (string) ($dbRow['kimb_time'] ?? ''),
                'stats'       => is_array($statsDecoded) ? $statsDecoded : [],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function persistKopRuntimeData(array $row): void
    {
        $categoryId = (int) ($row['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return;
        }
        $stats = isset($row['stats']) && is_array($row['stats']) ? $row['stats'] : [];
        $statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);
        if (!is_string($statsJson)) {
            return;
        }
        $table = $this->table(UnicreditConfig::TABLE_KOP_MAPPING);
        $now = date('Y-m-d H:i:s');
        $this->db->query(
            "UPDATE `{$table}` SET `kimb` = '" . $this->db->escape((string) ($row['kimb'] ?? '')) . "', `kimb_time` = '" . (int) ($row['kimb_time'] ?? 0) . "', `stats` = '" . $this->db->escape($statsJson) . "', `date_upd` = '" . $this->db->escape($now) . "' WHERE `category_id` = '" . (int) $categoryId . "'"
        );
    }

    private function canUseBankCoeffApi(string $service, string $user, string $password): bool
    {
        return trim($service) !== '' && trim($user) !== '' && trim($password) !== '';
    }

    /**
     * @return array{kimb: float, glp: float}|null
     */
    private function fetchCoeffWithFileCache(
        string $serviceUrl,
        string $user,
        string $password,
        string $kop,
        int $installments,
        bool $useCert
    ): ?array {
        if (trim($kop) === '' || $installments <= 0) {
            return null;
        }
        $cached = $this->readBankCoeffCache($user, $kop, $installments, $useCert);
        if ($cached !== null) {
            return $cached;
        }
        $fetched = $this->fetchCoeffFromBank($serviceUrl, $user, $password, $kop, $installments, $useCert);
        if ($fetched !== null && $fetched['kimb'] > 0) {
            $this->writeBankCoeffCache($user, $kop, $installments, $useCert, $fetched);

            return $fetched;
        }

        return null;
    }

    /**
     * @return array{kimb: float, glp: float}|null
     */
    private function readBankCoeffCache(string $user, string $kop, int $installments, bool $useCert): ?array
    {
        $cacheKey = $this->bankCoeffCacheKey($user, $kop, $installments, $useCert);
        $table = $this->table(UnicreditConfig::TABLE_API_CACHE);
        $q = $this->db->query(
            "SELECT `payload`, `date_upd` FROM `{$table}` WHERE `cache_key` = '" . $this->db->escape($cacheKey) . "' LIMIT 1"
        );
        if (!$q->num_rows) {
            return null;
        }
        $updatedTs = strtotime((string) ($q->row['date_upd'] ?? ''));
        if ($updatedTs === false || (time() - (int) $updatedTs) >= UnicreditConfig::API_CACHE_TTL_COEFF) {
            return null;
        }
        $data = json_decode((string) ($q->row['payload'] ?? ''), true);
        if (!is_array($data) || !isset($data['kimb'])) {
            return null;
        }

        return [
            'kimb' => (float) $data['kimb'],
            'glp'  => isset($data['glp']) ? (float) $data['glp'] : 0.0,
        ];
    }

    /**
     * @param array{kimb: float, glp: float} $payload
     */
    private function writeBankCoeffCache(string $user, string $kop, int $installments, bool $useCert, array $payload): void
    {
        $cacheKey = $this->bankCoeffCacheKey($user, $kop, $installments, $useCert);
        $json = json_encode(['kimb' => $payload['kimb'], 'glp' => $payload['glp']], JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }
        $table = $this->table(UnicreditConfig::TABLE_API_CACHE);
        $now = date('Y-m-d H:i:s');
        $q = $this->db->query("SELECT `id_mt_uni_credit_api_cache` FROM `{$table}` WHERE `cache_key` = '" . $this->db->escape($cacheKey) . "' LIMIT 1");
        if ($q->num_rows) {
            $this->db->query(
                "UPDATE `{$table}` SET `cache_group` = 'coeff', `payload` = '" . $this->db->escape($json) . "', `date_upd` = '" . $this->db->escape($now) . "' WHERE `cache_key` = '" . $this->db->escape($cacheKey) . "'"
            );
        } else {
            $this->db->query(
                "INSERT INTO `{$table}` SET `cache_group` = 'coeff', `cache_key` = '" . $this->db->escape($cacheKey) . "', `payload` = '" . $this->db->escape($json) . "', `date_add` = '" . $this->db->escape($now) . "', `date_upd` = '" . $this->db->escape($now) . "'"
            );
        }
    }

    private function bankCoeffCacheKey(string $user, string $kop, int $installments, bool $useCert): string
    {
        return 'coeff:' . md5($user . '|' . $kop . '|' . $installments . '|' . ($useCert ? '1' : '0'));
    }

    /**
     * @return array{kimb: float, glp: float}|null
     */
    private function fetchCoeffFromBank(
        string $serviceUrl,
        string $user,
        string $password,
        string $kop,
        int $installments,
        bool $useCert
    ): ?array {
        $url = rtrim($serviceUrl, '/') . '/getCoeff';
        $body = http_build_query([
            'user'              => $user,
            'pass'              => $password,
            'onlineProductCode' => $kop,
            'installmentCount'  => (string) $installments,
        ], '', '&');

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
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
            $paths = $this->ensureClientPemMaterial();
            if ($paths === null) {
                return null;
            }
            $opts[CURLOPT_SSLKEY] = $paths['key'];
            $opts[CURLOPT_SSLKEYPASSWD] = self::CLIENT_PEM_PASSPHRASE;
            $opts[CURLOPT_SSLCERT] = $paths['cert'];
            $opts[CURLOPT_SSLCERTPASSWD] = self::CLIENT_PEM_PASSPHRASE;
        }

        $ch = curl_init();
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);

        if ($response === false || $response === '') {
            return null;
        }

        $obj = json_decode((string) $response);
        if (!is_object($obj) || empty($obj->coeffList) || empty($obj->coeffList[0])) {
            return null;
        }

        $first = $obj->coeffList[0];
        $kimb = isset($first->coeff) ? (float) $first->coeff : 0.0;
        $glp = isset($first->interestPercent) ? (float) $first->interestPercent : 0.0;
        if ($kimb <= 0) {
            return null;
        }

        return ['kimb' => $kimb, 'glp' => $glp];
    }

    /**
     * @return array{key: string, cert: string}|null
     */
    private function ensureClientPemMaterial(): ?array
    {
        $base = rtrim(UnicreditConfig::LIVE_URL, '/');
        $keyUrl = $base . '/calculators/key/avalon_private_key.pem';
        $certUrl = $base . '/calculators/key/avalon_cert.pem';
        $root = \DIR_EXTENSION . 'mt_uni_credit/';
        $keyPath = $root . 'keys/avalon_private_key.pem';
        $certPath = $root . 'keys/avalon_cert.pem';

        if (!$this->downloadUrlToFile($keyUrl, $keyPath) || !$this->downloadUrlToFile($certUrl, $certPath)) {
            return null;
        }

        return ['key' => $keyPath, 'cert' => $certPath];
    }

    private function downloadUrlToFile(string $url, string $destination): bool
    {
        $allowedPrefix = rtrim(UnicreditConfig::LIVE_URL, '/');
        if (!str_starts_with($url, $allowedPrefix)) {
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($data === false || $code !== 200) {
            return false;
        }
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($destination, $data) !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchUniParamsFromBankAndCache(string $unicid, bool $forceReload): ?array
    {
        $unicid = trim($unicid);
        if ($unicid === '') {
            return null;
        }
        $cacheKey = 'params:' . md5($unicid);
        if (!$forceReload) {
            $cached = $this->readApiCachePayload($cacheKey, UnicreditConfig::API_CACHE_TTL_PARAMS);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $url = rtrim(UnicreditConfig::LIVE_URL, '/') . UnicreditConfig::BANK_GETPARAMETERS_PATH . '?cid=' . rawurlencode($unicid);
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false || $httpCode !== 200) {
            return null;
        }
        $params = json_decode((string) $response, true);
        if (!is_array($params)) {
            return null;
        }
        $this->writeApiCachePayload($cacheKey, 'params', $params);

        return $params;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchUniCalculationFromBankAndCache(string $unicid, string $deviceis, bool $forceReload): ?array
    {
        $unicid = trim($unicid);
        if ($unicid === '') {
            return null;
        }
        $cacheKey = 'calc:' . md5($unicid . '_' . $deviceis);
        if (!$forceReload) {
            $cached = $this->readApiCachePayload($cacheKey, UnicreditConfig::API_CACHE_TTL_PARAMS);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $url = rtrim(UnicreditConfig::LIVE_URL, '/') . '/function/getcalculation.php?cid=' . rawurlencode($unicid) . '&deviceis=' . rawurlencode($deviceis);
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false || $httpCode !== 200) {
            return null;
        }
        $params = json_decode((string) $response, true);
        if (!is_array($params)) {
            return null;
        }
        $this->writeApiCachePayload($cacheKey, 'calc', $params);

        return $params;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readApiCachePayload(string $cacheKey, int $ttlSeconds): ?array
    {
        $table = $this->table(UnicreditConfig::TABLE_API_CACHE);
        $q = $this->db->query(
            "SELECT `payload`, `date_upd` FROM `{$table}` WHERE `cache_key` = '" . $this->db->escape($cacheKey) . "' LIMIT 1"
        );
        if (!$q->num_rows) {
            return null;
        }
        $updatedTs = strtotime((string) ($q->row['date_upd'] ?? ''));
        if ($updatedTs === false || (time() - $updatedTs) >= $ttlSeconds) {
            return null;
        }
        $payload = json_decode((string) ($q->row['payload'] ?? ''), true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeApiCachePayload(string $cacheKey, string $group, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }
        $table = $this->table(UnicreditConfig::TABLE_API_CACHE);
        $now = date('Y-m-d H:i:s');
        $q = $this->db->query(
            "SELECT `id_mt_uni_credit_api_cache` FROM `{$table}` WHERE `cache_key` = '" . $this->db->escape($cacheKey) . "' LIMIT 1"
        );
        if ($q->num_rows) {
            $this->db->query(
                "UPDATE `{$table}` SET `cache_group` = '" . $this->db->escape($group) . "', `payload` = '" . $this->db->escape($json) . "', `date_upd` = '" . $this->db->escape($now) . "' WHERE `cache_key` = '" . $this->db->escape($cacheKey) . "'"
            );
        } else {
            $this->db->query(
                "INSERT INTO `{$table}` SET `cache_group` = '" . $this->db->escape($group) . "', `cache_key` = '" . $this->db->escape($cacheKey) . "', `payload` = '" . $this->db->escape($json) . "', `date_add` = '" . $this->db->escape($now) . "', `date_upd` = '" . $this->db->escape($now) . "'"
            );
        }
    }

    private function table(string $nameWithoutPrefix): string
    {
        return DB_PREFIX . $nameWithoutPrefix;
    }
}
