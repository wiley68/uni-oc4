<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Deterministic filesystem paths for manually deployed certificate material.
 *
 * OpenCart 2.0.2 does not synchronize certificates from the Control Panel.
 */
final class CertificateLocalPaths
{
    public const CERT_FILENAME = 'avalon_cert.pem';
    public const KEY_FILENAME = 'avalon_private_key.pem';
    public const RELATIVE_KEYS_DIR = 'keys';

    private string $keysDir;

    public function __construct(?string $keysDir = null)
    {
        $this->keysDir = $keysDir ?? (ExtensionRoot::path() . '/' . self::RELATIVE_KEYS_DIR);
    }

    public function keysDirectory(): string
    {
        return $this->keysDir;
    }

    public function certificatePath(): string
    {
        return $this->keysDir . '/' . self::CERT_FILENAME;
    }

    public function privateKeyPath(): string
    {
        return $this->keysDir . '/' . self::KEY_FILENAME;
    }

    public function certificateRelativePath(): string
    {
        return self::RELATIVE_KEYS_DIR . '/' . self::CERT_FILENAME;
    }

    public function privateKeyRelativePath(): string
    {
        return self::RELATIVE_KEYS_DIR . '/' . self::KEY_FILENAME;
    }

    /**
     * @return array{certificate_pem: string, private_key_pem: string}|null
     */
    public function readPairBytes(): ?array
    {
        $certPath = $this->certificatePath();
        $keyPath = $this->privateKeyPath();
        if (!is_file($certPath) || !is_file($keyPath) || !is_readable($certPath) || !is_readable($keyPath)) {
            return null;
        }
        $cert = file_get_contents($certPath);
        $key = file_get_contents($keyPath);
        if (!is_string($cert) || !is_string($key) || $cert === '' || $key === '') {
            return null;
        }

        return [
            'certificate_pem' => $cert,
            'private_key_pem' => $key,
        ];
    }
}
