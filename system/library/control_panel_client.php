<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Control Panel HTTP client — login, refresh, logout, GET /shop (Phase 4).
 */
final class ControlPanelClient
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
