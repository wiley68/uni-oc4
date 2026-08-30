<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class SmartUcfSessionCoordinator
{
    public const ERROR_CREDENTIALS_SYNC_FAILED = 'smartucf_credentials_sync_failed';

    public const CUSTOMER_OUTCOME_UNKNOWN =
        'Поръчката е създадена, но потвърждението от банковата система не беше получено. Не изпращайте заявката повторно.';
    public const CUSTOMER_PROCESSING = 'Заявката към банката се обработва. Моля, изчакайте.';
    public const CUSTOMER_FAILED =
        'Поръчката и заявката в Контролния панел са създадени, но изпращането към банковата система не беше успешно.';

    private object $client;

    private CertificateSynchronizer $certificateSynchronizer;

    public function __construct(
        private SmartUcfLifecycleRepository $lifecycle,
        object $client,
        private SmartUcfFailureClassifier $classifier,
        private OrderBankStatusRepository $bankStatuses,
        private ControlPanelClient $controlPanel,
        ?CertificateSynchronizer $certificateSynchronizer = null
    ) {
        if (!method_exists($client, 'createSession')) {
            throw new \InvalidArgumentException('SmartUCF client must provide createSession().');
        }
        $this->client = $client;
        $this->certificateSynchronizer = $certificateSynchronizer ?? new CertificateSynchronizer($controlPanel);
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

            return $latest === null
                ? SmartUcfCoordinationResult::processing(self::CUSTOMER_PROCESSING)
                : ($this->resultFromState($latest) ?? SmartUcfCoordinationResult::processing(self::CUSTOMER_PROCESSING));
        }

        try {
            /** @var array{session_id: string, redirect_url: string, http_code: int} $session */
            $session = $lease === null
                ? $this->client->createSession($shop, $submission, $localOrderId)
                : $this->client->createSession($shop, $submission, $localOrderId, $lease);
        } catch (\Throwable $exception) {
            return $this->handleFailure($attemptId, $submission->storeId, $localOrderId, $cpOrderId, $exception);
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

        $this->persistBankStatus(
            $submission->storeId,
            $localOrderId,
            $cpOrderId,
            BankStatus::process1Sent()
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
        int $cpOrderId,
        \Throwable $exception
    ): SmartUcfCoordinationResult {
        $classification = $this->classifier->classifyThrowable($exception);
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
            $this->persistBankStatus($storeId, $localOrderId, $cpOrderId, BankStatus::smartUcfFailure());
        }

        return SmartUcfCoordinationResult::failed(
            self::CUSTOMER_FAILED,
            $classification->isRetryable(),
            $classification->errorClass()
        );
    }

    /** @param array{status_id: string, status_label: string} $status */
    private function persistBankStatus(int $storeId, int $localOrderId, int $cpOrderId, array $status): void
    {
        try {
            $this->bankStatuses->updateByOrderIdentifier(
                $storeId,
                (string) $localOrderId,
                $status['status_id'],
                $status['status_label']
            );
        } catch (\Throwable $ignored) {
        }
        try {
            $this->controlPanel->updateOrderStatus(
                (string) $cpOrderId,
                $status['status_label'],
                $status['status_id']
            );
        } catch (\Throwable $ignored) {
        }
    }
}
