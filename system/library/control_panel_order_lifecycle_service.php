<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Shared CP order create/recover lifecycle for Product, Cart and Checkout (Phase 10B).
 *
 * Recovery for retryable failures = re-POST the frozen cp_payload (CP idempotent on shop+order).
 * Ambiguous outcomes (`cp_outcome_unknown`) must NEVER blind re-POST — stop safely.
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

        if (!$this->locks->acquire(
            $submission->storeId,
            $submission->entryPoint,
            $submission->operationKeyHash,
            $lockOwnerToken
        )) {
            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, true);
        }

        try {
            return $this->runUnderLock($attempt->attemptId(), $submission, $localOrderId, $shop);
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
        array $shop
    ): ControlPanelOrderSubmissionResult {
        $row = $this->attempts->findById($attemptId);
        if ($row === null) {
            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, false);
        }

        $existingCpId = isset($row['control_panel_order_id']) ? (int) $row['control_panel_order_id'] : 0;
        if ($existingCpId > 0 && (string) ($row['state'] ?? '') === FinancingAttemptState::CP_CREATED) {
            $this->log('cp_replay_local', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, $existingCpId, null, null);

            return ControlPanelOrderSubmissionResult::ok($existingCpId, true);
        }

        // Ambiguous CP create: never blind-resend POST /orders.
        if ((string) ($row['state'] ?? '') === FinancingAttemptState::CP_OUTCOME_UNKNOWN) {
            $this->log(
                'cp_outcome_unknown_blocked',
                $attemptId,
                $submission->entryPoint,
                $submission->storeId,
                $localOrderId,
                $existingCpId > 0 ? $existingCpId : null,
                (string) ($row['last_error_class'] ?? ControlPanelErrorClass::RECOVERY_FAILED),
                null
            );

            return ControlPanelOrderSubmissionResult::fail(
                (string) ($row['last_error_class'] ?? ControlPanelErrorClass::RECOVERY_FAILED),
                false
            );
        }

        $payload = $this->resolveFrozenPayload($row, $submission, $localOrderId, $shop);
        if ($payload === null) {
            $this->persistFailure($attemptId, ControlPanelErrorClass::RECOVERY_FAILED, FinancingAttemptState::CP_FAILED_RETRYABLE);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, true);
        }

        $this->attempts->persistCpPayload($attemptId, $payload);

        if (!$this->enterSubmitting($attemptId, (string) ($row['state'] ?? ''))) {
            $fresh = $this->attempts->findById($attemptId);
            $freshCp = isset($fresh['control_panel_order_id']) ? (int) $fresh['control_panel_order_id'] : 0;
            if ($fresh !== null && $freshCp > 0 && (string) ($fresh['state'] ?? '') === FinancingAttemptState::CP_CREATED) {
                return ControlPanelOrderSubmissionResult::ok($freshCp, true);
            }
            if ($fresh !== null && (string) ($fresh['state'] ?? '') === FinancingAttemptState::CP_OUTCOME_UNKNOWN) {
                return ControlPanelOrderSubmissionResult::fail(
                    (string) ($fresh['last_error_class'] ?? ControlPanelErrorClass::RECOVERY_FAILED),
                    false
                );
            }
            $this->persistFailure($attemptId, ControlPanelErrorClass::RECOVERY_FAILED, FinancingAttemptState::CP_FAILED_RETRYABLE);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, true);
        }

        $this->log('cp_submitting', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, null, null);

        try {
            $response = $this->client->createOrder($payload);
            $cpId = (int) ($response['data']['id'] ?? 0);
            if ($cpId <= 0) {
                // Malformed success after HTTP 2xx — ambiguous; never bank_send_failed_cp; no second POST.
                $this->persistFailure($attemptId, ControlPanelErrorClass::INVALID_RESPONSE, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
                $this->log('cp_invalid_response', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::INVALID_RESPONSE, 200);

                return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::INVALID_RESPONSE, false, 200);
            }

            if (!$this->attempts->persistControlPanelOrderId($attemptId, $cpId)) {
                // Crash-window: CP succeeded with known cpId but local persist raced.
                // Prefer outcome_unknown stop — do not blind re-POST.
                $this->persistFailure($attemptId, ControlPanelErrorClass::RECOVERY_FAILED, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
                $this->log('cp_persist_race', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, $cpId, ControlPanelErrorClass::RECOVERY_FAILED, null);

                return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, false);
            }

            $this->attempts->transitionFromStates(
                $attemptId,
                [FinancingAttemptState::CP_SUBMITTING],
                FinancingAttemptState::CP_CREATED
            );
            $this->attempts->clearLastErrorClass($attemptId);
            $this->log('cp_created', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, $cpId, null, 201);

            return ControlPanelOrderSubmissionResult::ok($cpId);
        } catch (CpAuthenticationException $exception) {
            $this->persistFailure($attemptId, ControlPanelErrorClass::AUTH_FAILED, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
            $this->log('cp_auth_failed', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::AUTH_FAILED, 401);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::AUTH_FAILED, false, 401);
        } catch (CpTimeoutException $exception) {
            $this->persistFailure($attemptId, ControlPanelErrorClass::TIMEOUT, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
            $this->log('cp_timeout', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::TIMEOUT, null);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::TIMEOUT, false);
        } catch (CpConnectionException $exception) {
            $this->persistFailure($attemptId, ControlPanelErrorClass::TRANSPORT_FAILED, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
            $this->log('cp_transport_failed', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::TRANSPORT_FAILED, null);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::TRANSPORT_FAILED, false);
        } catch (CpHttpException $exception) {
            return $this->handleHttpFailure($attemptId, $submission, $localOrderId, $exception);
        } catch (CpInvalidPayloadException|CpMalformedJsonException $exception) {
            // Malformed success / echo mismatch after HTTP 2xx → outcome_unknown, no second POST.
            $this->persistFailure($attemptId, ControlPanelErrorClass::INVALID_RESPONSE, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
            $this->log('cp_invalid_response', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::INVALID_RESPONSE, null);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::INVALID_RESPONSE, false);
        } catch (\Throwable $exception) {
            $this->persistFailure($attemptId, ControlPanelErrorClass::TRANSPORT_FAILED, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
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
    }

    private function handleHttpFailure(
        int $attemptId,
        ValidatedFinancingSubmission $submission,
        int $localOrderId,
        CpHttpException $exception
    ): ControlPanelOrderSubmissionResult {
        $status = $exception->getStatusCode();
        $error = $exception->isCanonicalFailure()
            ? (string) $exception->getCanonicalError()
            : '';

        if ($error !== '' && in_array($error, self::TERMINAL_CREATE_ERROR_CODES, true)) {
            $this->persistFailure($attemptId, ControlPanelErrorClass::REJECTED, FinancingAttemptState::TERMINAL_FAILED);
            $this->log('cp_rejected_terminal', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::REJECTED, $status);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::REJECTED, false, $status);
        }

        // Bare/malformed/non-definitive 409 remains ambiguous — never terminal_failed.
        if ($status === 409) {
            $this->persistFailure($attemptId, ControlPanelErrorClass::CONFLICT, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
            $this->log('cp_conflict_ambiguous', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::CONFLICT, 409);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::CONFLICT, false, 409);
        }

        if ($status === 429 || $error === 'rate_limited') {
            $this->persistFailure($attemptId, ControlPanelErrorClass::REJECTED, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
            $this->log('cp_rate_limited', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::REJECTED, 429);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::REJECTED, false, 429);
        }

        // 5xx and unknown 4xx: ambiguous — stop safely; never blind re-POST.
        if ($status >= 500) {
            $this->persistFailure($attemptId, ControlPanelErrorClass::TRANSPORT_FAILED, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
            $this->log('cp_http_5xx', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::TRANSPORT_FAILED, $status);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::TRANSPORT_FAILED, false, $status);
        }

        $this->persistFailure($attemptId, ControlPanelErrorClass::REJECTED, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
        $this->log('cp_rejected_ambiguous', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::REJECTED, $status);

        return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::REJECTED, false, $status);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $shop
     * @return array<string, mixed>|null
     */
    private function resolveFrozenPayload(array $row, ValidatedFinancingSubmission $submission, int $localOrderId, array $shop): ?array
    {
        $raw = $row['cp_payload'] ?? null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                $decoded = null;
            }
            if (is_array($decoded) && isset($decoded['order_id'])) {
                return $decoded;
            }
        }

        return $this->payloadBuilder->build($submission, $localOrderId, $shop);
    }

    private function enterSubmitting(int $attemptId, string $currentState): bool
    {
        if ($currentState === FinancingAttemptState::CP_CREATED
            || $currentState === FinancingAttemptState::CP_OUTCOME_UNKNOWN
            || $currentState === FinancingAttemptState::TERMINAL_FAILED
        ) {
            return false;
        }

        $from = [
            FinancingAttemptState::ORDER_CREATED,
            FinancingAttemptState::CP_SUBMITTING,
            FinancingAttemptState::CP_FAILED_RETRYABLE,
        ];

        // Materialization may leave order_created without an explicit local_order_prepared DB state.
        if ($this->attempts->transitionFromStates($attemptId, $from, FinancingAttemptState::CP_SUBMITTING)) {
            return true;
        }

        $fresh = $this->attempts->findById($attemptId);

        return $fresh !== null && (string) ($fresh['state'] ?? '') === FinancingAttemptState::CP_SUBMITTING;
    }

    private function persistFailure(int $attemptId, string $errorClass, string $state): void
    {
        $this->attempts->persistLastErrorClass($attemptId, $errorClass);
        $this->attempts->transitionFromStates(
            $attemptId,
            [
                FinancingAttemptState::ORDER_CREATED,
                FinancingAttemptState::CP_SUBMITTING,
                FinancingAttemptState::CP_FAILED_RETRYABLE,
                FinancingAttemptState::CP_OUTCOME_UNKNOWN,
            ],
            $state
        );
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
