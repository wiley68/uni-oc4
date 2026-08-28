<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Resolves the SmartUCF mTLS private-key passphrase from module ZIP secrets only.
 *
 * Authoritative source: secrets/smartucf-key.php
 * Never reads environment variables, OpenCart settings, DB, or request input.
 */
final class MtlsPrivateKeyPassphraseProvider
{
    public const RELATIVE_PATH = 'secrets/smartucf-key.php';
    public const ARRAY_KEY = 'passphrase';

    private string $secretFilePath;

    /** @var callable|null Optional test double: (): mixed */
    private $loader;

    public function __construct(?string $secretFilePath = null, ?callable $loader = null)
    {
        $this->secretFilePath = $secretFilePath ?? (ExtensionRoot::path() . '/' . self::RELATIVE_PATH);
        $this->loader = $loader;
    }

    /**
     * @throws MtlsPrivateKeyPassphraseNotConfiguredException
     */
    public function require(): string
    {
        $value = $this->resolve();
        if ($value === null) {
            throw new MtlsPrivateKeyPassphraseNotConfiguredException();
        }

        return $value;
    }

    /**
     * Non-empty passphrase, or null when missing / invalid / blank.
     */
    public function resolve(): ?string
    {
        $loaded = $this->loadFile();
        if ($loaded === null) {
            return null;
        }
        if (!is_array($loaded)) {
            return null;
        }
        if (!array_key_exists(self::ARRAY_KEY, $loaded)) {
            return null;
        }
        $raw = $loaded[self::ARRAY_KEY];
        if (!is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    public function isConfigured(): bool
    {
        return $this->resolve() !== null;
    }

    /**
     * Structured availability without exposing the passphrase value.
     *
     * @return array{status: string, configured: bool}
     */
    public function health(): array
    {
        if (!is_file($this->secretFilePath)) {
            return ['status' => DeploymentHealthStatus::MISSING, 'configured' => false];
        }
        if (!is_readable($this->secretFilePath)) {
            return ['status' => DeploymentHealthStatus::UNREADABLE, 'configured' => false];
        }

        /** @var mixed $loaded */
        $loaded = $this->loadFile();
        if (!is_array($loaded)) {
            return ['status' => DeploymentHealthStatus::INVALID, 'configured' => false];
        }
        if (!array_key_exists(self::ARRAY_KEY, $loaded) || !is_string($loaded[self::ARRAY_KEY])) {
            return ['status' => DeploymentHealthStatus::INVALID, 'configured' => false];
        }
        if (trim($loaded[self::ARRAY_KEY]) === '') {
            return ['status' => DeploymentHealthStatus::INVALID, 'configured' => false];
        }

        return ['status' => DeploymentHealthStatus::HEALTHY, 'configured' => true];
    }

    public function secretFilePath(): string
    {
        return $this->secretFilePath;
    }

    /** @return mixed|null */
    private function loadFile()
    {
        if ($this->loader !== null) {
            return ($this->loader)();
        }

        if (!is_file($this->secretFilePath) || !is_readable($this->secretFilePath)) {
            return null;
        }

        return include $this->secretFilePath;
    }
}
