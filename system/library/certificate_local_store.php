<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Filesystem store for the authoritative SmartUCF certificate pair.
 */
final class CertificateLocalStore
{
    public const LOCK_FILENAME = '.sync.lock';
    public const STATE_FILENAME = '.ssl_state.json';

    private const LOCK_TIMEOUT_SECONDS = 15;

    private CertificateLocalPaths $paths;

    private CertificatePairValidator $validator;

    public function __construct(
        ?string $keysDir = null,
        ?CertificatePairValidator $validator = null
    ) {
        $this->paths = new CertificateLocalPaths($keysDir);
        $this->validator = $validator ?? new CertificatePairValidator();
    }

    public function keysDirectory(): string
    {
        return $this->paths->keysDirectory();
    }

    public function certificatePath(): string
    {
        return $this->paths->certificatePath();
    }

    public function privateKeyPath(): string
    {
        return $this->paths->privateKeyPath();
    }

    public function ensureProtectionFiles(): void
    {
        $directory = $this->keysDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new CertificateSyncException(
                'The certificate keys directory could not be created.',
                CertificateSyncException::REASON_LOCAL_FS
            );
        }
        @chmod($directory, 0750);

        $htaccess = $directory . '/.htaccess';
        if (!is_file($htaccess)) {
            $written = @file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n"
            );
            if ($written === false) {
                throw new CertificateSyncException(
                    'The certificate directory protection file could not be created.',
                    CertificateSyncException::REASON_LOCAL_FS
                );
            }
        }

        $index = $directory . '/index.php';
        if (!is_file($index) && @file_put_contents($index, "<?php\nheader('HTTP/1.0 403 Forbidden');\nexit;\n") === false) {
            throw new CertificateSyncException(
                'The certificate directory index protection could not be created.',
                CertificateSyncException::REASON_LOCAL_FS
            );
        }
    }

    /**
     * @return array{certificate_pem: string, private_key_pem: string}|null
     */
    public function readPairBytes(): ?array
    {
        return $this->paths->readPairBytes();
    }

    /**
     * @return array{certificate_sha256: string, private_key_sha256: string, not_after: string}|null
     */
    public function validateLocalPair(): ?array
    {
        $pair = $this->readPairBytes();
        if ($pair === null) {
            return null;
        }

        try {
            $validated = $this->validator->validate($pair['certificate_pem'], $pair['private_key_pem']);
        } catch (\Throwable $exception) {
            return null;
        }

        return [
            'certificate_sha256' => $this->validator->sha256($pair['certificate_pem']),
            'private_key_sha256' => $this->validator->sha256($pair['private_key_pem']),
            'not_after' => $validated['not_after'],
        ];
    }

    /**
     * @param array{ssl_revision?: string, certificate_sha256: string, private_key_sha256: string} $metadata
     */
    public function replacePair(string $certificatePem, string $privateKeyPem, array $metadata): void
    {
        $this->ensureProtectionFiles();

        try {
            $validated = $this->validator->validate($certificatePem, $privateKeyPem);
        } catch (\Throwable $exception) {
            throw new CertificateSyncException(
                'The certificate pair could not be validated.',
                CertificateSyncException::REASON_INVALID_BUNDLE,
                $exception
            );
        }

        $incoming = $this->keysDirectory() . '/.incoming';
        if (!is_dir($incoming) && !@mkdir($incoming, 0750, true) && !is_dir($incoming)) {
            throw new CertificateSyncException(
                'The certificate staging directory could not be created.',
                CertificateSyncException::REASON_LOCAL_FS
            );
        }

        $suffix = bin2hex(random_bytes(8));
        $stageCert = $incoming . '/certificate-' . $suffix . '.pem';
        $stageKey = $incoming . '/private-key-' . $suffix . '.pem';
        $backupCert = $incoming . '/certificate-backup-' . $suffix . '.pem';
        $backupKey = $incoming . '/private-key-backup-' . $suffix . '.pem';
        $hadCert = is_file($this->certificatePath());
        $hadKey = is_file($this->privateKeyPath());

        try {
            if ($hadCert && !@copy($this->certificatePath(), $backupCert)) {
                throw new CertificateSyncException('The existing certificate could not be backed up.', CertificateSyncException::REASON_LOCAL_FS);
            }
            if ($hadKey && !@copy($this->privateKeyPath(), $backupKey)) {
                throw new CertificateSyncException('The existing private key could not be backed up.', CertificateSyncException::REASON_LOCAL_FS);
            }
            if (
                @file_put_contents($stageCert, $validated['certificate_pem'], LOCK_EX) === false
                || @file_put_contents($stageKey, $validated['private_key_pem'], LOCK_EX) === false
            ) {
                throw new CertificateSyncException('The staged certificate pair could not be written.', CertificateSyncException::REASON_LOCAL_FS);
            }
            @chmod($stageCert, 0640);
            @chmod($stageKey, 0600);

            if (!@rename($stageCert, $this->certificatePath()) || !@rename($stageKey, $this->privateKeyPath())) {
                throw new CertificateSyncException('The staged certificate pair could not be promoted.', CertificateSyncException::REASON_LOCAL_FS);
            }
            @chmod($this->certificatePath(), 0640);
            @chmod($this->privateKeyPath(), 0600);

            $pair = $this->readPairBytes();
            if (
                $pair === null
                || $pair['certificate_pem'] !== $validated['certificate_pem']
                || $pair['private_key_pem'] !== $validated['private_key_pem']
            ) {
                throw new CertificateSyncException('The promoted certificate pair is inconsistent.', CertificateSyncException::REASON_LOCAL_FS);
            }

            $this->writeState([
                'ssl_revision' => (string) ($metadata['ssl_revision'] ?? ''),
                'certificate_sha256' => (string) $metadata['certificate_sha256'],
                'private_key_sha256' => (string) $metadata['private_key_sha256'],
                'synced_at' => gmdate('c'),
            ]);
        } catch (\Throwable $exception) {
            $this->restoreFile($backupCert, $this->certificatePath(), $hadCert);
            $this->restoreFile($backupKey, $this->privateKeyPath(), $hadKey);
            if ($exception instanceof CertificateSyncException) {
                throw $exception;
            }
            throw new CertificateSyncException(
                'Certificate pair replacement failed.',
                CertificateSyncException::REASON_LOCAL_FS,
                $exception
            );
        } finally {
            foreach ([$stageCert, $stageKey, $backupCert, $backupKey] as $path) {
                @unlink($path);
            }
            @rmdir($incoming);
        }
    }

    public function createConsumerPairLease(): CertificateConsumerLease
    {
        $pair = $this->readPairBytes();
        if ($pair === null) {
            throw new CertificateSyncException(
                'The local certificate pair is missing or unreadable.',
                CertificateSyncException::REASON_LOCAL_FS
            );
        }

        try {
            $this->validator->validate($pair['certificate_pem'], $pair['private_key_pem']);
            $password = $this->validator->privateKeyPassphrase();
        } catch (\Throwable $exception) {
            throw new CertificateSyncException(
                'The local certificate pair is not usable.',
                CertificateSyncException::REASON_LOCAL_FS,
                $exception
            );
        }

        @chmod($this->certificatePath(), 0640);
        @chmod($this->privateKeyPath(), 0600);
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'mt-uni-credit-ssl-' . bin2hex(random_bytes(8));
        if (!@mkdir($directory, 0700) && !is_dir($directory)) {
            throw new CertificateSyncException(
                'The certificate lease directory could not be created.',
                CertificateSyncException::REASON_LOCAL_FS
            );
        }

        $certificatePath = $directory . '/certificate.pem';
        $privateKeyPath = $directory . '/private_key.pem';
        try {
            if (
                @file_put_contents($certificatePath, $pair['certificate_pem'], LOCK_EX) === false
                || @file_put_contents($privateKeyPath, $pair['private_key_pem'], LOCK_EX) === false
            ) {
                throw new CertificateSyncException(
                    'The certificate lease files could not be written.',
                    CertificateSyncException::REASON_LOCAL_FS
                );
            }
            @chmod($certificatePath, 0600);
            @chmod($privateKeyPath, 0600);
        } catch (\Throwable $exception) {
            @unlink($certificatePath);
            @unlink($privateKeyPath);
            @rmdir($directory);
            if ($exception instanceof CertificateSyncException) {
                throw $exception;
            }
            throw new CertificateSyncException('Certificate lease creation failed.', CertificateSyncException::REASON_LOCAL_FS, $exception);
        }

        return new CertificateConsumerLease($directory, $certificatePath, $privateKeyPath, $password);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withExclusiveLock(callable $callback)
    {
        return $this->withLock(LOCK_EX, $callback);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withSharedLock(callable $callback)
    {
        return $this->withLock(LOCK_SH, $callback);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(int $mode, callable $callback)
    {
        $this->ensureProtectionFiles();
        $handle = @fopen($this->keysDirectory() . '/' . self::LOCK_FILENAME, 'c+');
        if ($handle === false) {
            throw new CertificateSyncException('The certificate sync lock could not be opened.', CertificateSyncException::REASON_LOCAL_FS);
        }

        $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;
        $locked = false;
        while (microtime(true) < $deadline) {
            if (flock($handle, $mode | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(50000);
        }
        if (!$locked) {
            fclose($handle);
            throw new CertificateSyncException('The certificate sync lock timed out.', CertificateSyncException::REASON_LOCAL_FS);
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string, string> $state */
    private function writeState(array $state): void
    {
        $path = $this->keysDirectory() . '/' . self::STATE_FILENAME;
        try {
            $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CertificateSyncException('The certificate sync state could not be encoded.', CertificateSyncException::REASON_LOCAL_FS, $exception);
        }
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw new CertificateSyncException('The certificate sync state could not be written.', CertificateSyncException::REASON_LOCAL_FS);
        }
        @chmod($path, 0640);
    }

    private function restoreFile(string $backup, string $destination, bool $existed): void
    {
        if ($existed && is_file($backup)) {
            @copy($backup, $destination);
            return;
        }
        if (!$existed) {
            @unlink($destination);
        }
    }
}
