<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Authenticates CP → module inbound requests (HMAC + nonce claim).
 *
 * Invalid signature must not consume the nonce (uni-ps9 parity).
 */
final class ModuleRequestAuthenticator
{
    private ModuleCredentialsRepository $credentials;

    private ApiNonceRepository $nonces;

    private ModuleRequestSignatureVerifier $verifier;

    private int $storeId;

    private bool $moduleEnabled;

    public function __construct(
        ModuleCredentialsRepository $credentials,
        ApiNonceRepository $nonces,
        int $storeId,
        bool $moduleEnabled,
        ?ModuleRequestSignatureVerifier $verifier = null
    ) {
        $this->credentials = $credentials;
        $this->nonces = $nonces;
        $this->storeId = $storeId;
        $this->moduleEnabled = $moduleEnabled;
        $this->verifier = $verifier ?? new ModuleRequestSignatureVerifier();
    }

    /**
     * Authenticate a signed inbound request.
     *
     * Order: enabled/credentials → HMAC on raw body → JSON object decode → UNICID → nonce claim.
     *
     * @param array<string, string> $headers
     * @return array{0: array<string, mixed>, 1: string} decoded payload and authenticated unicid
     */
    public function authenticate(string $rawBody, array $headers): array
    {
        if (!$this->moduleEnabled) {
            throw new ModuleApiException('Модулът е изключен.', 403);
        }

        $storedUnicid = $this->credentials->getUnicid($this->storeId);
        $storedSecret = $this->credentials->getSecret($this->storeId);
        if ($storedUnicid === '' || $storedSecret === null) {
            throw new ModuleApiException('Модулът не е конфигуриран.', 401);
        }

        // HMAC over exact raw body before JSON decode / UNICID binding.
        $this->verifier->verify($storedSecret, $rawBody, $headers);

        $payload = $this->decodeJsonObject($rawBody);

        $unicid = $payload['unicid'] ?? null;
        if (!is_string($unicid) || $unicid === '') {
            throw $this->authFailure();
        }

        if (!hash_equals($storedUnicid, $unicid)) {
            throw $this->authFailure();
        }

        $nonce = $this->verifier->extractNonce($headers);
        try {
            if (!$this->nonces->claim($this->storeId, $unicid, $nonce)) {
                throw $this->authFailure();
            }
        } catch (PersistenceException $exception) {
            throw new ModuleApiException(
                'Хранилището за replay защита временно е недостъпно.',
                500,
                'replay_store_failed',
                null,
                $exception
            );
        }

        return [$payload, $unicid];
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject(string $rawBody): array
    {
        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ModuleApiException('JSON тялото на заявката е невалидно.', 400);
        }

        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new ModuleApiException('JSON тялото на заявката трябва да бъде обект.', 400);
        }

        return $payload;
    }

    private function authFailure(): ModuleApiException
    {
        return new ModuleApiException(ModuleRequestSignatureProtocol::AUTH_FAILURE_MESSAGE, 401);
    }
}
