<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Shared CP order create/recover lifecycle for Product, Cart and Checkout (Phase 10B).
 *
 * Recovery = re-POST the frozen cp_payload. CP is idempotent on (shop_id, order_id)
 * with matching semantic hash — there is no separate GET lookup endpoint.
 */
final class ControlPanelOrderLifecycleService
{
    public const CUSTOMER_FAILURE_MESSAGE = 'Поръчката е създадена, но изпращането към системата за финансиране не беше успешно.';

    public const CUSTOMER_SUCCESS_MESSAGE = 'Поръчката е изпратена към системата за финансиране.';

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
            $this->persistFailure($attemptId, ControlPanelErrorClass::RECOVERY_FAILED, FinancingAttemptState::CP_FAILED_RETRYABLE);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, true);
        }

        $this->log('cp_submitting', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, null, null);

        try {
            $response = $this->client->createOrder($payload);
            $cpId = (int) ($response['data']['id'] ?? 0);
            if ($cpId <= 0) {
                $this->persistFailure($attemptId, ControlPanelErrorClass::INVALID_RESPONSE, FinancingAttemptState::CP_FAILED_RETRYABLE);
                $this->log('cp_invalid_response', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::INVALID_RESPONSE, 200);

                return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::INVALID_RESPONSE, true, 200);
            }

            if (!$this->attempts->persistControlPanelOrderId($attemptId, $cpId)) {
                // Crash-window: CP succeeded but local write raced — retry path re-POSTs frozen payload.
                $this->persistFailure($attemptId, ControlPanelErrorClass::RECOVERY_FAILED, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
                $this->log('cp_persist_race', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, $cpId, ControlPanelErrorClass::RECOVERY_FAILED, null);

                return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::RECOVERY_FAILED, true);
            }

            $this->attempts->transitionFromStates(
                $attemptId,
                [FinancingAttemptState::CP_SUBMITTING, FinancingAttemptState::CP_OUTCOME_UNKNOWN],
                FinancingAttemptState::CP_CREATED
            );
            $this->attempts->clearLastErrorClass($attemptId);
            $this->log('cp_created', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, $cpId, null, 201);

            return ControlPanelOrderSubmissionResult::ok($cpId);
        } catch (CpAuthenticationException $exception) {
            $this->persistFailure($attemptId, ControlPanelErrorClass::AUTH_FAILED, FinancingAttemptState::CP_FAILED_RETRYABLE);
            $this->log('cp_auth_failed', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::AUTH_FAILED, 401);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::AUTH_FAILED, true, 401);
        } catch (CpTimeoutException $exception) {
            $this->persistFailure($attemptId, ControlPanelErrorClass::TIMEOUT, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
            $this->log('cp_timeout', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::TIMEOUT, null);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::TIMEOUT, true);
        } catch (CpConnectionException $exception) {
            $this->persistFailure($attemptId, ControlPanelErrorClass::TRANSPORT_FAILED, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
            $this->log('cp_transport_failed', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::TRANSPORT_FAILED, null);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::TRANSPORT_FAILED, true);
        } catch (CpHttpException $exception) {
            $status = $exception->getStatusCode();
            if ($status === 409) {
                $this->persistFailure($attemptId, ControlPanelErrorClass::CONFLICT, FinancingAttemptState::CP_FAILED_RETRYABLE);
                $this->log('cp_conflict', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::CONFLICT, 409);

                return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::CONFLICT, false, 409);
            }
            if ($status >= 400 && $status < 500) {
                $this->persistFailure($attemptId, ControlPanelErrorClass::REJECTED, FinancingAttemptState::CP_FAILED_RETRYABLE);
                $this->log('cp_rejected', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::REJECTED, $status);

                return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::REJECTED, $status === 422 || $status === 429, $status);
            }
            $this->persistFailure($attemptId, ControlPanelErrorClass::TRANSPORT_FAILED, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
            $this->log('cp_http_5xx', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::TRANSPORT_FAILED, $status);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::TRANSPORT_FAILED, true, $status);
        } catch (CpInvalidPayloadException|CpMalformedJsonException $exception) {
            $this->persistFailure($attemptId, ControlPanelErrorClass::INVALID_RESPONSE, FinancingAttemptState::CP_FAILED_RETRYABLE);
            $this->log('cp_invalid_response', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::INVALID_RESPONSE, null);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::INVALID_RESPONSE, true);
        } catch (\Throwable $exception) {
            $this->persistFailure($attemptId, ControlPanelErrorClass::TRANSPORT_FAILED, FinancingAttemptState::CP_OUTCOME_UNKNOWN);
            $this->log('cp_unexpected', $attemptId, $submission->entryPoint, $submission->storeId, $localOrderId, null, ControlPanelErrorClass::TRANSPORT_FAILED, null);

            return ControlPanelOrderSubmissionResult::fail(ControlPanelErrorClass::TRANSPORT_FAILED, true);
        }
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
        if ($currentState === FinancingAttemptState::CP_CREATED) {
            return false;
        }

        $from = [
            FinancingAttemptState::ORDER_CREATED,
            FinancingAttemptState::CP_SUBMITTING,
            FinancingAttemptState::CP_FAILED_RETRYABLE,
            FinancingAttemptState::CP_OUTCOME_UNKNOWN,
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
        ?int $httpStatus
    ): void {
        if ($this->logger === null) {
            return;
        }
        ($this->logger)([
            'event' => $event,
            'attempt_id' => $attemptId,
            'entry_point' => $entryPoint,
            'store_id' => $storeId,
            'local_order_id' => $localOrderId,
            'cp_order_id' => $cpOrderId,
            'error_class' => $errorClass,
            'http_status' => $httpStatus,
        ]);
    }
}
