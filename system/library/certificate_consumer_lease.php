<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Immutable PEM snapshot used by one SmartUCF request.
 */
final class CertificateConsumerLease
{
    private bool $released = false;

    public function __construct(
        private string $directory,
        private string $certificatePath,
        private string $privateKeyPath,
        private string $password
    ) {
    }

    public function certificatePath(): string
    {
        return $this->certificatePath;
    }

    public function privateKeyPath(): string
    {
        return $this->privateKeyPath;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;

        foreach ([$this->certificatePath, $this->privateKeyPath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir($this->directory)) {
            @rmdir($this->directory);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
