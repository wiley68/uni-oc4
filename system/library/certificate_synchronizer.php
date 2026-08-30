<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Synchronizes SmartUCF certificate material from the authenticated Control Panel.
 */
final class CertificateSynchronizer
{
    public function __construct(
        private ControlPanelClient $client,
        private ?CertificateLocalStore $store = null,
        private ?CertificatePairValidator $validator = null
    ) {
        $this->validator ??= new CertificatePairValidator();
        $this->store ??= new CertificateLocalStore(null, $this->validator);
    }

    public function ensureCurrent(): CertificateConsumerLease
    {
        $this->store->assertWritableStore();

        try {
            $metadata = $this->client->getSslCertificateMetadata();
        } catch (CpHttpException $exception) {
            if ($this->isExplicitUnavailable($exception)) {
                throw new CertificateSyncException(
                    'Control Panel reports no SSL certificate available.',
                    CertificateSyncException::REASON_CP_UNAVAILABLE,
                    $exception
                );
            }
            if ($exception->isTransient()) {
                return $this->failOpenOrThrow($exception);
            }
            throw new CertificateSyncException(
                'Control Panel SSL metadata request was rejected.',
                CertificateSyncException::REASON_CP_TRANSPORT,
                $exception
            );
        } catch (CpException $exception) {
            if ($exception->isTransient()) {
                return $this->failOpenOrThrow($exception);
            }
            throw new CertificateSyncException(
                'Control Panel SSL metadata response is not usable.',
                CertificateSyncException::REASON_CP_TRANSPORT,
                $exception
            );
        }

        if (empty($metadata['available'])) {
            throw new CertificateSyncException(
                'Control Panel SSL metadata is unavailable.',
                CertificateSyncException::REASON_CP_UNAVAILABLE
            );
        }

        if ($this->matchesMetadata($this->store->validateLocalPair(), $metadata)) {
            return $this->store->withSharedLock(
                fn(): CertificateConsumerLease => $this->store->createConsumerPairLease()
            );
        }

        return $this->store->withExclusiveLock(function () use ($metadata): CertificateConsumerLease {
            if ($this->matchesMetadata($this->store->validateLocalPair(), $metadata)) {
                return $this->store->createConsumerPairLease();
            }

            try {
                $bundle = $this->client->downloadSslCertificateBundle();
            } catch (CpException $exception) {
                throw new CertificateSyncException(
                    'SSL certificate bundle download failed.',
                    CertificateSyncException::REASON_REFRESH_FAILED,
                    $exception
                );
            }

            $this->assertBundleIntegrity($bundle, $metadata);
            try {
                $this->validator->validate(
                    (string) $bundle['certificate_pem'],
                    (string) $bundle['private_key_pem']
                );
            } catch (\Throwable $exception) {
                throw new CertificateSyncException(
                    'Downloaded SSL certificate bundle failed validation.',
                    CertificateSyncException::REASON_INVALID_BUNDLE,
                    $exception
                );
            }

            $this->store->replacePair(
                (string) $bundle['certificate_pem'],
                (string) $bundle['private_key_pem'],
                [
                    'ssl_revision' => (string) ($bundle['ssl_revision'] ?? $metadata['ssl_revision'] ?? ''),
                    'certificate_sha256' => (string) $bundle['certificate_sha256'],
                    'private_key_sha256' => (string) $bundle['private_key_sha256'],
                ]
            );

            return $this->store->createConsumerPairLease();
        });
    }

    private function failOpenOrThrow(\Throwable $exception): CertificateConsumerLease
    {
        if ($this->store->validateLocalPair() !== null) {
            return $this->store->withSharedLock(
                fn(): CertificateConsumerLease => $this->store->createConsumerPairLease()
            );
        }

        throw new CertificateSyncException(
            'Control Panel SSL metadata is unavailable and the local certificate pair is not usable.',
            CertificateSyncException::REASON_CP_TRANSPORT,
            $exception
        );
    }

    private function isExplicitUnavailable(CpHttpException $exception): bool
    {
        if ($exception->getStatusCode() !== 404) {
            return false;
        }
        $payload = $exception->getErrorPayload();

        return (($payload['error'] ?? '') === 'ssl_certificate_unavailable')
            || (isset($payload['data']['available']) && $payload['data']['available'] === false);
    }

    /**
     * @param array{certificate_sha256: string, private_key_sha256: string, not_after: string}|null $local
     * @param array<string, mixed> $metadata
     */
    private function matchesMetadata(?array $local, array $metadata): bool
    {
        return $local !== null
            && hash_equals((string) $metadata['certificate_sha256'], $local['certificate_sha256'])
            && hash_equals((string) $metadata['private_key_sha256'], $local['private_key_sha256']);
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $metadata
     */
    private function assertBundleIntegrity(array $bundle, array $metadata): void
    {
        foreach (['certificate_pem', 'private_key_pem', 'certificate_sha256', 'private_key_sha256'] as $field) {
            if (!isset($bundle[$field]) || !is_string($bundle[$field]) || $bundle[$field] === '') {
                throw new CertificateSyncException(
                    'SSL certificate bundle is missing required fields.',
                    CertificateSyncException::REASON_INVALID_BUNDLE
                );
            }
        }

        $certificateHash = strtolower((string) $bundle['certificate_sha256']);
        $privateKeyHash = strtolower((string) $bundle['private_key_sha256']);
        if (!$this->validator->isSha256Hex($certificateHash) || !$this->validator->isSha256Hex($privateKeyHash)) {
            throw new CertificateSyncException(
                'SSL certificate bundle hashes are malformed.',
                CertificateSyncException::REASON_INVALID_BUNDLE
            );
        }
        if (
            !hash_equals($certificateHash, $this->validator->sha256((string) $bundle['certificate_pem']))
            || !hash_equals($privateKeyHash, $this->validator->sha256((string) $bundle['private_key_pem']))
        ) {
            throw new CertificateSyncException(
                'Downloaded SSL PEM digests do not match their declared hashes.',
                CertificateSyncException::REASON_INVALID_BUNDLE
            );
        }

        if (
            !hash_equals((string) $metadata['certificate_sha256'], $certificateHash)
            || !hash_equals((string) $metadata['private_key_sha256'], $privateKeyHash)
        ) {
            throw new CertificateSyncException(
                'Downloaded SSL bundle does not match the metadata that triggered refresh.',
                CertificateSyncException::REASON_INVALID_BUNDLE
            );
        }
    }
}
