<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Control Panel HTTP client — login, refresh, logout, shop, orders (canonical envelopes).
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
        $this->storeTokenResponse($response, true);

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
            $this->storeTokenResponse($response, false);

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
            return InboundApiEnvelope::success('Logged out locally.');
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
        $data = $response['data'] ?? null;
        if (!is_array($data) || !$this->isAssociativeObject($data)) {
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
     * POST /orders — financing order create (idempotent by shop_id + order_id).
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function createOrder(array $order): array
    {
        $response = $this->authenticatedRequest('POST', '/orders', $order);
        $this->assertCreateOrderIdentity($response, $order);

        return $response;
    }

    /**
     * PATCH /orders/status after a definitive bank lifecycle transition.
     *
     * @param string $shopOrderId Shop order identifier — same value as POST /orders `order_id`
     *                            (local OpenCart order id), not the Control Panel internal PK.
     * @return array<string, mixed>
     */
    public function updateOrderStatus(string $shopOrderId, string $statusLabel, string $statusId): array
    {
        $shopOrderId = trim($shopOrderId);
        $statusLabel = trim($statusLabel);
        $statusId = trim($statusId);
        if ($shopOrderId === '' || $statusId === '' || $statusLabel === '') {
            throw new CpInvalidPayloadException('Control Panel order status fields are incomplete.');
        }
        $payload = [
            'order_id' => $shopOrderId,
            'status' => $statusLabel,
            'status_id' => $statusId,
        ];
        $response = $this->authenticatedRequest('PATCH', '/orders/status', $payload);
        $this->assertPatchStatusEcho($response, $payload);

        return $response;
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
            // POST /orders must never auto-replay after a remote response — lifecycle owns create ambiguity.
            if (!$this->allowsAuthenticationRetry($method, $path)) {
                throw $exception;
            }

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

    /**
     * Automatic login-and-retry after a *canonical* 401 is allowed only for idempotent routes.
     * Unsafe create (POST /orders) is never auto-replayed once a remote response was received.
     */
    private function allowsAuthenticationRetry(string $method, string $path): bool
    {
        $method = strtoupper($method);
        $normalized = '/' . trim($path, '/');

        if ($method === 'GET' && ($normalized === '/shop' || str_starts_with($normalized, '/ssl/'))) {
            return true;
        }

        if ($method === 'PATCH' && $normalized === '/orders/status') {
            return true;
        }

        return false;
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

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            // Including 401: bare/malformed bodies are not treated as safe auth evidence.
            throw $this->buildHttpFailure($response->getStatusCode(), $response->getBody());
        }

        return $this->decodeSuccessEnvelope($response->getBody());
    }

    private function buildHttpFailure(int $statusCode, string $body): \Throwable
    {
        try {
            $decodedObject = $this->decodeJsonAsObject($body);
        } catch (CpMalformedJsonException $exception) {
            return $exception;
        }

        if ($decodedObject === null) {
            return new CpMalformedJsonException('The Control Panel JSON error response is not an object.');
        }

        if (!property_exists($decodedObject, 'success')
            || $decodedObject->success !== false
            || !property_exists($decodedObject, 'error')
            || !is_string($decodedObject->error)
            || $decodedObject->error === ''
            || !property_exists($decodedObject, 'message')
            || !is_string($decodedObject->message)
            || !property_exists($decodedObject, 'data')
            || !($decodedObject->data instanceof \stdClass)
        ) {
            return new CpInvalidPayloadException('The Control Panel error response is not a canonical failure envelope.');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($decodedObject, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return new CpMalformedJsonException('The Control Panel JSON error response is not an object.');
        }

        $message = is_string($decodedObject->message) ? $decodedObject->message : 'Control Panel HTTP error.';

        // Canonical 401 is structured auth failure evidence for safe-route retry policy.
        if ($statusCode === 401) {
            return new CpAuthenticationException(
                $message !== '' ? $message : 'The Control Panel rejected the authentication.'
            );
        }

        return new CpHttpException(
            $statusCode,
            $decoded,
            $message,
            true,
            $decodedObject->error
        );
    }

    /** @return array<string, mixed> */
    private function decodeSuccessEnvelope(string $body): array
    {
        $decodedObject = $this->decodeJsonAsObject($body);
        if ($decodedObject === null) {
            throw new CpMalformedJsonException('The Control Panel JSON response is not an object.');
        }

        if (!property_exists($decodedObject, 'success')
            || $decodedObject->success !== true
            || !property_exists($decodedObject, 'error')
            || $decodedObject->error !== null
            || !property_exists($decodedObject, 'message')
            || !is_string($decodedObject->message)
            || !property_exists($decodedObject, 'data')
            || !($decodedObject->data instanceof \stdClass)
        ) {
            throw new CpInvalidPayloadException('The Control Panel response does not confirm success.');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($decodedObject, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new CpMalformedJsonException('The Control Panel JSON response is not an object.');
        }

        return $decoded;
    }

    private function decodeJsonAsObject(string $body): ?\stdClass
    {
        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CpMalformedJsonException('The Control Panel returned malformed JSON.', 0, $exception);
        }

        return $decoded instanceof \stdClass ? $decoded : null;
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
    private function storeTokenResponse(array $response, bool $requireShop): void
    {
        $data = $response['data'] ?? null;
        if (!is_array($data) || !$this->isAssociativeObject($data)) {
            $this->tokens->invalidate();
            throw new CpInvalidPayloadException('The Control Panel token response has no valid data object.');
        }

        // Tokens ONLY from response.data — reject legacy top-level token fields.
        if (isset($response['access_token']) || isset($response['token_type']) || isset($response['expires_in'])) {
            if (!isset($data['access_token'])) {
                $this->tokens->invalidate();
                throw new CpInvalidPayloadException('The Control Panel token response uses legacy top-level token fields.');
            }
        }

        $accessToken = $data['access_token'] ?? null;
        $tokenType = $data['token_type'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        if (
            !is_string($accessToken) || $accessToken === ''
            || !is_string($tokenType) || strcasecmp($tokenType, 'Bearer') !== 0
            || !is_numeric($expiresIn) || (int) $expiresIn <= 0
        ) {
            $this->tokens->invalidate();
            throw new CpInvalidPayloadException('The Control Panel token response is invalid.');
        }

        if ($requireShop) {
            $shop = $data['shop'] ?? null;
            if (!is_array($shop)) {
                $this->tokens->invalidate();
                throw new CpInvalidPayloadException('The Control Panel login response has no valid shop data.');
            }

            $responseUnicid = $shop['unicid'] ?? null;
            $configuredUnicid = $this->credentials->getUnicid($this->storeId);
            if (
                !is_string($responseUnicid)
                || $responseUnicid === ''
                || $configuredUnicid === ''
                || !hash_equals($configuredUnicid, $responseUnicid)
            ) {
                $this->tokens->invalidate();
                throw new CpInvalidPayloadException('The Control Panel login shop UNICID does not match configuration.');
            }
        }

        if (!$this->tokens->save($accessToken, $tokenType, $this->now() + (int) $expiresIn)) {
            $this->tokens->invalidate();
            throw new CpInvalidPayloadException('The Control Panel token could not be stored.');
        }
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $order
     */
    private function assertCreateOrderIdentity(array $response, array $order): void
    {
        $data = $response['data'] ?? null;
        if (!is_array($data) || !$this->isAssociativeObject($data)) {
            throw new CpInvalidPayloadException('The Control Panel create-order response has no valid data object.');
        }

        $id = $data['id'] ?? null;
        if (!is_int($id) || $id <= 0) {
            throw new CpInvalidPayloadException('The Control Panel create-order response has no order id.');
        }

        $sentOrderId = $order['order_id'] ?? null;
        if (!is_string($sentOrderId) || $sentOrderId === '') {
            throw new CpInvalidPayloadException('The Control Panel create-order request order_id is invalid.');
        }
        $echoOrderId = $data['order_id'] ?? null;
        if (!is_string($echoOrderId) || $echoOrderId !== $sentOrderId) {
            throw new CpInvalidPayloadException('The Control Panel create-order response order_id does not match the request.');
        }

        $configuredUnicid = $this->credentials->getUnicid($this->storeId);
        $echoUnicid = $data['unicid'] ?? null;
        if (!is_string($echoUnicid)
            || $echoUnicid === ''
            || $configuredUnicid === ''
            || !hash_equals($configuredUnicid, $echoUnicid)
        ) {
            throw new CpInvalidPayloadException('The Control Panel create-order response unicid does not match configuration.');
        }

        $shopId = $data['shop_id'] ?? null;
        if (!is_int($shopId) || $shopId <= 0) {
            throw new CpInvalidPayloadException('The Control Panel create-order response has no valid shop_id.');
        }

        $createdAt = $data['created_at'] ?? null;
        if (!is_string($createdAt) || trim($createdAt) === '') {
            throw new CpInvalidPayloadException('The Control Panel create-order response has no valid created_at.');
        }
    }

    /**
     * @param array<string, mixed> $response
     * @param array{order_id: string, status_id: string, status: string} $payload
     */
    private function assertPatchStatusEcho(array $response, array $payload): void
    {
        $data = $response['data'] ?? null;
        if (!is_array($data) || !$this->isAssociativeObject($data)) {
            throw new CpInvalidPayloadException('The Control Panel status response has no valid data object.');
        }

        $id = $data['id'] ?? null;
        if (!is_int($id) || $id <= 0) {
            throw new CpInvalidPayloadException('The Control Panel status response has no valid id.');
        }

        $shopId = $data['shop_id'] ?? null;
        if (!is_int($shopId) || $shopId <= 0) {
            throw new CpInvalidPayloadException('The Control Panel status response has no valid shop_id.');
        }

        $echoOrderId = $data['order_id'] ?? null;
        $echoStatusId = $data['status_id'] ?? null;
        $echoStatus = $data['status'] ?? null;
        if (!is_string($echoOrderId) || $echoOrderId !== $payload['order_id']
            || !is_string($echoStatusId) || $echoStatusId !== $payload['status_id']
            || !is_string($echoStatus) || $echoStatus !== $payload['status']
        ) {
            throw new CpInvalidPayloadException('The Control Panel status response does not echo the request identity.');
        }

        $updatedAt = $data['updated_at'] ?? null;
        if (!is_string($updatedAt) || trim($updatedAt) === '') {
            throw new CpInvalidPayloadException('The Control Panel status response has no valid updated_at.');
        }
    }

    /** @param array<mixed> $value */
    private function isAssociativeObject(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return !array_is_list($value);
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }
}
