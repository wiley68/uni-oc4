<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Module;

use Opencart\Catalog\Model\Extension\MtUniCredit\Module\MtUniCreditCart as CartFinancingModel;
use Opencart\System\Library\Extension\MtUniCredit\CartFingerprint;
use Opencart\System\Library\Extension\MtUniCredit\CartOperationIdentity;
use Opencart\System\Library\Extension\MtUniCredit\CartSelectionHash;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\ProductStorefrontCsrf;
use Opencart\System\Library\Extension\MtUniCredit\SubmissionTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\UnavailableSchemeException;

class MtUniCreditCart extends \Opencart\System\Engine\Controller
{
    public function calculate(): void
    {
        $this->respondJson(function (): array {
            $this->assertPostWithCsrf();
            $model = $this->cartModel();
            $shop = $model->getShopConfiguration();
            if ($shop === null) {
                return $this->errorPayload('configuration_unavailable', 'Калкулаторът временно не е наличен.');
            }

            $cart = $model->createCartContext();
            if ($cart->lines === [] || $cart->total <= 0.0) {
                return $this->errorPayload('cart_empty', 'Количката е празна.');
            }

            $currency = (string) ($this->session->data['currency'] ?? $this->config->get('config_currency'));
            $presenter = $model->createCalculatorPresenter()->present($shop, $cart, $currency);
            if ($presenter === null) {
                return $this->errorPayload('no_schemes', 'Няма налични схеми за тази количка.');
            }

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
            $model = $this->cartModel();
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

    public function submit(): void
    {
        $this->respondJson(function (): array {
            $this->assertPostWithCsrf();
            $model = $this->cartModel();
            $cartCountBefore = $model->countActiveCartProducts();
            $shop = $model->getShopConfiguration();
            if ($shop === null) {
                return $this->errorPayload('configuration_unavailable', 'Заявката временно не е налична.');
            }

            $context = $this->readSelectionContext($model);
            $materials = $model->buildOrderMaterials();
            $meta = $model->shopCacheMeta();
            $service = $model->createSubmissionService();
            try {
                $result = $service->submit(
                    $shop,
                    $context['store_id'],
                    trim((string) ($this->request->post['submission_token'] ?? '')),
                    $context['actor_binding_hash'],
                    \Opencart\System\Library\Extension\MtUniCredit\CartActorBinding::sessionFingerprint((string) $this->session->getId()),
                    $this->customer->isLogged() ? (int) $this->customer->getId() : 0,
                    (int) $this->config->get('config_customer_group_id'),
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
                    (int) $this->config->get('config_language_id'),
                    (string) $this->config->get('config_language'),
                    (int) $this->currency->getId($context['currency']),
                    (float) $this->currency->getValue($context['currency']),
                    (string) $this->config->get('config_name'),
                    (string) ($this->config->get('config_url') ?? ''),
                    (string) ($this->config->get('config_invoice_prefix') ?? ''),
                    LockOwnerTokenGenerator::generate(),
                    (string) ($this->request->server['REMOTE_ADDR'] ?? '127.0.0.1'),
                    $model->storeAddressDefaults(),
                    null
                );
            } catch (ProductFinancingFlowException $exception) {
                http_response_code($exception->httpStatus());

                return [
                    'success'    => false,
                    'error_code' => $exception->errorCode(),
                    'message'    => $exception->getMessage(),
                    'errors'     => $exception->fieldErrors(),
                ];
            }

            $payload = $result->toArray();
            $payload['cart_unchanged'] = $model->countActiveCartProducts() === $cartCountBefore;
            if ($result->success && $result->step === 'process2_prepared' && $result->orderId !== null) {
                $this->session->data['order_id'] = $result->orderId;
                $this->session->data['mt_uni_credit_success_order_id'] = $result->orderId;
                if (empty($payload['redirect_url'])) {
                    $payload['redirect_url'] = $this->url->link(
                        'checkout/success',
                        'language=' . $this->config->get('config_language'),
                        true
                    );
                }
            }

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

    /**
     * @return CartFinancingModel|\Opencart\System\Engine\Proxy
     */
    private function cartModel(): object
    {
        $this->load->model('extension/mt_uni_credit/module/mt_uni_credit_cart');

        return $this->model_extension_mt_uni_credit_module_mt_uni_credit_cart;
    }

    /**
     * @param CartFinancingModel|\Opencart\System\Engine\Proxy $model
     * @return array<string, mixed>
     */
    private function readSelectionContext(object $model): array
    {
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
        $currency = (string) ($this->session->data['currency'] ?? $this->config->get('config_currency'));
        $shop = $model->getShopConfiguration();
        if ($shop === null) {
            throw new ProductFinancingFlowException('validation', 'Shop configuration unavailable.');
        }

        $cart = $model->createCartContext();
        if ($cart->lines === [] || $cart->total <= 0.0) {
            throw new ProductFinancingFlowException('cart_empty', 'Количката е празна.');
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

        // Authoritative first installment from scheme calculation (locked schemes ignore raw POST 0).
        // Without this, issue hashes 0 then submit hashes the rendered mandatory amount → false stale_selection
        // (operator-visible copy: "Избраните условия са променени...").
        $firstInstallment = (float) ($calculation['first_installment'] ?? $firstInstallment);

        $fingerprint = CartFingerprint::hash($cart, $currency);
        $postedFingerprint = trim((string) ($this->request->post['cart_fingerprint'] ?? ''));
        if ($postedFingerprint !== '' && !hash_equals($fingerprint, $postedFingerprint)) {
            throw new ProductFinancingFlowException(
                'cart_changed',
                'Съдържанието на количката е променено. Моля, презаредете калкулатора.'
            );
        }

        $actorBindingHash = $model->actorBindingHash();
        $selectionHash = CartSelectionHash::hash(
            $storeId,
            $fingerprint,
            $currency,
            $cart->total,
            $schemeKey,
            $schemeType,
            $kopCode,
            $months,
            $filterId,
            $firstInstallment,
            $actorBindingHash
        );
        $operationKeyHash = CartOperationIdentity::hash($storeId, $currency, $fingerprint);

        return [
            'store_id'           => $storeId,
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
        $trace = $exception->getTrace();
        $caller = isset($trace[0]['file'])
            ? (basename(str_replace('\\', '/', (string) $trace[0]['file'])) . ':' . (int) ($trace[0]['line'] ?? 0))
            : 'n/a';
        $this->log->write(sprintf(
            'mt_uni_credit.cart: %s: %s in %s:%d via %s',
            $exception::class,
            $exception->getMessage(),
            $file,
            $exception->getLine(),
            $caller
        ));
    }
}
