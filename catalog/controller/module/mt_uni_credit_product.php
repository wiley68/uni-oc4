<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Module;

use Opencart\Catalog\Model\Extension\MtUniCredit\Module\MtUniCreditProduct as ProductFinancingModel;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\ProductActorBinding;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\ProductOperationIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductOptionNormalizer;
use Opencart\System\Library\Extension\MtUniCredit\ProductSelectionHash;
use Opencart\System\Library\Extension\MtUniCredit\SubmissionTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\UnavailableSchemeException;

class MtUniCreditProduct extends \Opencart\System\Engine\Controller
{
    public function calculate(): void
    {
        $this->respondJson(function (): array {
            $this->assertPostWithCsrf();
            $model = $this->productModel();
            $shop = $model->getShopConfiguration();
            if ($shop === null) {
                return $this->errorPayload('configuration_unavailable', 'Калкулаторът временно не е наличен.');
            }

            $productId = (int) ($this->request->post['product_id'] ?? 0);
            $quantity = max(1, min(9999, (int) ($this->request->post['quantity'] ?? 1)));
            $options = ProductOptionNormalizer::normalize($this->request->post['option'] ?? []);
            $storeId = (int) $this->config->get('config_store_id');
            $currency = (string) ($this->session->data['currency'] ?? $this->config->get('config_currency'));
            $line = $model->createDisplayProductContextFactory()->create($storeId, $productId, $quantity, $options);
            $presenter = $model->createCalculatorPresenter()->present($shop, $line, $currency);
            if ($presenter === null) {
                return $this->errorPayload('no_schemes', 'Няма налични схеми за този продукт.');
            }

            return [
                'success'  => true,
                'sequence' => (int) ($this->request->post['sequence'] ?? 0),
                'calculator' => $presenter,
            ];
        });
    }

    public function issueSubmission(): void
    {
        $this->respondJson(function (): array {
            $this->assertPostWithCsrf();
            $model = $this->productModel();
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
                trim((string) ($this->request->post['submission_token'] ?? ''))
            );
            $token = (string) ($attempt['submission_token'] ?? '');
            if (!SubmissionTokenGenerator::isValidFormat($token)) {
                return $this->errorPayload('technical_failure', 'Заявката не може да бъде обработена.');
            }

            return [
                'success'           => true,
                'step'              => 'submission_token_issued',
                'submission_token'  => $token,
                'calculation'       => $context['calculation'],
            ];
        });
    }

    public function submit(): void
    {
        $this->respondJson(function (): array {
            $this->assertPostWithCsrf();
            $cartCountBefore = 0;
            $model = $this->productModel();
            $cartCountBefore = $model->countActiveCartProducts();
            $shop = $model->getShopConfiguration();
            if ($shop === null) {
                return $this->errorPayload('configuration_unavailable', 'Заявката временно не е налична.');
            }

            $context = $this->readSelectionContext($model);
            $meta = $model->shopCacheMeta();
            $service = $model->createSubmissionService();
            try {
                $result = $service->submit(
                    $shop,
                    $context['store_id'],
                    trim((string) ($this->request->post['submission_token'] ?? '')),
                    $context['actor_binding_hash'],
                    ProductActorBinding::sessionFingerprint((string) $this->session->getId()),
                    $this->customer->isLogged() ? (int) $this->customer->getId() : 0,
                    (int) $this->config->get('config_customer_group_id'),
                    $context['product_id'],
                    $context['quantity'],
                    $context['options'],
                    $context['currency'],
                    $context['popup_type'],
                    $context['scheme_type'],
                    $context['kop_code'],
                    $context['months'],
                    $context['filter_id'],
                    $context['scheme_key'],
                    $context['first_installment'],
                    $this->request->post,
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
                    $model->storeAddressDefaults()
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

            return $payload;
        });
    }

    private function assertPostWithCsrf(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            throw new \RuntimeException('method_not_allowed');
        }
        $expected = (string) ($this->session->data['csrf_token'] ?? '');
        $provided = (string) ($this->request->post['csrf_token'] ?? '');
        if ($expected === '' || !hash_equals($expected, $provided)) {
            http_response_code(403);
            throw new \RuntimeException('invalid_csrf');
        }
    }

    private function productModel(): ProductFinancingModel
    {
        $this->load->model('extension/mt_uni_credit/module/mt_uni_credit_product');
        /** @var ProductFinancingModel $model */
        $model = $this->model_extension_mt_uni_credit_module_mt_uni_credit_product;

        return $model;
    }

    /** @return array<string, mixed> */
    private function readSelectionContext(ProductFinancingModel $model): array
    {
        $productId = (int) ($this->request->post['product_id'] ?? 0);
        $quantity = max(1, min(9999, (int) ($this->request->post['quantity'] ?? 1)));
        $options = ProductOptionNormalizer::normalize($this->request->post['option'] ?? []);
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

        $line = $model->createProductContextFactory()->create($storeId, $productId, $quantity, $options);
        try {
            $calculation = $model->createSchemeCalculator()->calculate(
                $shop,
                $line->toProductContext(),
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

        $actorBindingHash = $model->actorBindingHash();
        $selectionHash = ProductSelectionHash::hash(
            $storeId,
            $productId,
            $line->normalizedOptions,
            $quantity,
            $currency,
            $line->financingPrice,
            $schemeKey,
            $schemeType,
            $kopCode,
            $months,
            $filterId,
            $firstInstallment,
            $actorBindingHash
        );
        $operationKeyHash = ProductOperationIdentity::hash($storeId, $productId, $line->normalizedOptions, $quantity, $currency);

        return [
            'store_id'             => $storeId,
            'product_id'           => $productId,
            'quantity'             => $quantity,
            'options'              => $options,
            'currency'             => $currency,
            'popup_type'           => $popupType,
            'scheme_type'          => $schemeType,
            'kop_code'             => $kopCode,
            'months'               => $months,
            'filter_id'            => $filterId,
            'scheme_key'           => $schemeKey,
            'first_installment'    => $firstInstallment,
            'actor_binding_hash'   => $actorBindingHash,
            'selection_hash'       => $selectionHash,
            'operation_key_hash'   => $operationKeyHash,
            'calculation'          => $calculation,
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
            if ($exception->getMessage() === 'invalid_csrf') {
                $json = $this->errorPayload('validation', 'Невалидна сесия. Моля, презаредете страницата.');
            } elseif ($exception->getMessage() === 'method_not_allowed') {
                $json = $this->errorPayload('validation', 'Невалиден метод.');
            } else {
                http_response_code(500);
                $json = $this->errorPayload('technical_failure', 'Заявката не може да бъде обработена.');
            }
        } catch (\Throwable) {
            http_response_code(500);
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
}
