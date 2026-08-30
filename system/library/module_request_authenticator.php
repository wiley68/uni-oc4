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
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function authenticate(array $payload, string $rawBody, array $headers): string
    {
        if (!$this->moduleEnabled) {
            throw new ModuleApiException('Модулът е изключен.', 403);
        }

        $storedUnicid = $this->credentials->getUnicid($this->storeId);
        $storedSecret = $this->credentials->getSecret($this->storeId);
        if ($storedUnicid === '' || $storedSecret === null) {
            throw new ModuleApiException('Модулът не е конфигуриран.', 401);
        }

        $unicid = $payload['unicid'] ?? null;
        if (!is_string($unicid) || $unicid === '') {
            throw $this->authFailure();
        }

        if (!hash_equals($storedUnicid, $unicid)) {
            throw $this->authFailure();
        }

        $this->verifier->verify($storedSecret, $rawBody, $headers);

        $nonce = $this->verifier->extractNonce($headers);
        if (!$this->nonces->claim($this->storeId, $unicid, $nonce)) {
            throw $this->authFailure();
        }

        return $unicid;
    }

    private function authFailure(): ModuleApiException
    {
        return new ModuleApiException(ModuleRequestSignatureProtocol::AUTH_FAILURE_MESSAGE, 401);
    }
}
