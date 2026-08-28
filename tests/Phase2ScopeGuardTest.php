<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase2ScopeGuardTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_MARKERS = [
        'ControlPanelClient',
        'CertificateSynchronizer',
        'curl_init',
        'file_get_contents(\'http',
        'SmartUcfSession',
        'orderbankstatus',
        'shopcache',
        'Calculator\\',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PATHS = [
        '/catalog/controller',
        '/catalog/model',
        '/admin/controller/payment',
        '/admin/controller/event',
    ];

    public function testNoPhase3PlusPaths(): void
    {
        $root = dirname(__DIR__);
        foreach (self::FORBIDDEN_PATHS as $suffix) {
            self::assertDirectoryDoesNotExist($root . $suffix, $suffix);
        }
    }

    public function testProductionCodeHasNoCpTransportOrFinancingLifecycle(): void
    {
        $root = dirname(__DIR__);
        foreach ([$root . '/admin', $root . '/system', $root . '/config'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach (self::FORBIDDEN_MARKERS as $marker) {
                    self::assertStringNotContainsString(
                        $marker,
                        $contents,
                        $file->getPathname() . ' must not contain ' . $marker
                    );
                }
            }
        }
    }

    public function testNoAutomaticCertificateSyncEndpoints(): void
    {
        $root = dirname(__DIR__);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/system', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            self::assertStringNotContainsString('/ssl/certificate', $contents, $file->getPathname());
            self::assertStringNotContainsString('replacePair', $contents, $file->getPathname());
        }
    }

    public function testWebProtectionCoversDeploymentDirs(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__) . '/.htaccess');
        self::assertStringContainsString('config|keys|secrets', $htaccess);
        self::assertFileExists(dirname(__DIR__) . '/keys/.htaccess');
        self::assertFileExists(dirname(__DIR__) . '/secrets/.htaccess');
    }
}
