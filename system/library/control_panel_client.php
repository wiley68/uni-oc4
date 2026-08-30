<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Control Panel HTTP client — login, refresh, logout, GET /shop (Phase 4).
 */
final class ControlPanelClient implements ControlPanelOrderStatusPort
{
    private ModuleCredentialsRepository $credentials;

    private CpTokenRepository $tokens;

    private CpHttpTransport $transport;

    private string $shopName;

    private string $baseUrl;

    private int $storeId;

    /** @var callable(): int */
    private $clock;

    public function __construct(
        ModuleCredentialsRepository $credentials,
        CpTokenRepository $tokens,
        CpHttpTransport $transport,
        string $shopName,
        int $storeId,
        ?string $baseUrl = null,
        ?callable $clock = null
    ) {
        $this->credentials = $credentials;
        $this->tokens = $tokens;
        $this->transport = $transport;
        $this->shopName = rtrim(trim($shopName), '/');
        $this->storeId = $storeId;
        $resolved = $baseUrl !== null && trim($baseUrl) !== ''
            ? $baseUrl
            : (new ModuleDeploymentEnvironment())->controlPanelApiBaseUrl();
        $this->baseUrl = rtrim($resolved, '/');
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @return array<string, mixed> */
    public function login(): array
    {
        $unicid = $this->credentials->getUnicid($this->storeId);
        $secret = $this->credentials->getSecret($this->storeId);
        if ($unicid === '' || $secret === null || $this->shopName === '') {
            $this->tokens->invalidate();
            throw new CpAuthenticationException('The Control Panel credentials are incomplete.');
        }

        $response = $this->send('POST', '/auth/login', [
            'unicid' => $unicid,
            'name' => $this->shopName,
            'secret' => $secret,
        ]);
        $this->storeTokenResponse($response);

        if (!isset($response['shop']) || !is_array($response['shop'])) {
            $this->tokens->invalidate();
            throw new CpInvalidPayloadException('The Control Panel login response has no valid shop data.');
        }

        return $response;
    }

    /** @return array<string, mixed> */
    public function refreshToken(): array
    {
        $token = $this->tokens->getAccessToken();
        if ($token === null) {
            throw new CpAuthenticationException('There is no Control Panel token to refresh.');
        }

        try {
            $response = $this->send('POST', '/auth/refresh', null, $token);
            $this->storeTokenResponse($response);

            return $response;
        } catch (CpAuthenticationException $exception) {
            $this->tokens->invalidate();
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function logout(): array
    {
        $token = $this->tokens->getAccessToken();
        if ($token === null) {
            return ['success' => true];
        }

        try {
            return $this->send('POST', '/auth/logout', null, $token);
        } finally {
            $this->tokens->invalidate();
        }
    }

    /** @return array<string, mixed> */
    public function getShop(): array
    {
        $response = $this->authenticatedRequest('GET', '/shop');
        if (!isset($response['data']) || !is_array($response['data'])) {
            throw new CpInvalidPayloadException('The Control Panel shop response has no valid data object.');
        }

        return $response;
    }

    /**
     * @return array{
     *     available: bool,
     *     ssl_revision: string,
     *     certificate_sha256: string,
     *     private_key_sha256: string,
     *     not_before: string,
     *     not_after: string
     * }
     */
    public function getSslCertificateMetadata(): array
    {
        $response = $this->authenticatedRequest('GET', '/ssl/certificate');
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new CpInvalidPayloadException('The Control Panel SSL metadata response has no data object.');
        }

        return $this->normalizeSslMetadata($data);
    }

    /**
     * @return array{
     *     available: bool,
     *     ssl_revision: string,
     *     certificate_sha256: string,
     *     private_key_sha256: string,
     *     not_before: string,
     *     not_after: string,
     *     certificate_pem: string,
     *     private_key_pem: string
     * }
     */
    public function downloadSslCertificateBundle(): array
    {
        $response = $this->authenticatedRequest('GET', '/ssl/certificate/bundle');
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new CpInvalidPayloadException('The Control Panel SSL bundle response has no data object.');
        }
        foreach (['certificate_pem', 'private_key_pem', 'certificate_sha256', 'private_key_sha256'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                throw new CpInvalidPayloadException('The Control Panel SSL bundle is missing required fields.');
            }
        }
        $metadata = $this->normalizeSslMetadata(array_merge($data, ['available' => true]));

        return $metadata + [
            'certificate_pem' => (string) $data['certificate_pem'],
            'private_key_pem' => (string) $data['private_key_pem'],
        ];
    }

    /**
     * POST /orders — Phase 10B financing order create (idempotent by shop_id + order_id).
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function createOrder(array $order): array
    {
        $response = $this->authenticatedRequest('POST', '/orders', $order);
        if (!isset($response['data']) || !is_array($response['data'])) {
            throw new CpInvalidPayloadException('The Control Panel order response has no valid data object.');
        }

        return $response;
    }

    /**
     * PATCH /orders/status after a definitive bank lifecycle transition.
     */
    public function updateOrderStatus(string $cpOrderId, string $statusLabel, string $statusId): void
    {
        $cpOrderId = trim($cpOrderId);
        $statusLabel = trim($statusLabel);
        $statusId = trim($statusId);
        if ($cpOrderId === '' || $statusId === '') {
            throw new CpInvalidPayloadException('Control Panel order status fields are incomplete.');
        }
        $this->authenticatedRequest('PATCH', '/orders/status', [
            'order_id' => $cpOrderId,
            'status' => $statusLabel,
            'status_id' => $statusId,
        ]);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function authenticatedRequest(string $method, string $path, ?array $payload = null): array
    {
        $token = $this->ensureToken();

        try {
            return $this->send($method, $path, $payload, $token);
        } catch (CpAuthenticationException $exception) {
            $this->tokens->invalidate();
            $this->login();
            $retryToken = $this->tokens->getAccessToken();
            if ($retryToken === null) {
                throw new CpAuthenticationException('Control Panel re-authentication did not provide a token.');
            }

            try {
                return $this->send($method, $path, $payload, $retryToken);
            } catch (CpAuthenticationException $retryException) {
                $this->tokens->invalidate();
                throw $retryException;
            }
        }
    }

    private function ensureToken(): string
    {
        $token = $this->tokens->getAccessToken();
        $now = $this->now();
        $expiresAt = $this->tokens->getExpiresAt();

        if ($token === null || $expiresAt <= $now) {
            $this->tokens->invalidate();
            $this->login();

            return (string) $this->tokens->getAccessToken();
        }

        if ($expiresAt <= $now + CpHttpConstants::REFRESH_MARGIN_SECONDS) {
            try {
                $this->refreshToken();
            } catch (CpAuthenticationException $exception) {
                $this->login();
            }

            return (string) $this->tokens->getAccessToken();
        }

        return $token;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $payload = null, ?string $token = null): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if ($token !== null) {
            $headers['Authorization'] = $this->tokens->getTokenType() . ' ' . $token;
        }

        $response = $this->transport->request(
            $method,
            $this->baseUrl . '/' . ltrim($path, '/'),
            $headers,
            $payload
        );
        if ($response->getStatusCode() === 401) {
            throw new CpAuthenticationException('The Control Panel rejected the authentication.');
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new CpHttpException(
                $response->getStatusCode(),
                $this->decodeErrorResponse($response->getBody())
            );
        }

        $decoded = $this->decode($response->getBody());

        if (($decoded['success'] ?? null) !== true) {
            throw new CpInvalidPayloadException('The Control Panel response does not confirm success.');
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CpMalformedJsonException('The Control Panel returned malformed JSON.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new CpMalformedJsonException('The Control Panel JSON response is not an object.');
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function decodeErrorResponse(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *     available: bool,
     *     ssl_revision: string,
     *     certificate_sha256: string,
     *     private_key_sha256: string,
     *     not_before: string,
     *     not_after: string
     * }
     */
    private function normalizeSslMetadata(array $data): array
    {
        $available = !empty($data['available']);
        $certificateHash = strtolower(trim((string) ($data['certificate_sha256'] ?? '')));
        $privateKeyHash = strtolower(trim((string) ($data['private_key_sha256'] ?? '')));
        if (
            $available
            && (
                !preg_match('/^[a-f0-9]{64}$/', $certificateHash)
                || !preg_match('/^[a-f0-9]{64}$/', $privateKeyHash)
            )
        ) {
            throw new CpInvalidPayloadException('The Control Panel SSL metadata hashes are invalid.');
        }

        return [
            'available' => $available,
            'ssl_revision' => (string) ($data['ssl_revision'] ?? ''),
            'certificate_sha256' => $certificateHash,
            'private_key_sha256' => $privateKeyHash,
            'not_before' => isset($data['not_before']) ? (string) $data['not_before'] : '',
            'not_after' => isset($data['not_after']) ? (string) $data['not_after'] : '',
        ];
    }

    /** @param array<string, mixed> $response */
    private function storeTokenResponse(array $response): void
    {
        $accessToken = $response['access_token'] ?? null;
        $tokenType = $response['token_type'] ?? null;
        $expiresIn = $response['expires_in'] ?? null;

        if (
            !is_string($accessToken) || $accessToken === ''
            || !is_string($tokenType) || strcasecmp($tokenType, 'Bearer') !== 0
            || !is_numeric($expiresIn) || (int) $expiresIn <= 0
        ) {
            $this->tokens->invalidate();
            throw new CpInvalidPayloadException('The Control Panel token response is invalid.');
        }

        if (!$this->tokens->save($accessToken, $tokenType, $this->now() + (int) $expiresIn)) {
            $this->tokens->invalidate();
            throw new CpInvalidPayloadException('The Control Panel token could not be stored.');
        }
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }
}
