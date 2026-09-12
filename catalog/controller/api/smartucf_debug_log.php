<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Api;

use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
use Opencart\System\Library\Extension\MtUniCredit\DiagnosticDebugLogRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderAmbiguousException;
use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderResolver;
use Opencart\System\Library\Extension\MtUniCredit\InboundApiOperations;
use Opencart\System\Library\Extension\MtUniCredit\ModuleApiException;
use Opencart\System\Library\Extension\MtUniCredit\OrderBankStatusRepository;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleStates;

/**
 * CP → module diagnostic debug retrieval (safe / redacted).
 *
 * Route: extension/mt_uni_credit/api/smartucf_debug_log
 * Method: POST
 *
 * Ownership checks run before any journal disclosure. All denials are opaque 404.
 */
class SmartucfDebugLog extends InboundApiBase
{
    protected function expectedOperation(): string
    {
        return InboundApiOperations::SMARTUCF_DEBUG_LOG;
    }

    public function index(): void
    {
        $this->runInbound(function (array $payload, string $unicid): array {
            $orderIdRaw = $payload['order_id'] ?? null;
            if (!is_string($orderIdRaw)) {
                throw new ModuleApiException('Полето order_id е задължително.', 400);
            }
            $orderIdRaw = trim($orderIdRaw);
            if ($orderIdRaw === '' || strlen($orderIdRaw) > 13 || !ctype_digit($orderIdRaw)) {
                throw new ModuleApiException('Полето order_id е невалидно.', 400);
            }

            $storeId = $this->storeId();
            $db = $this->dbConnection();

            try {
                $resolved = (new FinancingOrderResolver($db))->resolve($storeId, $unicid, $orderIdRaw);
            } catch (FinancingOrderAmbiguousException $exception) {
                throw $this->opaqueNotFound();
            }

            if ($resolved === null) {
                throw $this->opaqueNotFound();
            }

            if (!$this->isAuthorizedPrimaryDebugTarget($storeId, $resolved['order_id'], $resolved['attempt'])) {
                throw $this->opaqueNotFound();
            }

            $orderId = $resolved['order_id'];
            $log = (new DiagnosticDebugLogRepository($db))->findLatestByOrderId($storeId, $orderId);
            if ($log === null) {
                throw $this->opaqueNotFound();
            }

            return [
                'success' => true,
                'message' => 'Диагностичният запис е намерен.',
                'data' => [
                    'order_id' => $orderIdRaw,
                    'oc_order_id' => $orderId,
                    'log' => $log,
                ],
            ];
        });
    }

    /**
     * Process 1 ownership only. Process-2-only and missing session ownership are opaque.
     *
     * @param array<string, mixed> $attempt
     */
    private function isAuthorizedPrimaryDebugTarget(int $storeId, int $orderId, array $attempt): bool
    {
        $bank = (new OrderBankStatusRepository($this->dbConnection()))->findCurrentStatus($storeId, $orderId);
        if (is_array($bank) && (string) ($bank['status_id'] ?? '') === BankStatus::SENT_PROCESS2) {
            return false;
        }

        $smartucfState = isset($attempt['smartucf_state']) ? (string) $attempt['smartucf_state'] : '';
        if ($smartucfState === '' || $smartucfState === SmartUcfLifecycleStates::NOT_STARTED) {
            return false;
        }

        return true;
    }

    private function opaqueNotFound(): ModuleApiException
    {
        return new ModuleApiException('Не е намерена диагностична информация за тази поръчка.', 404);
    }
}
