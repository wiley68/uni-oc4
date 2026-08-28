<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Reads module-local ZIP deployment endpoints from config/environment.php.
 *
 * Single authoritative Control Panel host source — do not duplicate elsewhere.
 */
final class ModuleDeploymentEnvironment
{
    public const RELATIVE_PATH = 'config/environment.php';
    public const CONTROL_PANEL_URL_KEY = 'control_panel_url';
    public const API_PATH_PREFIX = '/api/v1';

    private string $configFilePath;

    public function __construct(?string $configFilePath = null)
    {
        $this->configFilePath = $configFilePath ?? (ExtensionRoot::path() . '/' . self::RELATIVE_PATH);
    }

    /**
     * Authoritative Control Panel host base (no API suffix), e.g. https://cp.example.com
     *
     * @throws \RuntimeException
     */
    public function controlPanelUrl(): string
    {
        $loaded = $this->load();
        $url = $loaded[self::CONTROL_PANEL_URL_KEY] ?? null;
        if (!is_string($url)) {
            throw new \RuntimeException('Control Panel URL is not configured in config/environment.php.');
        }
        $url = rtrim(trim($url), '/');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Control Panel URL is invalid in config/environment.php.');
        }

        return $url;
    }

    /**
     * Outbound CP HTTP API base (host + /api/v1). Phase 2 does not call the network.
     *
     * @throws \RuntimeException
     */
    public function controlPanelApiBaseUrl(): string
    {
        return $this->controlPanelUrl() . self::API_PATH_PREFIX;
    }

    /**
     * Safe host for admin display (no credentials, no path).
     */
    public function controlPanelHost(): ?string
    {
        try {
            $parts = parse_url($this->controlPanelUrl());
        } catch (\Throwable $exception) {
            return null;
        }

        if (!is_array($parts) || !isset($parts['host']) || !is_string($parts['host']) || $parts['host'] === '') {
            return null;
        }

        return $parts['host'];
    }

    public function configFilePath(): string
    {
        return $this->configFilePath;
    }

    public function isReadable(): bool
    {
        return is_file($this->configFilePath) && is_readable($this->configFilePath);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function load(): array
    {
        if (!is_file($this->configFilePath) || !is_readable($this->configFilePath)) {
            throw new \RuntimeException('Deployment environment file config/environment.php is missing or unreadable.');
        }

        /** @var mixed $loaded */
        $loaded = include $this->configFilePath;
        if (!is_array($loaded)) {
            throw new \RuntimeException('Deployment environment file config/environment.php must return an array.');
        }

        return $loaded;
    }
}
