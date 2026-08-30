<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Api;

use Opencart\System\Library\Extension\MtUniCredit\DiagnosticDebugLogRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleApiException;

/**
 * CP → module diagnostic debug retrieval (safe / redacted).
 *
 * Route: extension/mt_uni_credit/api/smartucf_debug_log
 * Method: POST
 *
 * Phase 11 writers may populate diagnostic rows. Bridge A returns structured 404 when absent.
 */
class SmartucfDebugLog extends InboundApiBase
{
    public function index(): void
    {
        $this->runInbound(function (array $payload, string $unicid): array {
            unset($unicid);

            $orderIdRaw = $payload['order_id'] ?? null;
            if (!is_string($orderIdRaw) && !is_int($orderIdRaw)) {
                throw new ModuleApiException('Полето order_id е задължително.', 400);
            }
            $orderIdRaw = trim((string) $orderIdRaw);
            if ($orderIdRaw === '' || strlen($orderIdRaw) > 64 || !ctype_digit($orderIdRaw)) {
                throw new ModuleApiException('Полето order_id е невалидно.', 400);
            }

            $orderId = (int) $orderIdRaw;
            $storeId = $this->storeId();
            $db = $this->dbConnection();

            // Authorize: financing attempt in this store, or same opaque 404 (no cross-shop oracle).
            $attempt = (new FinancingAttemptRepository($db))->findByOrderId($storeId, $orderId);
            $log = (new DiagnosticDebugLogRepository($db))->findLatestByOrderId($storeId, $orderId);

            if ($attempt === null || $log === null) {
                throw new ModuleApiException('Не е намерена диагностична информация за тази поръчка.', 404);
            }

            return [
                'success' => true,
                'data' => [
                    'order_id' => $orderIdRaw,
                    'oc_order_id' => $orderId,
                    'log' => $log,
                ],
            ];
        });
    }
}
