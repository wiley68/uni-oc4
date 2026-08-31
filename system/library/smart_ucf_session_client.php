<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class SmartUcfSessionClient
{
    public const HTTP_TIMEOUT_SECONDS = 10;

    public function __construct(
        private ?SmartUcfPayloadBuilder $payloadBuilder = null,
        private ?SmartUcfEndpointPolicy $endpointPolicy = null,
        private ?CertificateLocalPaths $certificatePaths = null,
        private ?MtlsPrivateKeyPassphraseProvider $passphrases = null
    ) {
        $this->payloadBuilder ??= new SmartUcfPayloadBuilder();
        $this->endpointPolicy ??= new SmartUcfEndpointPolicy();
        $this->certificatePaths ??= new CertificateLocalPaths();
        $this->passphrases ??= new MtlsPrivateKeyPassphraseProvider();
    }

    /**
     * @param array<string, mixed> $shop
     * @return array{session_id: string, redirect_url: string, http_code: int, raw_request: string, raw_response: string, endpoint: string}
     */
    public function createSession(
        array $shop,
        ValidatedFinancingSubmission $submission,
        int $localOrderId,
        ?CertificateConsumerLease $lease = null
    ): array {
        try {
            $url = $this->endpointPolicy->buildSessionStartUrl($this->serviceUrl($shop));
            $application = $this->endpointPolicy->assertTrustedApplicationBase($this->applicationUrl($shop));
            $payload = $this->payloadBuilder->build($submission, $shop, $localOrderId);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new SmartUcfSessionException(
                'SmartUCF request could not be prepared.',
                true,
                '',
                0,
                SmartUcfSessionException::KIND_PRE_SEND,
                $exception
            );
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'cache-control: no-cache'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if (ShopConfigurationFlags::usesSmartUcfCertificate($shop)) {
            $key = $lease !== null ? $lease->privateKeyPath() : $this->certificatePaths->privateKeyPath();
            $certificate = $lease !== null ? $lease->certificatePath() : $this->certificatePaths->certificatePath();
            if (!is_readable($key) || !is_readable($certificate)) {
                throw new SmartUcfSessionException(
                    'SmartUCF SSL key or certificate is missing or unreadable.',
                    true,
                    '',
                    0,
                    SmartUcfSessionException::KIND_PRE_SEND
                );
            }
            $password = $lease !== null ? $lease->password() : $this->passphrases->require();
            $options[CURLOPT_SSLKEY] = $key;
            $options[CURLOPT_SSLKEYPASSWD] = $password;
            $options[CURLOPT_SSLCERT] = $certificate;
            $options[CURLOPT_SSLCERTPASSWD] = $password;
            $options[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new SmartUcfSessionException(
                'SmartUCF HTTP client could not be initialized.',
                true,
                '',
                0,
                SmartUcfSessionException::KIND_PRE_SEND
            );
        }
        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        $error = curl_error($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        $raw = is_string($response) ? $response : '';

        if ($error !== '' || $raw === '') {
            throw new SmartUcfSessionException(
                $error !== '' ? 'SmartUCF connection failed: ' . $error : 'SmartUCF returned an empty response.',
                false,
                $raw !== '' ? $raw : $error,
                $httpCode,
                SmartUcfSessionException::KIND_TRANSPORT
            );
        }

        $decoded = json_decode($raw);
        if (!is_object($decoded)) {
            throw new SmartUcfSessionException(
                'SmartUCF returned invalid JSON.',
                false,
                $raw,
                $httpCode,
                SmartUcfSessionException::KIND_TRANSPORT
            );
        }
        $sessionId = trim((string) ($decoded->sucfOnlineSessionID ?? ''));
        if ($sessionId === '') {
            throw new SmartUcfSessionException(
                'SmartUCF did not return a session identifier.',
                false,
                $raw,
                $httpCode,
                $this->detectFailureKind($raw, $httpCode)
            );
        }

        try {
            $redirect = $this->endpointPolicy->buildApplicationRedirect($application, $sessionId);
        } catch (\InvalidArgumentException $exception) {
            throw new SmartUcfSessionException(
                'SmartUCF session redirect could not be built safely.',
                false,
                $raw,
                $httpCode,
                SmartUcfSessionException::KIND_TRANSPORT,
                $exception
            );
        }

        return [
            'session_id' => $sessionId,
            'redirect_url' => $redirect,
            'http_code' => $httpCode,
            'raw_request' => $json,
            'raw_response' => $raw,
            'endpoint' => $url,
        ];
    }

    private function detectFailureKind(string $raw, int $httpCode): string
    {
        $value = strtolower($raw);
        if ((str_contains($value, 'duplicate') && str_contains($value, 'order'))
            || str_contains($value, 'already exists') || str_contains($value, 'съществува')
        ) {
            return SmartUcfSessionException::KIND_DUPLICATE;
        }

        return ($httpCode === 0 || $httpCode >= 500)
            ? SmartUcfSessionException::KIND_TRANSPORT
            : SmartUcfSessionException::KIND_REMOTE;
    }

    /** @param array<string, mixed> $shop */
    private function serviceUrl(array $shop): string
    {
        return ShopConfigurationFlags::isTestEnvironment($shop)
            ? trim((string) ($shop['uni_test_service'] ?? ''))
            : trim((string) ($shop['uni_production_service'] ?? ''));
    }

    /** @param array<string, mixed> $shop */
    private function applicationUrl(array $shop): string
    {
        return ShopConfigurationFlags::isTestEnvironment($shop)
            ? trim((string) ($shop['uni_test_application'] ?? ''))
            : trim((string) ($shop['uni_production_application'] ?? ''));
    }
}
