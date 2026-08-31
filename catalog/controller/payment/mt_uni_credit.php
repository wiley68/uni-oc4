<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Payment;

use Opencart\Catalog\Model\Extension\MtUniCredit\Module\MtUniCreditCheckout as CheckoutFinancingModel;
use Opencart\System\Library\Extension\MtUniCredit\CartFingerprint;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutOperationIdentity;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutSelectionHash;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\ModuleAssetVersion;
use Opencart\System\Library\Extension\MtUniCredit\ModuleLocalSettings;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\ProductStorefrontCsrf;
use Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationFlags;
use Opencart\System\Library\Extension\MtUniCredit\SubmissionTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\UnavailableSchemeException;

/**
 * Class MtUniCredit
 *
 * Checkout payment UI + issue/confirm/calculate JSON endpoints.
 */
class MtUniCredit extends \Opencart\System\Engine\Controller
{
    public function index(): string
    {
        $this->load->language('extension/mt_uni_credit/payment/mt_uni_credit');

        if (!$this->hasValidCheckoutSession()) {
            return '';
        }

        $model = $this->checkoutModel();
        $order = $model->resolveSessionOrder();
        if ($order === null) {
            return '';
        }

        $shop = $model->getShopConfiguration();
        if ($shop === null) {
            return '';
        }

        $orderTotal = $model->financingAmountForOrder($order);
        $cart = $model->createCartContextForOrderTotal($orderTotal);
        $currency = (string) ($order['currency_code'] ?? $this->session->data['currency'] ?? $this->config->get('config_currency'));
        $presenter = $model->createCalculatorPresenter()->present($shop, $cart, $currency);
        if ($presenter === null) {
            return '';
        }

        $presenter['source'] = 'checkout';
        $presenter['order_id'] = (int) $order['order_id'];
        $presenter['price'] = $orderTotal;

        $customer = $model->customerPrefillFromOrder($order);
        $buttonAction = ModuleLocalSettings::normalizeProductButtonAction(
            (string) ($this->config->get(ModuleLocalSettings::PRODUCT_BUTTON_ACTION) ?? '')
        );
        $modal = $model->createModalPresenter()->present($shop, $customer, $buttonAction);

        $csrf = new ProductStorefrontCsrf();
        $csrfToken = $csrf->getOrCreate($this->session->data);
        $language = (string) $this->config->get('config_language');
        $assetBase = 'extension/mt_uni_credit/catalog/view/image/product/';

        $this->document->addStyle(ModuleAssetVersion::href('catalog/view/stylesheet/mt_uni_credit_fonts.css'));
        $this->document->addStyle(ModuleAssetVersion::href('catalog/view/stylesheet/mt_uni_credit_product.css'));
        $this->document->addStyle(ModuleAssetVersion::href('catalog/view/stylesheet/mt_uni_credit_checkout.css'));
        $this->document->addScript(
            ModuleAssetVersion::href('catalog/view/javascript/mt_uni_credit_redirect.js'),
            'footer'
        );
        $this->document->addScript(
            ModuleAssetVersion::href('catalog/view/javascript/mt_uni_credit_checkout.js'),
            'footer'
        );

        $issueUrl = $this->url->link(
            'extension/mt_uni_credit/payment/mt_uni_credit.issueSubmission',
            'language=' . $language,
            true
        );
        $confirmUrl = $this->url->link(
            'extension/mt_uni_credit/payment/mt_uni_credit.confirm',
            'language=' . $language,
            true
        );
        $calculateUrl = $this->url->link(
            'extension/mt_uni_credit/payment/mt_uni_credit.calculate',
            'language=' . $language,
            true
        );

        $data = [];
        $languageKeys = [
            'heading_title',
            'button_confirm',
            'text_loading',
            'text_price',
            'text_months',
            'text_months_short',
            'text_first_installment',
            'text_financed_amount',
            'text_monthly_installment',
            'text_monthly_installment_short',
            'text_total_payable',
            'text_glp',
            'text_gpr',
            'text_consents',
            'text_egn',
            'text_phone2',
            'text_required',
            'text_processing_title',
            'text_processing_message',
            'text_local_order_prepared',
            'error_order',
            'error_payment_method',
        ];
        foreach ($languageKeys as $key) {
            $data[$key] = $this->language->get($key);
        }

        $data['language'] = $language;
        $data['order_id'] = (int) $order['order_id'];
        $data['consents'] = $modal['consents'] ?? [];
        $data['show_first_installment'] = !empty($presenter['show_first_installment']);
        $data['badge_url'] = $assetBase . 'uni_mini_logo.png';
        $secondaryProcess = ShopConfigurationFlags::isSecondaryProcess($shop);
        $data['process2'] = $secondaryProcess;
        $data['checkout_helper'] = $this->language->get(
            $secondaryProcess ? 'text_checkout_helper_process2' : 'text_checkout_helper_process1'
        );
        $data['mt_uni_credit_bootstrap_json'] = json_encode([
            'source'               => 'checkout',
            'order_id'             => (int) $order['order_id'],
            'calculator'           => $presenter,
            'modal'                => $modal,
            'logo_standard_url'    => $assetBase . 'uni_logo.svg',
            'logo_alternative_url' => $assetBase . 'uni_logo_red.svg',
            'badge_url'            => $assetBase . 'uni_mini_logo.png',
            'issue_url'            => $issueUrl,
            'confirm_url'          => $confirmUrl,
            'calculate_url'        => $calculateUrl,
            'csrf_token'           => $csrfToken,
            'fonts_href'           => ModuleAssetVersion::href('catalog/view/stylesheet/mt_uni_credit_fonts.css'),
            'product_css_href'     => ModuleAssetVersion::href('catalog/view/stylesheet/mt_uni_credit_product.css'),
            'checkout_css_href'    => ModuleAssetVersion::href('catalog/view/stylesheet/mt_uni_credit_checkout.css'),
            'script_href'          => ModuleAssetVersion::href('catalog/view/javascript/mt_uni_credit_checkout.js'),
            'i18n'                 => [
                'order_changed' => (string) $this->language->get('error_order_changed'),
                'order_missing' => (string) $this->language->get('error_order'),
                'confirm'       => (string) $this->language->get('button_confirm'),
                'processing'    => (string) $this->language->get('text_processing_message'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->load->view('extension/mt_uni_credit/payment/mt_uni_credit', $data);
    }

    public function calculate(): void
    {
        $this->respondJson(function (): array {
            $this->assertPostWithCsrf();
            $this->assertCheckoutPaymentSession();

            $model = $this->checkoutModel();
            $order = $model->resolveSessionOrder();
            if ($order === null) {
                return $this->errorPayload('checkout_order_missing', $this->language->get('error_order'));
            }

            $shop = $model->getShopConfiguration();
            if ($shop === null) {
                return $this->errorPayload('configuration_unavailable', 'Калкулаторът временно не е наличен.');
            }

            $orderTotal = $model->financingAmountForOrder($order);
            $cart = $model->createCartContextForOrderTotal($orderTotal);
            if ($cart->lines === [] || $cart->total <= 0.0) {
                return $this->errorPayload('checkout_order_changed', $this->language->get('error_order_changed'));
            }

            $currency = (string) ($order['currency_code'] ?? $this->session->data['currency'] ?? $this->config->get('config_currency'));
            $presenter = $model->createCalculatorPresenter()->present($shop, $cart, $currency);
            if ($presenter === null) {
                return $this->errorPayload('no_schemes', 'Няма налични схеми за тази поръчка.');
            }

            $presenter['source'] = 'checkout';
            $presenter['order_id'] = (int) $order['order_id'];

            return [
                'success'    => true,
                'sequence'   => (int) ($this->request->post['sequence'] ?? 0),
                'calculator' => $presenter,
            ];
        });
    }

    public function issueSubmission(): void
    {
        $this->respondJson(function (): array {
            $this->assertPostWithCsrf();
            $this->assertCheckoutPaymentSession();

            $model = $this->checkoutModel();
            $shop = $model->getShopConfiguration();
            if ($shop === null) {
                return $this->errorPayload('configuration_unavailable', 'Заявката временно не е налична.');
            }

            $context = $this->readSelectionContext($model);
            $issuer = $model->createSubmissionIssuer();
            $attempt = $issuer->issueOrReuse(
                $context['store_id'],
                $context['operation_key_hash'],
                $context['actor_binding_hash'],
                $context['selection_hash'],
                trim((string) ($this->request->post['submission_token'] ?? '')),
                null,
                $context['cart_fingerprint']
            );
            $token = (string) ($attempt['submission_token'] ?? '');
            if (!SubmissionTokenGenerator::isValidFormat($token)) {
                return $this->errorPayload('technical_failure', 'Заявката не може да бъде обработена.');
            }

            return [
                'success'          => true,
                'step'             => 'submission_token_issued',
                'submission_token' => $token,
                'calculation'      => $context['calculation'],
            ];
        });
    }

    public function confirm(): void
    {
        $this->respondJson(function (): array {
            $this->load->language('extension/mt_uni_credit/payment/mt_uni_credit');
            $this->assertPostWithCsrf();
            $this->assertCheckoutPaymentSession();

            $model = $this->checkoutModel();
            $order = $model->resolveSessionOrder();
            if ($order === null) {
                return $this->errorPayload('checkout_order_missing', $this->language->get('error_order'));
            }

            $shop = $model->getShopConfiguration();
            if ($shop === null) {
                return $this->errorPayload('configuration_unavailable', 'Заявката временно не е налична.');
            }

            $context = $this->readSelectionContext($model);
            $materials = $model->buildOrderMaterialsFromOrder((int) $order['order_id']);
            $meta = $model->shopCacheMeta();
            $service = $model->createSubmissionService();

            try {
                $result = $service->submit(
                    $shop,
                    $context['store_id'],
                    trim((string) ($this->request->post['submission_token'] ?? '')),
                    $context['actor_binding_hash'],
                    \Opencart\System\Library\Extension\MtUniCredit\CartActorBinding::sessionFingerprint((string) $this->session->getId()),
                    $this->customer->isLogged() ? (int) $this->customer->getId() : (int) ($order['customer_id'] ?? 0),
                    (int) ($order['customer_group_id'] ?? $this->config->get('config_customer_group_id')),
                    $context['cart'],
                    $context['cart_fingerprint'],
                    $context['currency'],
                    $context['popup_type'],
                    $context['scheme_type'],
                    $context['kop_code'],
                    $context['months'],
                    $context['filter_id'],
                    $context['scheme_key'],
                    $context['first_installment'],
                    $this->request->post,
                    $materials['products'],
                    $materials['totals'],
                    $materials['order_total'],
                    $materials['shipping_required'],
                    (string) ($meta['unicid'] ?? ''),
                    (string) ($meta['fetched_at'] ?? gmdate('Y-m-d H:i:s')),
                    (int) ($order['language_id'] ?? $this->config->get('config_language_id')),
                    (string) ($order['language_code'] ?? $this->config->get('config_language')),
                    (int) ($order['currency_id'] ?? $this->currency->getId($context['currency'])),
                    (float) ($order['currency_value'] ?? $this->currency->getValue($context['currency'])),
                    (string) ($order['store_name'] ?? $this->config->get('config_name')),
                    (string) ($order['store_url'] ?? $this->config->get('config_url') ?? ''),
                    (string) ($order['invoice_prefix'] ?? $this->config->get('config_invoice_prefix') ?? ''),
                    LockOwnerTokenGenerator::generate(),
                    (int) $order['order_id'],
                    $order,
                    (string) ($this->request->server['REMOTE_ADDR'] ?? '127.0.0.1'),
                    $model->storeAddressDefaults(),
                    $model->sessionCheckoutData(),
                    $model->verifiedOwnedAddressForOrder($order)
                );
            } catch (ProductFinancingFlowException $exception) {
                if ($exception->errorCode() === 'technical_failure') {
                    $this->logFailure($exception->getPrevious() ?? $exception);
                }
                http_response_code($exception->httpStatus());

                return [
                    'success'    => false,
                    'error_code' => $exception->errorCode(),
                    'message'    => $exception->getMessage(),
                    'errors'     => $exception->fieldErrors(),
                ];
            }

            if (\Opencart\System\Library\Extension\MtUniCredit\FinancingTerminalNavigationSupport::isSmartUcfTerminalFailure($result)) {
                $payload = $result->toArray();
                $thankYouUrl = $this->url->link(
                    'checkout/success',
                    'language=' . $this->config->get('config_language'),
                    true
                );
                $payload = \Opencart\System\Library\Extension\MtUniCredit\FinancingTerminalNavigationSupport::enrichTerminalPayload(
                    $payload,
                    $result,
                    $thankYouUrl,
                    $this->session->data
                );

                return $payload;
            }

            $orderStatusId = (int) $this->config->get('payment_mt_uni_credit_order_status_id');
            if ($orderStatusId > 0) {
                $this->load->model('checkout/order');
                $this->model_checkout_order->addHistory((int) $order['order_id'], $orderStatusId);
            }

            $this->session->data['mt_uni_credit_checkout_success'] = $this->language->get('text_success_financing');

            $payload = $result->toArray();
            $thankYouUrl = $this->url->link(
                'checkout/success',
                'language=' . $this->config->get('config_language'),
                true
            );
            $payload = \Opencart\System\Library\Extension\MtUniCredit\FinancingTerminalNavigationSupport::enrichTerminalPayload(
                $payload,
                $result,
                $thankYouUrl,
                $this->session->data
            );
            $payload['redirect'] = $payload['redirect_url'] ?? $thankYouUrl;

            return $payload;
        });
    }

    private function assertPostWithCsrf(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            throw new \RuntimeException('method_not_allowed');
        }

        $csrf = new ProductStorefrontCsrf();
        $result = $csrf->validate(
            $this->session->data,
            (string) ($this->request->post['csrf_token'] ?? '')
        );
        if ($result === 'ok') {
            return;
        }

        http_response_code(403);
        throw new \RuntimeException($result);
    }

    private function assertCheckoutPaymentSession(): void
    {
        $this->load->language('extension/mt_uni_credit/payment/mt_uni_credit');

        if (!isset($this->session->data['order_id'])) {
            throw new ProductFinancingFlowException('checkout_order_missing', $this->language->get('error_order'));
        }

        $code = (string) ($this->session->data['payment_method']['code'] ?? '');
        if ($code !== PaymentIdentity::optionCode()) {
            throw new ProductFinancingFlowException('validation', $this->language->get('error_payment_method'));
        }
    }

    private function hasValidCheckoutSession(): bool
    {
        if (!isset($this->session->data['order_id'])) {
            return false;
        }

        return (string) ($this->session->data['payment_method']['code'] ?? '') === PaymentIdentity::optionCode();
    }

    /**
     * @return CheckoutFinancingModel|\Opencart\System\Engine\Proxy
     */
    private function checkoutModel(): object
    {
        $this->load->model('extension/mt_uni_credit/module/mt_uni_credit_checkout');

        return $this->model_extension_mt_uni_credit_module_mt_uni_credit_checkout;
    }

    /**
     * @param CheckoutFinancingModel|\Opencart\System\Engine\Proxy $model
     * @return array<string, mixed>
     */
    private function readSelectionContext(object $model): array
    {
        $order = $model->resolveSessionOrder();
        if ($order === null) {
            throw new ProductFinancingFlowException('checkout_order_missing', $this->language->get('error_order'));
        }

        $popupType = (string) ($this->request->post['popup_offer_type'] ?? 'standard');
        $schemeType = (string) ($this->request->post['scheme_type'] ?? '');
        $kopCode = trim((string) ($this->request->post['kop_code'] ?? ''));
        $months = (int) ($this->request->post['months'] ?? 0);
        $filterId = (int) ($this->request->post['filter_id'] ?? 0);
        $schemeKey = trim((string) ($this->request->post['scheme_key'] ?? ''));
        $firstInstallment = is_numeric($this->request->post['first_installment'] ?? null)
            ? (float) $this->request->post['first_installment']
            : 0.0;

        $storeId = (int) $this->config->get('config_store_id');
        $currency = (string) ($order['currency_code'] ?? $this->session->data['currency'] ?? $this->config->get('config_currency'));
        $shop = $model->getShopConfiguration();
        if ($shop === null) {
            throw new ProductFinancingFlowException('validation', 'Shop configuration unavailable.');
        }

        $orderTotal = $model->financingAmountForOrder($order);
        $cart = $model->createCartContextForOrderTotal($orderTotal);
        if ($cart->lines === [] || $cart->total <= 0.0) {
            throw new ProductFinancingFlowException(
                'checkout_order_changed',
                $this->language->get('error_order_changed')
            );
        }

        try {
            $calculation = $model->createSchemeCalculator()->calculate(
                $shop,
                $cart,
                $currency,
                $popupType,
                $schemeType,
                $kopCode,
                $months,
                $filterId,
                $schemeKey,
                $firstInstallment
            );
        } catch (UnavailableSchemeException $exception) {
            throw new ProductFinancingFlowException('unavailable_scheme', 'Избраната схема не е налична.', [], $exception);
        }

        $firstInstallment = (float) ($calculation['first_installment'] ?? $firstInstallment);

        $fingerprint = CartFingerprint::hash($cart, $currency);
        $postedFingerprint = trim((string) ($this->request->post['cart_fingerprint'] ?? ''));
        if ($postedFingerprint !== '' && !hash_equals($fingerprint, $postedFingerprint)) {
            throw new ProductFinancingFlowException(
                'checkout_order_changed',
                $this->language->get('error_order_changed')
            );
        }

        $actorBindingHash = $model->actorBindingHash();
        $orderId = (int) $order['order_id'];
        $selectionHash = CheckoutSelectionHash::hash(
            $storeId,
            $orderId,
            $fingerprint,
            $currency,
            $orderTotal,
            $schemeKey,
            $schemeType,
            $kopCode,
            $months,
            $filterId,
            $firstInstallment,
            $actorBindingHash
        );
        $operationKeyHash = CheckoutOperationIdentity::hash($storeId, $orderId);

        return [
            'store_id'           => $storeId,
            'order_id'           => $orderId,
            'cart'               => $cart,
            'currency'           => $currency,
            'popup_type'         => $popupType,
            'scheme_type'        => $schemeType,
            'kop_code'           => $kopCode,
            'months'             => $months,
            'filter_id'          => $filterId,
            'scheme_key'         => $schemeKey,
            'first_installment'  => $firstInstallment,
            'actor_binding_hash' => $actorBindingHash,
            'selection_hash'     => $selectionHash,
            'operation_key_hash' => $operationKeyHash,
            'cart_fingerprint'   => $fingerprint,
            'calculation'        => $calculation,
            'order_total'        => $orderTotal,
        ];
    }

    /**
     * @param callable(): array<string, mixed> $callback
     */
    private function respondJson(callable $callback): void
    {
        $json = [];
        try {
            $json = $callback();
        } catch (ProductFinancingFlowException $exception) {
            http_response_code($exception->httpStatus());
            $json = [
                'success'    => false,
                'error_code' => $exception->errorCode(),
                'message'    => $exception->getMessage(),
                'errors'     => $exception->fieldErrors(),
            ];
        } catch (UnavailableSchemeException) {
            http_response_code(409);
            $json = $this->errorPayload('stale_selection', 'Избраните условия вече не са налични.');
        } catch (\RuntimeException $exception) {
            $code = $exception->getMessage();
            if ($code === 'missing_csrf' || $code === 'invalid_csrf') {
                $json = $this->errorPayload($code, 'Невалидна сесия. Моля, презаредете страницата.');
            } elseif ($code === 'method_not_allowed') {
                $json = $this->errorPayload('validation', 'Невалиден метод.');
            } else {
                http_response_code(500);
                $this->logFailure($exception);
                $json = $this->errorPayload('technical_failure', 'Заявката не може да бъде обработена.');
            }
        } catch (\Throwable $exception) {
            http_response_code(500);
            $this->logFailure($exception);
            $json = $this->errorPayload('technical_failure', 'Заявката не може да бъде обработена.');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private function errorPayload(string $code, string $message): array
    {
        return [
            'success'    => false,
            'error_code' => $code,
            'message'    => $message,
        ];
    }

    private function logFailure(\Throwable $exception): void
    {
        $file = basename(str_replace('\\', '/', $exception->getFile()));
        $orderId = (int) ($this->session->data['order_id'] ?? 0);
        $storeId = (int) $this->config->get('config_store_id');
        $this->log->write(sprintf(
            'mt_uni_credit checkout technical_failure class=%s message=%s file=%s line=%d entry_point=checkout order_id=%d store_id=%d',
            $exception::class,
            $exception->getMessage(),
            $file,
            $exception->getLine(),
            $orderId,
            $storeId
        ));
    }
}
