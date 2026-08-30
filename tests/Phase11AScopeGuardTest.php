<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase11AScopeGuardTest extends TestCase
{
    public function testProcess1ProductionNeverWritesProcess2Status(): void
    {
        $root = dirname(__DIR__);
        foreach ([$root . '/system/library', $root . '/catalog'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'js'], true)) {
                    continue;
                }
                if (str_ends_with($file->getPathname(), '/system/library/bank_status.php')) {
                    continue;
                }
                if (str_ends_with($file->getPathname(), '/system/library/inbound_bank_status_vocabulary.php')) {
                    continue;
                }
                self::assertStringNotContainsString(
                    'bank_sent_process2',
                    (string) file_get_contents($file->getPathname()),
                    $file->getPathname()
                );
            }
        }
    }

    public function testSmartUcfClientKeepsTlsVerificationAndNoCertificateSync(): void
    {
        $client = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/smart_ucf_session_client.php'
        );
        self::assertStringContainsString('CURLOPT_SSL_VERIFYPEER => true', $client);
        self::assertStringContainsString('CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS', $client);
        self::assertStringNotContainsString('CertificateSynchronizer', $client);
    }
}
