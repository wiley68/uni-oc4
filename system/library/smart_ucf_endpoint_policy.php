<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class SmartUcfEndpointPolicy
{
    public const HOST_PRODUCTION = 'online.ucfin.bg';
    public const HOST_TEST = 'onlinetest.ucfin.bg';
    public const SERVICE_PATH = '/suos/api/otp';
    public const APPLICATION_PATH = '/sucf-online/Request/Start';
    public const SESSION_START_SUFFIX = 'sucfOnlineSessionStart';

    private const TRUSTED_HOSTS = [self::HOST_PRODUCTION, self::HOST_TEST];

    public function assertTrustedServiceBase(string $url): string
    {
        $parts = $this->parseStrictHttpsUrl($url, 'SmartUCF service');
        $this->assertTrustedHostAndPort($parts, 'SmartUCF service');
        if ($this->normalizedAbsolutePath((string) ($parts['path'] ?? '')) !== self::SERVICE_PATH) {
            throw new \InvalidArgumentException('The SmartUCF service path is not trusted.');
        }

        return 'https://' . strtolower((string) $parts['host']) . self::SERVICE_PATH . '/';
    }

    public function buildSessionStartUrl(string $serviceBaseUrl): string
    {
        return $this->assertTrustedServiceBase($serviceBaseUrl) . self::SESSION_START_SUFFIX;
    }

    public function assertTrustedApplicationBase(string $url): string
    {
        $parts = $this->parseStrictHttpsUrl($url, 'SmartUCF application');
        $this->assertTrustedHostAndPort($parts, 'SmartUCF application');
        if ($this->normalizedAbsolutePath((string) ($parts['path'] ?? '')) !== self::APPLICATION_PATH) {
            throw new \InvalidArgumentException('The SmartUCF application path is not trusted.');
        }

        return 'https://' . strtolower((string) $parts['host']) . self::APPLICATION_PATH;
    }

    public function buildApplicationRedirect(string $applicationBaseUrl, string $sessionId): string
    {
        return $this->assertTrustedApplicationBase($applicationBaseUrl) . '/' . $this->assertSafeSessionId($sessionId);
    }

    public function isTrustedApplicationRedirect(string $redirectUrl): bool
    {
        try {
            $this->assertTrustedApplicationRedirect($redirectUrl);

            return true;
        } catch (\InvalidArgumentException $exception) {
            return false;
        }
    }

    public function assertTrustedApplicationRedirect(string $redirectUrl): string
    {
        $parts = $this->parseStrictHttpsUrl($redirectUrl, 'SmartUCF redirect');
        $this->assertTrustedHostAndPort($parts, 'SmartUCF redirect');
        $path = (string) ($parts['path'] ?? '');
        $prefix = self::APPLICATION_PATH . '/';
        if (!str_starts_with($path, $prefix)) {
            throw new \InvalidArgumentException('The SmartUCF redirect path is not trusted.');
        }
        $sessionId = $this->assertSafeSessionId(substr($path, strlen($prefix)));

        return 'https://' . strtolower((string) $parts['host']) . $prefix . $sessionId;
    }

    public function describeUrlForLog(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '[unparseable]';
        }
        $authority = strtolower((string) ($parts['host'] ?? ''));
        if (isset($parts['port'])) {
            $authority .= ':' . (int) $parts['port'];
        }

        return strtolower((string) ($parts['scheme'] ?? '')) . '://' . $authority . (string) ($parts['path'] ?? '');
    }

    /** @return array<string, mixed> */
    private function parseStrictHttpsUrl(string $url, string $label): array
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($url === '' || !is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('The ' . $label . ' URL is malformed.');
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw new \InvalidArgumentException('The ' . $label . ' URL must use HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) {
            throw new \InvalidArgumentException('The ' . $label . ' URL contains forbidden components.');
        }

        return $parts;
    }

    /** @param array<string, mixed> $parts */
    private function assertTrustedHostAndPort(array $parts, string $label): void
    {
        if (!in_array(strtolower((string) $parts['host']), self::TRUSTED_HOSTS, true)) {
            throw new \InvalidArgumentException('The ' . $label . ' hostname is not trusted.');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new \InvalidArgumentException('The ' . $label . ' URL must use the default HTTPS port.');
        }
    }

    private function normalizedAbsolutePath(string $path): string
    {
        return rtrim('/' . ltrim($path, '/'), '/');
    }

    private function assertSafeSessionId(string $sessionId): string
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || strlen($sessionId) > 128 || !preg_match('/^[A-Za-z0-9._~-]+$/', $sessionId)) {
            throw new \InvalidArgumentException('The SmartUCF session identifier is invalid.');
        }

        return $sessionId;
    }
}
