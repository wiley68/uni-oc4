<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\CertificateLocalStore;
use Opencart\System\Library\Extension\MtUniCredit\CertificatePairValidator;
use Opencart\System\Library\Extension\MtUniCredit\MtlsPrivateKeyPassphraseProvider;
use PHPUnit\Framework\TestCase;

/**
 * Handled certificate-store chmod failures must not contaminate HTTP/JSON bodies.
 */
final class CertificateChmodJsonContaminationTest extends TestCase
{
    public function testHandledChmodFailureEmitsNoNativeWarningIntoOutput(): void
    {
        $store = new CertificateLocalStore(sys_get_temp_dir() . '/mt-uni-chmod-' . bin2hex(random_bytes(4)));
        $method = new \ReflectionMethod(CertificateLocalStore::class, 'tryChmod');
        $method->setAccessible(true);

        $logFile = tempnam(sys_get_temp_dir(), 'mt-uni-chmod-log-');
        self::assertNotFalse($logFile);
        $previousLog = ini_get('error_log');
        ini_set('error_log', $logFile);

        // Simulate OpenCart-style handler that would echo @-suppressed warnings unless swallowed.
        set_error_handler(static function (int $severity, string $message): bool {
            echo 'Warning: ' . $message;

            return false;
        });

        ob_start();
        try {
            $method->invoke($store, sys_get_temp_dir() . '/mt-uni-missing-' . bin2hex(random_bytes(4)), 02770);
            $output = (string) ob_get_clean();
        } finally {
            restore_error_handler();
            if (is_string($previousLog)) {
                ini_set('error_log', $previousLog);
            }
        }

        self::assertSame('', $output);
        self::assertStringNotContainsString('Warning', $output);
        self::assertStringNotContainsString('chmod', $output);

        $logged = (string) file_get_contents($logFile);
        @unlink($logFile);
        self::assertStringContainsString('[mt_uni_credit] certificate store chmod failed', $logged);
        self::assertStringContainsString('path_category=keys', $logged);
    }

    public function testEnsureProtectionFilesSurvivesChmodFailureWithoutOutputPollution(): void
    {
        $dir = sys_get_temp_dir() . '/mt-uni-keys-' . bin2hex(random_bytes(4));
        $store = new CertificateLocalStore($dir);

        set_error_handler(static function (int $severity, string $message): bool {
            echo 'Warning: ' . $message;

            return false;
        });
        ob_start();
        try {
            $store->ensureProtectionFiles();
            // Second call exercises tryChmod on an already-created directory.
            $store->ensureProtectionFiles();
            $output = (string) ob_get_clean();
        } finally {
            restore_error_handler();
            $this->removeTree($dir);
        }

        self::assertSame('', $output);
        self::assertStringNotContainsString('Warning:', $output);
    }

    public function testCheckoutRedirectJsonRemainsValidWhenHandledChmodFails(): void
    {
        $payload = [
            'success' => true,
            'step' => 'bank_redirect',
            'lifecycle_state' => 'completed',
            'bank_submitted' => true,
            'redirect_url' => 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/sess-ok',
            'redirect' => 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/sess-ok',
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        self::assertIsString($json);

        $store = new CertificateLocalStore(sys_get_temp_dir() . '/mt-uni-chmod-json-' . bin2hex(random_bytes(4)));
        $method = new \ReflectionMethod(CertificateLocalStore::class, 'tryChmod');
        $method->setAccessible(true);

        set_error_handler(static function (int $severity, string $message): bool {
            echo 'Warning: chmod(): Operation not permitted in certificate_local_store.php';

            return false;
        });
        ob_start();
        try {
            $method->invoke($store, sys_get_temp_dir() . '/mt-uni-missing-' . bin2hex(random_bytes(4)), 0640);
            echo $json;
            $body = (string) ob_get_clean();
        } finally {
            restore_error_handler();
        }

        self::assertSame($json, $body);
        self::assertSame('{', $body[0] ?? '');
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['success']);
        self::assertSame('bank_redirect', $decoded['step']);
        self::assertStringContainsString('sucf-online/Request/Start/', (string) $decoded['redirect_url']);
    }

    public function testSuccessfulProductRedirectJsonSurvivesMissingStagingFileCleanup(): void
    {
        $store = new CertificateLocalStore(sys_get_temp_dir() . '/mt-uni-cleanup-json-' . bin2hex(random_bytes(4)));
        $remove = new \ReflectionMethod(CertificateLocalStore::class, 'removeFileWithoutWarning');
        $remove->setAccessible(true);
        $missing = sys_get_temp_dir() . '/certificate-missing-' . bin2hex(random_bytes(4)) . '.pem';
        $payload = [
            'success' => true,
            'step' => 'bank_redirect',
            'redirect_url' => 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/session-ok',
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        set_error_handler(static function (int $severity, string $message): bool {
            echo 'Warning: ' . $message;

            return false;
        });
        ob_start();
        try {
            $remove->invoke($store, $missing);
            echo $json;
            $body = (string) ob_get_clean();
        } finally {
            restore_error_handler();
        }

        self::assertSame($json, $body);
        self::assertSame($payload, json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testSuccessfulAtomicReplacementDoesNotContaminateProductRedirectJson(): void
    {
        $directory = sys_get_temp_dir() . '/mt-uni-replace-json-' . bin2hex(random_bytes(4));
        $fixtures = __DIR__ . '/fixtures/certificates';
        $certificate = (string) file_get_contents($fixtures . '/matching_cert.pem');
        $privateKey = (string) file_get_contents($fixtures . '/matching_key.pem');
        $validator = new CertificatePairValidator(new MtlsPrivateKeyPassphraseProvider(
            null,
            static fn(): array => ['passphrase' => 'phase2-fixture-secret']
        ));
        $store = new CertificateLocalStore($directory, $validator);
        $payload = [
            'success' => true,
            'step' => 'bank_redirect',
            'redirect_url' => 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/session-ok',
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        set_error_handler(static function (int $severity, string $message): bool {
            echo 'Warning: ' . $message;

            return false;
        });
        ob_start();
        try {
            $store->replacePair($certificate, $privateKey, [
                'ssl_revision' => 'r1',
                'certificate_sha256' => hash('sha256', $certificate),
                'private_key_sha256' => hash('sha256', $privateKey),
            ]);
            echo $json;
            $body = (string) ob_get_clean();
        } finally {
            restore_error_handler();
            $this->removeTree($directory);
        }

        self::assertSame($json, $body);
        self::assertSame($payload, json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testHandledChmodFailureRemainsNonFatalForWritableStoreProbe(): void
    {
        $dir = sys_get_temp_dir() . '/mt-uni-writable-' . bin2hex(random_bytes(4));
        $store = new CertificateLocalStore($dir);
        try {
            $store->assertWritableStore();
            self::assertDirectoryExists($dir);
        } finally {
            $this->removeTree($dir);
        }
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
