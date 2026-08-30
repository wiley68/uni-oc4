<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Api;

use Opencart\System\Library\Extension\MtUniCredit\InboundBankStatusVocabulary;
use Opencart\System\Library\Extension\MtUniCredit\ModuleApiException;
use Opencart\System\Library\Extension\MtUniCredit\OrderBankStatusRepository;

/**
 * CP → module bank status update.
 *
 * Route: extension/mt_uni_credit/api/order_bank_status
 * Method: POST
 */
class OrderBankStatus extends InboundApiBase
{
    public function index(): void
    {
        $this->runInbound(function (array $payload, string $unicid): array {
            unset($unicid);

            $orderId = $payload['order_id'] ?? null;
            if (!is_string($orderId) && !is_int($orderId)) {
                throw new ModuleApiException('Полето order_id е задължително.', 400);
            }
            $orderId = trim((string) $orderId);
            if ($orderId === '' || strlen($orderId) > 64) {
                throw new ModuleApiException('Полето order_id е невалидно.', 400);
            }

            $statusId = $payload['status_id'] ?? null;
            if (!is_string($statusId) && !is_int($statusId)) {
                throw new ModuleApiException('Полето status_id е задължително.', 400);
            }
            $statusId = trim((string) $statusId);
            if ($statusId === '' || strlen($statusId) > 255) {
                throw new ModuleApiException('Полето status_id е невалидно.', 400);
            }
            if (!InboundBankStatusVocabulary::isAccepted($statusId)) {
                throw new ModuleApiException('Неподдържан банков статус.', 400, 'unsupported_status');
            }

            $status = $payload['status'] ?? '';
            if (!is_string($status) || strlen($status) > 255) {
                throw new ModuleApiException('Полето status е невалидно.', 400);
            }
            $status = trim($status);

            $result = (new OrderBankStatusRepository($this->dbConnection()))->updateByOrderIdentifier(
                $this->storeId(),
                $orderId,
                $statusId,
                $status
            );
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
