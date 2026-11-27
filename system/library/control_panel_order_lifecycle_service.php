<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Shared CP order create/recover lifecycle for Product, Cart and Checkout (Phase 10B).
 *
 * An authorized pre-dispatch attempt can send its frozen body. A persisted
 * dispatch claim requires reconciliation unless a definitive result is recorded.
 */
final class ControlPanelOrderLifecycleService
{
    public const CUSTOMER_FAILURE_MESSAGE = 'Поръчката е създадена, но изпращането към системата за финансиране не беше успешно.';

    public const CUSTOMER_SUCCESS_MESSAGE = 'Поръчката е изпратена към системата за финансиране.';

    /** Positive allowlist of definitive non-retryable CP machine codes on create. */
    private const TERMINAL_CREATE_ERROR_CODES = [
        'invalid_payload',
        'semantic_conflict',
        'unsupported_status',
        'shop_not_found',
        'order_not_found',
    ];

    /**
     * Explicit HTTP endpoint rejections that prove create was not accepted
     * (no valid CP order), even without an application machine code.
     *
     * @var list<int>
     */
    private const DEFINITIVE_ENDPOINT_REJECTION_STATUSES = [403, 404, 405, 410];

    /** @var callable|null */
    private $logger;

    public function __construct(
        private FinancingAttemptRepository $attempts,
        private OperationLockRepository $locks,
        private ControlPanelClient $client,
        private ControlPanelOrderPayloadBuilder $payloadBuilder,
        ?callable $logger = null
    ) {
        $this->logger = $logger;
    }

    public function database(): DbConnection
    {
        return $this->attempts->database();
    }

    public function client(): ControlPanelClient
    {
        return $this->client;
    }

    /**
     * @param array<string, mixed> $shop
     */
    public function submitOrRecover(
        FinancingAttemptContext $attempt,
        ValidatedFinancingSubmission $submission,
        int $localOrderId,
        array $shop,
        string $lockOwnerToken
    ): ControlPanelOrderSubmissionResult {
        if ($localOrderId <= 0) {
            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, false);
        }
        CpOriginGuard::assertAttempt($attempt->row());
        if (!$this->locks->acquire(
            $submission->storeId,
            $submission->entryPoint,
            $submission->operationKeyHash,
            $lockOwnerToken
        )) {
            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, true);
        }

        try {
            return $this->runUnderLock($attempt->attemptId(), $submission, $localOrderId, $shop, $lockOwnerToken);
        } finally {
            $this->locks->release(
                $submission->storeId,
                $submission->entryPoint,
                $submission->operationKeyHash,
                $lockOwnerToken
            );
        }
    }

    /**
     * @param array<string, mixed> $shop
     */
    private function runUnderLock(
        int $attemptId,
        ValidatedFinancingSubmission $submission,
        int $localOrderId,
        array $shop,
        string $lockOwnerToken
    ): ControlPanelOrderSubmissionResult {
        $row = $this->attempts->findById($attemptId);
        if ($row === null) {
            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, false);
        }

        CpOriginGuard::assertAttempt($row);

        $consent = new DurableConsentAuthority($this->database());
        $existingCpId = isset($row['control_panel_order_id']) ? (int) $row['control_panel_order_id'] : 0;
        if (self::requiresCpReconciliation($row)) {
            $consent->requireFinalized($attemptId, $submission);
            if ((int) ($row['order_id'] ?? 0) !== $localOrderId) {
                throw ConsentResolver::refusal();
            }
            $error = self::reconciliationError($row);
            $this->log(
                'cp_outcome_unknown_blocked',
                $attemptId,
                $submission->entryPoint,
                $submission->storeId,
                $localOrderId,
                $existingCpId > 0 ? $existingCpId : null,
                $error,
                null
            );

            return ControlPanelOrderSubmissionResult::fail($error, false);
        }

        $record = $consent->requireFinalized($attemptId, $submission, $localOrderId);
        $submission->eurOrderProof = (new DurableEurOrderGuard($this->database()))->prove(
            $attemptId,
            $submission->storeId,
            $localOrderId
        );

        if ($existingCpId > 0 && (string) ($row['state'] ?? '') === FinancingAttemptState::CP_CREATED) {
            $this->log('cp_replay_local', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, $existingCpId, null, null);

            return ControlPanelOrderSubmissionResult::ok($existingCpId, true);
        }

        $state = (string) ($row['state'] ?? '');
        if (!FinancingAttemptRepository::canPrepareCpSubmission($row) || $existingCpId > 0) {
            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, false);
        }

        $raw = $row['cp_payload'] ?? null;
        $pending = $row['last_error_class'] ?? null;
        $hasAuthorization = FinancingAttemptRepository::isCpBodyAuthorization($pending);
        if ($raw !== null) {
            $payload = $this->decodeFrozenPayload($raw);
            if ($payload === null) {
                return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, false);
            }
            $consent->assertCpPayload($attemptId, $payload, $submission, $localOrderId, true);
        } else {
            if ($state !== FinancingAttemptState::ORDER_CREATED) {
                return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, false);
            }
            // The resume hydrator starts from the native amount. Use the finalized gross for CP.
            $newSubmission = clone $submission;
            $newSubmission->financingCalculation = clone $submission->financingCalculation;
            $newSubmission->financingCalculation->price = $record['issued']['context']['conditions']['price'];
            $payload = $this->payloadBuilder->build($newSubmission, $localOrderId, $shop);
            $consent->assertCpPayload($attemptId, $payload, $newSubmission, $localOrderId);
        }

        $authorization = FinancingAttemptRepository::cpBodyAuthorization($payload);
        if ($authorization === null) {
            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, false);
        }
        if ($hasAuthorization && $pending !== $authorization) {
            return $this->rejectBodyConflict($attemptId, $submission->storeId, $localOrderId);
        }
        if (!$hasAuthorization) {
            try {
                $this->attempts->reserveCpBodyAuthorization($row, $lockOwnerToken, $authorization);
            } catch (\Exception) {
                // A lost acknowledgement is inconclusive until the authoritative read-back.
            }
            $saved = $this->attempts->findById($attemptId);
            if (!self::isAuthorizedRow($row, $saved, $authorization) || ($saved['cp_payload'] ?? null) !== $raw) {
                return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, false);
            }
            $row = $saved;
        }

        if ($raw === null) {
            try {
                $this->attempts->persistCpPayload($attemptId, $payload);
            } catch (\Exception) {
                // The write can lose its acknowledgement after it commits.
            }
            $saved = $this->attempts->findById($attemptId);
            if (!self::isAuthorizedRow($row, $saved, $authorization)) {
                return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, false);
            }
            $confirmedRaw = $saved['cp_payload'] ?? null;
            if ($confirmedRaw === null) {
                return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, false);
            }
            $confirmed = $this->decodeFrozenPayload($confirmedRaw);
            if ($confirmed === null || FinancingAttemptRepository::cpBodyAuthorization($confirmed) !== $authorization) {
                return $this->rejectBodyConflict($attemptId, $submission->storeId, $localOrderId);
            }
            $consent->assertCpPayload($attemptId, $confirmed, $newSubmission, $localOrderId);
            $payload = $confirmed;
            $row = $saved;
        }

        try {
            $dispatchConfirmed = $this->attempts->claimCpSubmission($row, $lockOwnerToken);
        } catch (\Exception) {
            // An unconfirmed dispatch claim never authorizes an external request.
            $dispatchConfirmed = false;
        }
        if (!$dispatchConfirmed) {
            $fresh = $this->attempts->findById($attemptId);
            if ($fresh === null) {
                return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, false);
            }
            CpOriginGuard::assertAttempt($fresh);
            $freshCp = (int) ($fresh['control_panel_order_id'] ?? 0);
            if ($freshCp > 0 && $fresh['state'] === FinancingAttemptState::CP_CREATED) {
                $consent->requireFinalized($attemptId, $submission, $localOrderId);
                (new DurableEurOrderGuard($this->database()))->prove($attemptId, $submission->storeId, $localOrderId, true);
                return ControlPanelOrderSubmissionResult::ok($freshCp, true);
            }
            if (self::requiresCpReconciliation($fresh)) {
                return ControlPanelOrderSubmissionResult::fail(self::reconciliationError($fresh), false);
            }
            if (($fresh['cp_payload'] ?? null) !== $row['cp_payload']) {
                return $this->rejectBodyConflict($attemptId, $submission->storeId, $localOrderId);
            }

            return ControlPanelOrderSubmissionResult::fail(
                ControlPanelErrorClass::RECOVERY_FAILED,
                $freshCp === 0 && FinancingAttemptRepository::canPrepareCpSubmission($fresh)
            );
        }

        $this->log('cp_submitting', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, null, null);

        try {
            $response = $this->client->createOrder($payload);
        } catch (CpAuthenticationException $exception) {
            $this->attempts->recordCpFailure($row, ControlPanelErrorClass::AUTH_FAILED);
            $this->log('cp_auth_failed', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::AUTH_FAILED, 401);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::AUTH_FAILED, false, 401);
        } catch (CpTimeoutException $exception) {
            $this->attempts->recordCpFailure($row, ControlPanelErrorClass::TIMEOUT);
            $this->log('cp_timeout', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::TIMEOUT, null);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::TIMEOUT, false);
        } catch (CpConnectionException $exception) {
            $this->attempts->recordCpFailure($row, ControlPanelErrorClass::TRANSPORT_FAILED);
            $this->log('cp_transport_failed', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::TRANSPORT_FAILED, null);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::TRANSPORT_FAILED, false);
        } catch (CpHttpException $exception) {
            return $this->handleHttpFailure($row, $submission, $localOrderId, $exception);
        } catch (CpInvalidPayloadException|CpMalformedJsonException $exception) {
            // Malformed success / echo mismatch after HTTP 2xx → outcome_unknown, no second POST.
            $this->attempts->recordCpFailure($row, ControlPanelErrorClass::INVALID_RESPONSE);
            $this->log('cp_invalid_response', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::INVALID_RESPONSE, null);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::INVALID_RESPONSE, false);
        } catch (\Throwable $exception) {
            $this->attempts->recordCpFailure($row, ControlPanelErrorClass::TRANSPORT_FAILED);
            $this->log(
                'cp_unexpected',
                $attemptId,
                $submission->entryPoint,
                $submission->storeId,
                $localOrderId,
                null,
                ControlPanelErrorClass::TRANSPORT_FAILED,
                null,
                $exception::class,
                DiagnosticPayloadRedactor::sanitizeLogMessage(substr($exception->getMessage(), 0, 200))
            );

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::TRANSPORT_FAILED, false);
        }

        $cpId = (int) ($response['data']['id'] ?? 0);
        if ($cpId <= 0) {
            $this->attempts->recordCpFailure($row, ControlPanelErrorClass::INVALID_RESPONSE);
            $this->log('cp_invalid_response', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::INVALID_RESPONSE, 200);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::INVALID_RESPONSE, false, 200);
        }

        if (!$this->attempts->recordCpCreated($row, $cpId)) {
            $this->log('cp_persist_race', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, $cpId, ControlPanelErrorClass::RECOVERY_FAILED, null);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, false);
        }

        $this->log('cp_created', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, $cpId, null, 201);

        return ControlPanelOrderSubmissionResult::ok($cpId);
    }

    private function handleHttpFailure(
        array $row,
        ValidatedFinancingSubmission $submission,
        int $localOrderId,
        CpHttpException $exception
    ): ControlPanelOrderSubmissionResult {
        $attemptId = (int) $row['attempt_id'];
        $status = $exception->getStatusCode();
        $error = $exception->isCanonicalFailure()
            ? (string) $exception->getCanonicalError()
            : '';

        if ($error !== '' && in_array($error, self::TERMINAL_CREATE_ERROR_CODES, true)) {
            $recorded = $this->attempts->recordCpFailure($row, ControlPanelErrorClass::REJECTED, true);
            $event = $recorded ? 'cp_rejected_terminal' : 'cp_rejection_disposition_unconfirmed';
            $this->log($event, $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::REJECTED, $status);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::REJECTED, false, $status, $recorded);
        }

        // Explicit endpoint rejection: create was not accepted; no CP order exists.
        if (in_array($status, self::DEFINITIVE_ENDPOINT_REJECTION_STATUSES, true)) {
            $recorded = $this->attempts->recordCpFailure($row, ControlPanelErrorClass::REJECTED, true);
            $event = $recorded ? 'cp_endpoint_rejected_terminal' : 'cp_rejection_disposition_unconfirmed';
            $this->log($event, $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::REJECTED, $status);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::REJECTED, false, $status, $recorded);
        }

        // Bare/malformed/non-definitive 409 remains ambiguous — never terminal_failed.
        if ($status === 409) {
            $this->attempts->recordCpFailure($row, ControlPanelErrorClass::CONFLICT);
            $this->log('cp_conflict_ambiguous', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::CONFLICT, 409);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::CONFLICT, false, 409);
        }

        if ($status === 429 || $error === 'rate_limited') {
            $this->attempts->recordCpFailure($row, ControlPanelErrorClass::REJECTED);
            $this->log('cp_rate_limited', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::REJECTED, 429);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::REJECTED, false, 429);
        }

        // 5xx and unknown 4xx: ambiguous — stop safely; never blind re-POST.
        if ($status >= 500) {
            $this->attempts->recordCpFailure($row, ControlPanelErrorClass::TRANSPORT_FAILED);
            $this->log('cp_http_5xx', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::TRANSPORT_FAILED, $status);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::TRANSPORT_FAILED, false, $status);
        }

        $this->attempts->recordCpFailure($row, ControlPanelErrorClass::REJECTED);
        $this->log('cp_rejected_ambiguous', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::REJECTED, $status);

        return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::REJECTED, false, $status);
    }

    private function decodeFrozenPayload(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($body)) {
            return null;
        }
        foreach (['order_id', 'name', 'phone', 'email', 'address', 'address2', 'products_id', 'products_name', 'products_q', 'currency', 'version'] as $field) {
            if (!is_string($body[$field] ?? null)) {
                return null;
            }
        }
        if ($body['version'] === '' || !in_array($body['type_client'] ?? null, [0, 1], true)) {
            return null;
        }

        return $body;
    }

    private static function isAuthorizedRow(array $expected, ?array $saved, string $authorization): bool
    {
        if ($saved === null || isset($saved['control_panel_order_id']) || ($saved['last_error_class'] ?? null) !== $authorization) {
            return false;
        }
        foreach (['attempt_id', 'store_id', 'order_id', 'entry_point', 'operation_key_hash', 'state', 'cp_origin', 'consent_context_json'] as $key) {
            if ($expected[$key] !== $saved[$key]) {
                return false;
            }
        }

        return true;
    }

    private function rejectBodyConflict(int $attemptId, int $storeId, int $orderId): ControlPanelOrderSubmissionResult
    {
        try {
            $this->attempts->holdCpPayloadConflict($attemptId, $storeId, $orderId);
        } catch (\Exception) {
            // The earlier authorization still fences the contradictory body if the hold cannot be recorded.
        }

        return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::CONFLICT, false);
    }

    private static function requiresCpReconciliation(array $row): bool
    {
        return in_array($row['state'], [FinancingAttemptState::CP_SUBMITTING, FinancingAttemptState::CP_OUTCOME_UNKNOWN], true);
    }

    private static function reconciliationError(array $row): string
    {
        $error = $row['last_error_class'] ?? '';

        return ControlPanelErrorClass::isValid($error) ? $error : ControlPanelErrorClass::RECOVERY_FAILED;
    }

    private function log(
        string $event,
        int $attemptId,
        string $entryPoint,
        int $storeId,
        int $localOrderId,
        ?int $cpOrderId,
        ?string $errorClass,
        ?int $httpStatus,
        ?string $exceptionClass = null,
        ?string $exceptionMessage = null
    ): void {
        if ($this->logger === null) {
            return;
        }
        $payload = [
            'event' => $event,
            'attempt_id' => $attemptId,
            'entry_point' => $entryPoint,
            'store_id' => $storeId,
            'local_order_id' => $localOrderId,
            'cp_order_id' => $cpOrderId,
            'error_class' => $errorClass,
            'http_status' => $httpStatus,
        ];
        if ($exceptionClass !== null) {
            $payload['exception_class'] = $exceptionClass;
        }
        if ($exceptionMessage !== null && $exceptionMessage !== '') {
            $payload['exception_message'] = DiagnosticPayloadRedactor::sanitizeLogMessage($exceptionMessage);
        }
        ($this->logger)($payload);
    }
}
