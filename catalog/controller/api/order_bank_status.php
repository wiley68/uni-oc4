<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Api;

use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderAmbiguousException;
use Opencart\System\Library\Extension\MtUniCredit\InboundApiOperations;
use Opencart\System\Library\Extension\MtUniCredit\InboundBankStatusVocabulary;
use Opencart\System\Library\Extension\MtUniCredit\ModuleApiException;
use Opencart\System\Library\Extension\MtUniCredit\OrderBankStatusRepository;
use Opencart\System\Library\Extension\MtUniCredit\OrderBankStatusSemanticConflictException;

/**
 * CP → module bank status update.
 *
 * Route: extension/mt_uni_credit/api/order_bank_status
 * Method: POST
 */
class OrderBankStatus extends InboundApiBase
{
    protected function expectedOperation(): string
    {
        return InboundApiOperations::ORDER_BANK_STATUS;
    }

    public function index(): void
    {
        $this->runInbound(function (array $payload, string $unicid): array {
            $orderId = $payload['order_id'] ?? null;
            if (!is_string($orderId)) {
                throw new ModuleApiException('Полето order_id е задължително.', 400);
            }
            $orderId = trim($orderId);
            if ($orderId === '' || strlen($orderId) > 13) {
                throw new ModuleApiException('Полето order_id е невалидно.', 400);
            }

            $statusId = $payload['status_id'] ?? null;
            if (!is_string($statusId)) {
                throw new ModuleApiException('Полето status_id е задължително.', 400);
            }
            $statusId = trim($statusId);
            if ($statusId === '' || strlen($statusId) > 255) {
                throw new ModuleApiException('Полето status_id е невалидно.', 400);
            }
            if (!InboundBankStatusVocabulary::isAccepted($statusId)) {
                throw new ModuleApiException('Неподдържан банков статус.', 400, 'unsupported_status');
            }

            $status = $payload['status'] ?? null;
            if (!is_string($status)) {
                throw new ModuleApiException('Полето status е задължително.', 400);
            }
            $status = trim($status);
            if ($status === '' || strlen($status) > 255) {
                throw new ModuleApiException('Полето status е невалидно.', 400);
            }

            try {
                $result = (new OrderBankStatusRepository($this->dbConnection()))->updateByOrderIdentifier(
                    $this->storeId(),
                    $unicid,
                    $orderId,
                    $statusId,
                    $status
                );
            } catch (FinancingOrderAmbiguousException $exception) {
                throw new ModuleApiException(
                    'Поръчката е двусмислена за този магазин.',
                    409,
                    'order_ambiguous',
                    null,
                    $exception
                );
            } catch (OrderBankStatusSemanticConflictException $exception) {
                throw new ModuleApiException(
                    'Несъвместима промяна на банков статус.',
                    409,
                    'semantic_conflict',
                    null,
                    $exception
                );
            }

            if ($result === null) {
                throw new ModuleApiException('Поръчката не е намерена в магазина.', 404);
            }

            return [
                'success' => true,
                'message' => 'Банковият статус е обновен успешно.',
                'data' => $result,
            ];
        });
    }
}
