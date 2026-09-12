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
        private ControlPanelStatusSyncService $statusSync,
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
            // Replay of proven SmartUCF success: never re-create session; restore local then PATCH.
            if ($known->isCreated()) {
                $this->reconcileProcess1BankStatusOnReplay(
                    $attemptId,
                    $submission->storeId,
                    $localOrderId,
                    (string) $submission->shopUnicid
                );
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
                    $this->reconcileProcess1BankStatusOnReplay(
                        $attemptId,
                        $submission->storeId,
                        $localOrderId,
                        (string) $submission->shopUnicid
                    );
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
            $this->persistFailureBankStatus($attemptId, $storeId, $localOrderId);
        }

        return SmartUcfCoordinationResult::failed(
            self::CUSTOMER_FAILED,
            $classification->isRetryable(),
            $classification->errorClass()
        );
    }

    /**
     * After proven SmartUCF Process 1 success:
     * admit durable pending target → local bank_sent_process1 → PATCH from target.
     * On CONFLICT: stop with no local mutation and no PATCH.
     * Local write failure: log and keep pending target (do not swallow silently).
     */
    private function persistProcess1BankStatus(int $attemptId, int $storeId, int $localOrderId): void
    {
        $status = BankStatus::process1Sent();
        $shopOrderId = substr((string) $localOrderId, 0, 13);

        $decision = $this->statusSync->admitTarget(
            $attemptId,
            $status['status_id'],
            $status['status_label']
        );
        if ($decision === ControlPanelStatusSyncService::CONFLICT
            || $decision === ControlPanelStatusSyncService::REJECT
        ) {
            error_log(
                'mt_uni_credit: Process 1 durable target admission blocked'
                    . ' decision=' . $decision
                    . ' attempt_id=' . $attemptId
                    . ' status_id=' . $status['status_id']
            );

            return;
        }

        if ($decision === ControlPanelStatusSyncService::SAME) {
            $existing = $this->statusSync->readPersistedTarget($attemptId);
            if ($existing === null
                || !$this->isExactCanonicalProcess1Target(
                    $existing['status_id'] ?? null,
                    $existing['status'] ?? null
                )
            ) {
                error_log(
                    'mt_uni_credit: Process 1 durable SAME admission without exact P1 target'
                        . ' attempt_id=' . $attemptId
                );

                return;
            }
        }

        try {
            $this->bankStatuses->upsertAuthorizedLocal(
                $storeId,
                $localOrderId,
                $status['status_id'],
                $status['status_label']
            );
        } catch (\Throwable $exception) {
            error_log(
                'mt_uni_credit: Process 1 local bank status write failed'
                    . ' attempt_id=' . $attemptId
                    . ' store_id=' . $storeId
                    . ' order_id=' . $localOrderId
                    . ' class=' . $exception::class
                    . ' (pending CP target retained)'
            );

            return;
        }

        $syncState = $this->statusSync->retryPending($attemptId, $shopOrderId);
        if ($syncState === ControlPanelStatusSyncStates::PENDING
            || $syncState === ControlPanelStatusSyncStates::TERMINAL_FAILED
        ) {
            error_log(
                'mt_uni_credit: ' . self::ERROR_CP_BANK_STATUS_SYNC_PENDING
                    . ' attempt_id=' . $attemptId
                    . ' store_id=' . $storeId
                    . ' order_id=' . $localOrderId
                    . ' status_id=' . $status['status_id']
                    . ' sync_state=' . $syncState
            );
        }
    }

    private function persistFailureBankStatus(int $attemptId, int $storeId, int $localOrderId): void
    {
        $status = BankStatus::smartUcfFailure();
        $shopOrderId = substr((string) $localOrderId, 0, 13);

        $decision = $this->statusSync->admitTarget(
            $attemptId,
            $status['status_id'],
            $status['status_label']
        );
        if ($decision === ControlPanelStatusSyncService::CONFLICT
            || $decision === ControlPanelStatusSyncService::REJECT
        ) {
            error_log(
                'mt_uni_credit: SmartUCF failure durable target admission blocked'
                    . ' decision=' . $decision
                    . ' attempt_id=' . $attemptId
            );

            return;
        }

        try {
            $this->bankStatuses->upsertAuthorizedLocal(
                $storeId,
                $localOrderId,
                $status['status_id'],
                $status['status_label']
            );
        } catch (\Throwable $exception) {
            error_log(
                'mt_uni_credit: SmartUCF failure local bank status write failed'
                    . ' attempt_id=' . $attemptId
                    . ' class=' . $exception::class
                    . ' (pending CP target retained)'
            );

            return;
        }

        $this->statusSync->retryPending($attemptId, $shopOrderId);
    }

    /**
     * Replay after SmartUCF created: persisted CP target is the sole authority.
     *
     * Requires an exact canonical Process 1 target (status_id + status text).
     * Ordinary replay never synthesizes a missing target.
     * When sync is not_needed, a separately guarded explicit recovery may run.
     * Local terminal fact must exist before any PATCH.
     */
    private function reconcileProcess1BankStatusOnReplay(
        int $attemptId,
        int $storeId,
        int $localOrderId,
        string $authoritativeUnicid
    ): void {
        $canonical = BankStatus::process1Sent();
        $shopOrderId = substr((string) $localOrderId, 0, 13);
        $target = $this->statusSync->readPersistedTarget($attemptId);

        if ($target === null) {
            return;
        }

        $state = (string) ($target['state'] ?? ControlPanelStatusSyncStates::NOT_NEEDED);
        $statusId = $target['status_id'] ?? null;
        $statusText = $target['status'] ?? null;

        // Ordinary replay never synthesizes from not_needed — explicit recovery is separate.
        if ($state === ControlPanelStatusSyncStates::NOT_NEEDED || $state === '') {
            error_log(
                'mt_uni_credit: Process 1 ordinary replay detected missing CP sync target'
                    . ' attempt_id=' . $attemptId
                    . ' store_id=' . $storeId
                    . ' order_id=' . $localOrderId
                    . '; invoking guarded post-SmartUCF recovery'
            );
            $this->recoverMissingProcess1TargetAfterCreatedSmartUcf(
                $attemptId,
                $storeId,
                $localOrderId,
                $authoritativeUnicid
            );

            return;
        }

        if ($state === ControlPanelStatusSyncStates::TERMINAL_FAILED) {
            error_log(
                'mt_uni_credit: Process 1 replay blocked by terminal_failed sync target'
                    . ' attempt_id=' . $attemptId
                    . ' status_id=' . (string) $statusId
                    . ' error_class=' . (string) ($target['error_class'] ?? '')
            );

            return;
        }

        if (!$this->isExactCanonicalProcess1Target($statusId, $statusText)) {
            error_log(
                'mt_uni_credit: Process 1 replay blocked by incomplete/conflicting durable target'
                    . ' attempt_id=' . $attemptId
                    . ' status_id=' . (string) $statusId
                    . ' status=' . (string) $statusText
            );

            return;
        }

        if ($state !== ControlPanelStatusSyncStates::PENDING
            && $state !== ControlPanelStatusSyncStates::CONFIRMED
        ) {
            return;
        }

        try {
            $this->bankStatuses->upsertAuthorizedLocal(
                $storeId,
                $localOrderId,
                $canonical['status_id'],
                $canonical['status_label']
            );
        } catch (\Throwable $exception) {
            error_log(
                'mt_uni_credit: Process 1 replay local bank status write failed'
                    . ' attempt_id=' . $attemptId
                    . ' store_id=' . $storeId
                    . ' order_id=' . $localOrderId
                    . ' class=' . $exception::class
                    . ' (pending CP target retained; PATCH skipped)'
            );

            return;
        }

        if ($state === ControlPanelStatusSyncStates::CONFIRMED) {
            // Local restored; CP already confirmed — never send a second PATCH.
            return;
        }

        $syncState = $this->statusSync->retryPending($attemptId, $shopOrderId);
        if ($syncState === ControlPanelStatusSyncStates::PENDING
            || $syncState === ControlPanelStatusSyncStates::TERMINAL_FAILED
        ) {
            error_log(
                'mt_uni_credit: ' . self::ERROR_CP_BANK_STATUS_SYNC_PENDING
                    . ' attempt_id=' . $attemptId
                    . ' store_id=' . $storeId
                    . ' order_id=' . $localOrderId
                    . ' status_id=' . $canonical['status_id']
                    . ' sync_state=' . $syncState
            );
        }
    }

    /**
     * Explicit post-SmartUCF / pre-target crash recovery.
     *
     * Invoked from production created-state replay only after ordinary path detects not_needed.
     * Admits a missing P1 target only when durable SmartUCF success + exact ownership are proven.
     *
     * @return bool true when a P1 target was admitted and local fact restored (PATCH may still be pending)
     */
    public function recoverMissingProcess1TargetAfterCreatedSmartUcf(
        int $attemptId,
        int $storeId,
        int $localOrderId,
        string $authoritativeUnicid
    ): bool {
        $row = $this->lifecycle->findByAttempt($attemptId);
        if ($row === null) {
            return false;
        }
        if ((string) ($row['smartucf_state'] ?? '') !== SmartUcfLifecycleStates::CREATED) {
            return false;
        }
        if ((int) ($row['store_id'] ?? -1) !== $storeId) {
            return false;
        }

        $storedOrder = $this->canonicalOrderId($row['order_id'] ?? null);
        $localOrder = $this->canonicalOrderId($localOrderId);
        if ($storedOrder === null || $localOrder === null || $storedOrder !== $localOrder) {
            error_log(
                'mt_uni_credit: Process 1 missing-target recovery blocked by order identity mismatch'
                    . ' attempt_id=' . $attemptId
                    . ' stored_order_id=' . (string) ($row['order_id'] ?? '')
                    . ' local_order_id=' . $localOrderId
            );

            return false;
        }

        $attemptUnicid = trim((string) ($row['unicid'] ?? ''));
        $ownedUnicid = trim($authoritativeUnicid);
        if ($attemptUnicid === '' || $ownedUnicid === '' || !hash_equals($attemptUnicid, $ownedUnicid)) {
            error_log(
                'mt_uni_credit: Process 1 missing-target recovery blocked by UNICID ownership'
                    . ' attempt_id=' . $attemptId
            );

            return false;
        }

        $process2State = (string) ($row['process2_state'] ?? ProcessTwoLifecycleStates::NOT_STARTED);
        if ($process2State !== ProcessTwoLifecycleStates::NOT_STARTED && $process2State !== '') {
            error_log(
                'mt_uni_credit: Process 1 missing-target recovery blocked by Process 2 lifecycle state'
                    . ' attempt_id=' . $attemptId
                    . ' process2_state=' . $process2State
            );

            return false;
        }

        $target = $this->statusSync->readPersistedTarget($attemptId);
        if ($target === null) {
            return false;
        }

        $state = (string) ($target['state'] ?? ControlPanelStatusSyncStates::NOT_NEEDED);
        $statusId = $target['status_id'] ?? null;
        $statusText = $target['status'] ?? null;

        // Only the exact post-SmartUCF / pre-admission crash window.
        if ($state !== ControlPanelStatusSyncStates::NOT_NEEDED
            || $statusId !== null
            || $statusText !== null
        ) {
            return false;
        }

        $local = $this->bankStatuses->findCurrentStatus($storeId, $localOrderId);
        $localStatusId = is_array($local) ? (string) ($local['status_id'] ?? '') : '';
        if ($localStatusId === BankStatus::SENT_PROCESS2) {
            error_log(
                'mt_uni_credit: Process 1 missing-target recovery blocked by local Process 2 fact'
                    . ' attempt_id=' . $attemptId
                    . ' order_id=' . $localOrderId
            );

            return false;
        }

        $this->persistProcess1BankStatus($attemptId, $storeId, $localOrderId);

        $after = $this->statusSync->readPersistedTarget($attemptId);
        if ($after === null) {
            return false;
        }

        return $this->isExactCanonicalProcess1Target(
            $after['status_id'] ?? null,
            $after['status'] ?? null
        );
    }

    /**
     * Canonical shop order id for identity binding (positive decimal, max 13 digits, no coercion).
     */
    private function canonicalOrderId(mixed $value): ?string
    {
        if (is_int($value)) {
            if ($value <= 0) {
                return null;
            }

            return substr((string) $value, 0, 13);
        }
        if (!is_string($value)) {
            return null;
        }
        if (!preg_match('/^[1-9][0-9]{0,12}$/', $value)) {
            return null;
        }

        return substr($value, 0, 13);
    }

    private function isExactCanonicalProcess1Target(?string $statusId, ?string $statusText): bool
    {
        $canonical = BankStatus::process1Sent();

        return $statusId === $canonical['status_id']
            && $statusText === $canonical['status_label'];
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
