<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class SmartUcfSessionCoordinator
{
    public const ERROR_CREDENTIALS_SYNC_FAILED = 'smartucf_credentials_sync_failed';

    /** Recoverable: SmartUCF already succeeded; CP PATCH /orders/status did not. */
    public const ERROR_CP_BANK_STATUS_SYNC_PENDING = 'cp_bank_status_sync_pending';

    public const CUSTOMER_OUTCOME_UNKNOWN =
    'Поръчката е създадена, но потвърждението от банковата система не беше получено. Не изпращайте заявката повторно.';
    public const CUSTOMER_PROCESSING = 'Заявката към банката се обработва. Моля, изчакайте.';
    public const CUSTOMER_FAILED =
    'Поръчката и заявката в Контролния панел са създадени, но изпращането към банковата система не беше успешно.';

    private object $client;

    private CertificateSynchronizer $certificateSynchronizer;

    private ?SmartUcfDiagnosticJournal $diagnosticJournal;

    private SmartUcfPayloadBuilder $payloadBuilder;

    public function __construct(
        private SmartUcfLifecycleRepository $lifecycle,
        object $client,
        private SmartUcfFailureClassifier $classifier,
        private OrderBankStatusRepository $bankStatuses,
        private ControlPanelClient $controlPanel,
        ?CertificateSynchronizer $certificateSynchronizer = null,
        ?SmartUcfDiagnosticJournal $diagnosticJournal = null,
        ?SmartUcfPayloadBuilder $payloadBuilder = null
    ) {
        if (!method_exists($client, 'createSession')) {
            throw new \InvalidArgumentException('SmartUCF client must provide createSession().');
        }
        $this->client = $client;
        $this->certificateSynchronizer = $certificateSynchronizer ?? new CertificateSynchronizer($controlPanel);
        $this->diagnosticJournal = $diagnosticJournal;
        $this->payloadBuilder = $payloadBuilder ?? new SmartUcfPayloadBuilder();
    }

    /**
     * @param array<string, mixed> $shop
     */
    public function run(
        int $attemptId,
        array $shop,
        ValidatedFinancingSubmission $submission,
        int $localOrderId,
        int $cpOrderId
    ): SmartUcfCoordinationResult {
        if (ShopConfigurationFlags::isSecondaryProcess($shop)) {
            return SmartUcfCoordinationResult::process2();
        }

        $row = $this->lifecycle->readAndNormalize($attemptId);
        if ($row === null) {
            return SmartUcfCoordinationResult::failed(
                self::CUSTOMER_FAILED,
                true,
                SmartUcfFailureClassification::CLASS_PRE_SEND
            );
        }
        $known = $this->resultFromState($row);
        if ($known !== null) {
            // Replay of proven SmartUCF success: never re-create session; reconcile bank status.
            if ($known->isCreated()) {
                $this->persistProcess1BankStatus($attemptId, $submission->storeId, $localOrderId);
            }

            return $known;
        }

        $lease = null;
        if (ShopConfigurationFlags::usesSmartUcfCertificate($shop)) {
            try {
                $lease = $this->certificateSynchronizer->ensureCurrent();
            } catch (CertificateSyncException $exception) {
                $errorClass = self::ERROR_CREDENTIALS_SYNC_FAILED . '_' . $exception->reason();
                try {
                    $this->lifecycle->markFailed($attemptId, $errorClass, true);
                } catch (\Throwable $ignored) {
                }

                return SmartUcfCoordinationResult::failed(self::CUSTOMER_FAILED, true, $errorClass);
            }
        }

        $claimed = $this->lifecycle->claimForSubmitting($attemptId);
        if ($claimed === null) {
            if ($lease !== null) {
                $lease->release();
            }
            $latest = $this->lifecycle->readAndNormalize($attemptId);
            if ($latest === null) {
                return SmartUcfCoordinationResult::processing(self::CUSTOMER_PROCESSING);
            }
            $fromLatest = $this->resultFromState($latest);
            if ($fromLatest !== null) {
                if ($fromLatest->isCreated()) {
                    $this->persistProcess1BankStatus($attemptId, $submission->storeId, $localOrderId);
                }

                return $fromLatest;
            }

            return SmartUcfCoordinationResult::processing(self::CUSTOMER_PROCESSING);
        }

        $endpoint = '';
        $smartUcfPayload = null;
        try {
            try {
                $endpoint = (new SmartUcfEndpointPolicy())->buildSessionStartUrl(
                    ShopConfigurationFlags::isTestEnvironment($shop)
                        ? trim((string) ($shop['uni_test_service'] ?? ''))
                        : trim((string) ($shop['uni_production_service'] ?? ''))
                );
                $smartUcfPayload = $this->payloadBuilder->build($submission, $shop, $localOrderId);
            } catch (\Throwable $ignored) {
                // Payload/endpoint resolution failures are logged from the client/pre-send exception path.
            }

            /** @var array{session_id: string, redirect_url: string, http_code: int, raw_request?: string, raw_response?: string, endpoint?: string} $session */
            $session = $lease === null
                ? $this->client->createSession($shop, $submission, $localOrderId)
                : $this->client->createSession($shop, $submission, $localOrderId, $lease);
        } catch (\Throwable $exception) {
            return $this->handleFailure(
                $attemptId,
                $submission->storeId,
                $localOrderId,
                $submission->entryPoint,
                $exception,
                $smartUcfPayload,
                $endpoint
            );
        } finally {
            if ($lease !== null) {
                $lease->release();
            }
        }

        try {
            $this->lifecycle->markCreated(
                $attemptId,
                (string) $session['session_id'],
                (string) $session['redirect_url'],
                (int) ($session['http_code'] ?? 0)
            );
        } catch (\Throwable $exception) {
            try {
                $this->lifecycle->markOutcomeUnknown(
                    $attemptId,
                    SmartUcfFailureClassification::CLASS_TRANSPORT_AMBIGUOUS,
                    (int) ($session['http_code'] ?? 0)
                );
            } catch (\Throwable $ignored) {
            }

            return SmartUcfCoordinationResult::outcomeUnknown(self::CUSTOMER_OUTCOME_UNKNOWN);
        }

        $this->persistProcess1BankStatus($attemptId, $submission->storeId, $localOrderId);

        $this->logSuccessfulSession(
            $submission->storeId,
            $localOrderId,
            $submission->entryPoint,
            $session,
            $endpoint
        );

        return SmartUcfCoordinationResult::created(
            (string) $session['redirect_url'],
            (string) $session['session_id']
        );
    }

    /** @param array<string, mixed> $row */
    private function resultFromState(array $row): ?SmartUcfCoordinationResult
    {
        $state = (string) ($row['smartucf_state'] ?? SmartUcfLifecycleStates::NOT_STARTED);
        if ($state === SmartUcfLifecycleStates::CREATED) {
            $redirect = (string) ($row['smartucf_redirect_url'] ?? '');
            $session = (string) ($row['smartucf_session_id'] ?? '');
            if ($redirect !== '' && (new SmartUcfEndpointPolicy())->isTrustedApplicationRedirect($redirect)) {
                return SmartUcfCoordinationResult::created($redirect, $session);
            }

            return SmartUcfCoordinationResult::outcomeUnknown(self::CUSTOMER_OUTCOME_UNKNOWN);
        }
        if ($state === SmartUcfLifecycleStates::SUBMITTING) {
            return SmartUcfCoordinationResult::processing(self::CUSTOMER_PROCESSING);
        }
        if ($state === SmartUcfLifecycleStates::OUTCOME_UNKNOWN) {
            return SmartUcfCoordinationResult::outcomeUnknown(self::CUSTOMER_OUTCOME_UNKNOWN);
        }
        if ($state === SmartUcfLifecycleStates::FAILED && empty($row['smartucf_retryable'])) {
            return SmartUcfCoordinationResult::failed(
                self::CUSTOMER_FAILED,
                false,
                (string) ($row['smartucf_error_class'] ?? '')
            );
        }

        return null;
    }

    private function handleFailure(
        int $attemptId,
        int $storeId,
        int $localOrderId,
        string $entryPoint,
        \Throwable $exception,
        mixed $requestPayload = null,
        string $endpoint = ''
    ): SmartUcfCoordinationResult {
        $classification = $this->classifier->classifyThrowable($exception);
        $this->logFailedSession(
            $storeId,
            $localOrderId,
            $entryPoint,
            $exception,
            $requestPayload,
            $endpoint,
            $classification
        );
        if ($classification->targetState() === SmartUcfLifecycleStates::OUTCOME_UNKNOWN) {
            try {
                $this->lifecycle->markOutcomeUnknown(
                    $attemptId,
                    $classification->errorClass(),
                    $classification->httpCode()
                );
            } catch (\Throwable $ignored) {
            }

            return SmartUcfCoordinationResult::outcomeUnknown(self::CUSTOMER_OUTCOME_UNKNOWN);
        }

        try {
            $this->lifecycle->markFailed(
                $attemptId,
                $classification->errorClass(),
                $classification->isRetryable(),
                $classification->httpCode()
            );
        } catch (\Throwable $ignored) {
        }
        if ($classification->errorClass() === SmartUcfFailureClassification::CLASS_REMOTE_REJECT) {
            $this->persistBankStatusPair($storeId, $localOrderId, BankStatus::smartUcfFailure());
        }

        return SmartUcfCoordinationResult::failed(
            self::CUSTOMER_FAILED,
            $classification->isRetryable(),
            $classification->errorClass()
        );
    }

    /**
     * After proven SmartUCF Process 1 success: local + CP bank_sent_process1.
     * CP PATCH uses the shop order_id (same as POST /orders), not the CP internal id.
     * CP failure leaves SmartUCF success durable and marks a recoverable sync pending class.
     */
    private function persistProcess1BankStatus(int $attemptId, int $storeId, int $localOrderId): void
    {
        $status = BankStatus::process1Sent();
        $cpSynced = $this->persistBankStatusPair($storeId, $localOrderId, $status);
        if (!$cpSynced) {
            error_log(
                'mt_uni_credit: ' . self::ERROR_CP_BANK_STATUS_SYNC_PENDING
                    . ' attempt_id=' . $attemptId
                    . ' store_id=' . $storeId
                    . ' order_id=' . $localOrderId
                    . ' status_id=' . $status['status_id']
            );
        }
    }

    /**
     * @param array{status_id: string, status_label: string} $status
     * @return bool true when Control Panel PATCH succeeded (local write may still have succeeded)
     */
    private function persistBankStatusPair(int $storeId, int $localOrderId, array $status): bool
    {
        $shopOrderId = substr((string) $localOrderId, 0, 13);
        try {
            $this->bankStatuses->updateByOrderIdentifier(
                $storeId,
                $shopOrderId,
                $status['status_id'],
                $status['status_label']
            );
        } catch (\Throwable $ignored) {
        }

        try {
            // CP looks up by shop order_id from create payload — never the CP internal PK.
            $this->controlPanel->updateOrderStatus(
                $shopOrderId,
                $status['status_label'],
                $status['status_id']
            );

            return true;
        } catch (\Throwable $exception) {
            error_log(
                'mt_uni_credit: Control Panel bank status PATCH failed: '
                    . $exception::class
                    . ' order_id=' . $shopOrderId
                    . ' status_id=' . $status['status_id']
            );

            return false;
        }
    }

    /**
     * @param array<string, mixed> $session
     */
    private function logSuccessfulSession(
        int $storeId,
        int $localOrderId,
        string $entryPoint,
        array $session,
        string $endpointFallback
    ): void {
        if ($this->diagnosticJournal === null) {
            return;
        }

        try {
            $this->diagnosticJournal->recordSmartUcfSession(
                $storeId,
                $localOrderId,
                $entryPoint,
                (string) ($session['endpoint'] ?? $endpointFallback),
                (string) ($session['raw_request'] ?? ''),
                (string) ($session['raw_response'] ?? ''),
                (int) ($session['http_code'] ?? 0),
                null,
                'success'
            );
        } catch (\Throwable $ignored) {
        }
    }

    private function logFailedSession(
        int $storeId,
        int $localOrderId,
        string $entryPoint,
        \Throwable $exception,
        mixed $requestPayload,
        string $endpointFallback,
        SmartUcfFailureClassification $classification
    ): void {
        if ($this->diagnosticJournal === null) {
            return;
        }

        $httpCode = $exception instanceof SmartUcfSessionException ? $exception->httpCode() : 0;
        $rawResponse = $exception instanceof SmartUcfSessionException ? $exception->rawResponse() : '';
        $transportError = null;
        if ($classification->targetState() === SmartUcfLifecycleStates::OUTCOME_UNKNOWN) {
            $transportError = $exception->getMessage();
        } elseif (
            $exception instanceof SmartUcfSessionException
            && $exception->getFailureKind() === SmartUcfSessionException::KIND_TRANSPORT
        ) {
            $transportError = $exception->getMessage();
        }

        $request = $requestPayload;
        if ($request === null && $exception instanceof SmartUcfSessionException) {
            $request = '';
        }

        $response = $rawResponse !== '' ? $rawResponse : (
            $transportError !== null ? null : $exception->getMessage()
        );

        try {
            $this->diagnosticJournal->recordSmartUcfSession(
                $storeId,
                $localOrderId,
                $entryPoint,
                $endpointFallback,
                $request ?? '',
                $response,
                $httpCode,
                $transportError,
                $classification->errorClass()
            );
        } catch (\Throwable $ignored) {
        }
    }
}
