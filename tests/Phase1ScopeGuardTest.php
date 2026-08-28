<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase1ScopeGuardTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_PRODUCTION_MARKERS = [
        'SmartUcfSession',
        'hash_hmac',
        'orderbankstatus',
        'shopcache',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PATH_SUFFIXES = [
        '/catalog/controller/payment',
        '/catalog/controller/event',
        '/catalog/model/payment',
        '/admin/controller/payment',
    ];

    public function testPhase2PlusPathsAreAbsent(): void
    {
        $root = dirname(__DIR__);
        foreach (self::FORBIDDEN_PATH_SUFFIXES as $suffix) {
            self::assertDirectoryDoesNotExist($root . $suffix, $suffix);
        }
    }

    public function testProductionCodeHasNoFinancingOrCpImplementation(): void
    {
        $root = dirname(__DIR__);
        $dirs = [
            $root . '/admin',
            $root . '/system',
        ];

        foreach ($dirs as $dir) {
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
                if (str_ends_with($file->getPathname(), '/system/library/persistence_schema_installer.php')) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach (self::FORBIDDEN_PRODUCTION_MARKERS as $marker) {
                    self::assertStringNotContainsString(
                        $marker,
                        $contents,
                        $file->getPathname() . ' must not contain ' . $marker
                    );
                }
            }
        }
    }

    public function testAdminSettingsRemainLocalOnly(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__) . '/admin/view/template/module/mt_uni_credit.twig');
        self::assertStringContainsString('module_mt_uni_credit_status', $twig);
        // CP host is display-only; secret uses password field with empty value (never re-rendered).
        self::assertStringNotContainsString('name="control_panel', $twig);
        self::assertStringContainsString('name="module_mt_uni_credit_secret"', $twig);
        self::assertStringContainsString('type="password"', $twig);
        self::assertStringContainsString('value=""', $twig);
        self::assertStringNotContainsString('type="file"', $twig);
        self::assertStringNotContainsString('passphrase', strtolower($twig));
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $twig);
        self::assertStringNotContainsString('BEGIN CERTIFICATE', $twig);
    }
}
