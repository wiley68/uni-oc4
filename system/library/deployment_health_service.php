<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Phase 2 local deployment health — no network calls.
 *
 * Returns structured statuses safe for admin display (never secret values or PEM).
 */
final class DeploymentHealthService
{
    private ModuleDeploymentEnvironment $environment;
    private MtlsPrivateKeyPassphraseProvider $secrets;
    private CertificateLocalPaths $paths;
    private CertificatePairValidator $validator;

    public function __construct(
        ?ModuleDeploymentEnvironment $environment = null,
        ?MtlsPrivateKeyPassphraseProvider $secrets = null,
        ?CertificateLocalPaths $paths = null,
        ?CertificatePairValidator $validator = null
    ) {
        $this->environment = $environment ?? new ModuleDeploymentEnvironment();
        $this->secrets = $secrets ?? new MtlsPrivateKeyPassphraseProvider();
        $this->paths = $paths ?? new CertificateLocalPaths();
        $this->validator = $validator ?? new CertificatePairValidator($this->secrets);
    }

    /**
     * @return array{
     *     environment: array{status: string},
     *     control_panel: array{status: string, configured: bool, host: ?string},
     *     secrets: array{status: string, configured: bool},
     *     certificate: array{status: string, relative_path: string},
     *     private_key: array{status: string, relative_path: string},
     *     certificate_validity: array{status: string, not_before: ?string, not_after: ?string},
     *     certificate_key_match: array{status: string},
     *     deployment_ready: bool
     * }
     */
    public function evaluate(): array
    {
        $environment = $this->evaluateEnvironment();
        $controlPanel = $this->evaluateControlPanel();
        $secrets = $this->secrets->health();
        $certificate = $this->evaluateFile($this->paths->certificatePath(), $this->paths->certificateRelativePath());
        $privateKey = $this->evaluateFile($this->paths->privateKeyPath(), $this->paths->privateKeyRelativePath());
        $validity = [
            'status'     => DeploymentHealthStatus::UNKNOWN,
            'not_before' => null,
            'not_after'  => null,
        ];
        $match = ['status' => DeploymentHealthStatus::UNKNOWN];

        if ($certificate['status'] === DeploymentHealthStatus::HEALTHY) {
            $certPem = @file_get_contents($this->paths->certificatePath());
            if (is_string($certPem) && $certPem !== '') {
                try {
                    $dates = $this->validator->parseCertificateDates($certPem);
                    $validity['not_before'] = $dates['not_before'];
                    $validity['not_after'] = $dates['not_after'];
                    $now = time();
                    if ($dates['not_before_timestamp'] > $now) {
                        $validity['status'] = DeploymentHealthStatus::NOT_YET_VALID;
                    } elseif ($dates['not_after_timestamp'] < $now) {
                        $validity['status'] = DeploymentHealthStatus::EXPIRED;
                    } else {
                        $validity['status'] = DeploymentHealthStatus::HEALTHY;
                    }
                } catch (\Throwable $exception) {
                    $certificate['status'] = DeploymentHealthStatus::INVALID;
                    $validity['status'] = DeploymentHealthStatus::INVALID;
                }
            }
        }

        if (
            $certificate['status'] === DeploymentHealthStatus::HEALTHY
            && $privateKey['status'] === DeploymentHealthStatus::HEALTHY
            && $secrets['status'] === DeploymentHealthStatus::HEALTHY
        ) {
            $pair = $this->paths->readPairBytes();
            if ($pair === null) {
                $match['status'] = DeploymentHealthStatus::UNREADABLE;
            } else {
                try {
                    $this->validator->validate($pair['certificate_pem'], $pair['private_key_pem']);
                    $match['status'] = DeploymentHealthStatus::HEALTHY;
                    if ($validity['status'] === DeploymentHealthStatus::UNKNOWN) {
                        $validity['status'] = DeploymentHealthStatus::HEALTHY;
                    }
                } catch (MtlsPrivateKeyPassphraseNotConfiguredException $exception) {
                    $match['status'] = DeploymentHealthStatus::MISSING;
                } catch (\InvalidArgumentException $exception) {
                    $message = $exception->getMessage();
                    if (str_contains($message, 'does not match')) {
                        $match['status'] = DeploymentHealthStatus::MISMATCH;
                    } elseif (str_contains($message, 'expired')) {
                        $validity['status'] = DeploymentHealthStatus::EXPIRED;
                        $match['status'] = DeploymentHealthStatus::INVALID;
                    } elseif (str_contains($message, 'not yet valid')) {
                        $validity['status'] = DeploymentHealthStatus::NOT_YET_VALID;
                        $match['status'] = DeploymentHealthStatus::INVALID;
                    } elseif (str_contains($message, 'private key could not be parsed')) {
                        $privateKey['status'] = DeploymentHealthStatus::INVALID;
                        $match['status'] = DeploymentHealthStatus::INVALID;
                    } elseif (str_contains($message, 'certificate')) {
                        $certificate['status'] = DeploymentHealthStatus::INVALID;
                        $match['status'] = DeploymentHealthStatus::INVALID;
                    } else {
                        $match['status'] = DeploymentHealthStatus::INVALID;
                    }
                } catch (\Throwable $exception) {
                    $match['status'] = DeploymentHealthStatus::INVALID;
                }
            }
        }

        $deploymentReady = $environment['status'] === DeploymentHealthStatus::HEALTHY
            && $controlPanel['status'] === DeploymentHealthStatus::HEALTHY
            && $secrets['status'] === DeploymentHealthStatus::HEALTHY
            && $certificate['status'] === DeploymentHealthStatus::HEALTHY
            && $privateKey['status'] === DeploymentHealthStatus::HEALTHY
            && $validity['status'] === DeploymentHealthStatus::HEALTHY
            && $match['status'] === DeploymentHealthStatus::HEALTHY;

        return [
            'environment'           => $environment,
            'control_panel'         => $controlPanel,
            'secrets'               => $secrets,
            'certificate'           => $certificate,
            'private_key'           => $privateKey,
            'certificate_validity'  => $validity,
            'certificate_key_match' => $match,
            'deployment_ready'      => $deploymentReady,
        ];
    }

    /**
     * Future storefront gate — never throws.
     */
    public function isDeploymentReady(): bool
    {
        try {
            return $this->evaluate()['deployment_ready'];
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /** @return array{status: string} */
    private function evaluateEnvironment(): array
    {
        if (!$this->environment->isReadable()) {
            return ['status' => DeploymentHealthStatus::MISSING];
        }

        try {
            $this->environment->controlPanelUrl();

            return ['status' => DeploymentHealthStatus::HEALTHY];
        } catch (\Throwable $exception) {
            return ['status' => DeploymentHealthStatus::INVALID];
        }
    }

    /** @return array{status: string, configured: bool, host: ?string} */
    private function evaluateControlPanel(): array
    {
        try {
            $host = $this->environment->controlPanelHost();

            return [
                'status'     => $host !== null ? DeploymentHealthStatus::HEALTHY : DeploymentHealthStatus::INVALID,
                'configured' => $host !== null,
                'host'       => $host,
            ];
        } catch (\Throwable $exception) {
            return [
                'status'     => DeploymentHealthStatus::INVALID,
                'configured' => false,
                'host'       => null,
            ];
        }
    }

    /**
     * @return array{status: string, relative_path: string}
     */
    private function evaluateFile(string $absolutePath, string $relativePath): array
    {
        if (!is_file($absolutePath)) {
            return ['status' => DeploymentHealthStatus::MISSING, 'relative_path' => $relativePath];
        }
        if (!is_readable($absolutePath)) {
            return ['status' => DeploymentHealthStatus::UNREADABLE, 'relative_path' => $relativePath];
        }
        $contents = @file_get_contents($absolutePath);
        if (!is_string($contents) || trim($contents) === '') {
            return ['status' => DeploymentHealthStatus::INVALID, 'relative_path' => $relativePath];
        }

        return ['status' => DeploymentHealthStatus::HEALTHY, 'relative_path' => $relativePath];
    }
}
