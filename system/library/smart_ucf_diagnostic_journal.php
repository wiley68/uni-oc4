<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * SmartUCF diagnostic journal — gated capture + safe export (PS9/Woo parity).
 */
final class SmartUcfDiagnosticJournal
{
    public const OPERATION_SESSION_START = 'sucfOnlineSessionStart';

    /** @var callable(int): bool */
    private $debugGate;

    /**
     * @param callable(int): bool $debugGate Receives store_id; true when verbose diagnostics are enabled.
     */
    public function __construct(
        private DiagnosticDebugLogRepository $repository,
        callable $debugGate
    ) {
        $this->debugGate = $debugGate;
    }

    public static function fromDatabase(DbConnection $db): self
    {
        $settings = new OpenCartModuleSettingStore($db);

        return new self(
            new DiagnosticDebugLogRepository($db),
            static function (int $storeId) use ($settings): bool {
                if (!OpenCartStoreScope::isValid($storeId)) {
                    return false;
                }

                return ModuleLocalSettings::normalizeFlag(
                    $settings->get($storeId, ModuleLocalSettings::DEBUG_ENABLED) ?? '0'
                ) === 1;
            }
        );
    }

    /**
     * @param mixed $request Final payload sent (array or JSON string).
     * @param mixed $response Raw/safe response body (array, string, or null).
     */
    public function recordSmartUcfSession(
        int $storeId,
        int $orderId,
        string $entryPoint,
        string $endpoint,
        mixed $request,
        mixed $response,
        int $httpStatus,
        ?string $transportError,
        string $eventCode
    ): bool {
        if (!$this->isDebugEnabled($storeId)) {
            return false;
        }

        try {
            $summary = [
                'operation' => self::OPERATION_SESSION_START,
                'endpoint' => DiagnosticPayloadRedactor::redactText($endpoint),
                'outcome' => $eventCode,
                'request' => DiagnosticPayloadRedactor::redactMixed($request),
                'response' => DiagnosticPayloadRedactor::redactMixed($response),
            ];
            if ($transportError !== null && $transportError !== '') {
                $summary['transport_error'] = DiagnosticPayloadRedactor::redactText($transportError);
            }

            return $this->repository->insert(
                $storeId,
                $orderId,
                $entryPoint,
                $eventCode,
                $httpStatus,
                $summary
            );
        } catch (\Throwable $exception) {
            error_log(
                'mt_uni_credit: SmartUCF diagnostic journal write failed: '
                    . $exception::class
                    . ' store_id=' . $storeId
                    . ' order_id=' . $orderId
            );

            return false;
        }
    }

    /** @return array<string, mixed> */
    public function buildExport(int $storeId): array
    {
        OpenCartStoreScope::require($storeId);
        $entries = $this->repository->findAllForStore($storeId);

        return [
            'module' => ModuleConstants::EXTENSION_CODE,
            'module_version' => ModuleConstants::VERSION,
            'exported_at_gmt' => gmdate('c'),
            'store_id' => $storeId,
            'debug_enabled' => $this->isDebugEnabled($storeId),
            'total_entries' => count($entries),
            'entries' => $entries,
        ];
    }

    public function hasEntries(int $storeId): bool
    {
        if (!OpenCartStoreScope::isValid($storeId)) {
            return false;
        }

        return $this->repository->countForStore($storeId) > 0;
    }

    private function isDebugEnabled(int $storeId): bool
    {
        if (!OpenCartStoreScope::isValid($storeId)) {
            return false;
        }

        return (bool) ($this->debugGate)($storeId);
    }
}
