<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase4DeploymentRemediationTest extends TestCase
{
    public function testCpAuthDeploymentFilesAreAbsent(): void
    {
        $root = dirname(__DIR__);
        self::assertFileDoesNotExist($root . '/secrets/cp-auth.php');
        self::assertFileDoesNotExist($root . '/secrets/cp-auth.php.example');
    }

    public function testGitignoreDoesNotReferenceCpAuth(): void
    {
        $gitignore = (string) file_get_contents(dirname(__DIR__) . '/.gitignore');
        self::assertStringNotContainsString('cp-auth.php', $gitignore);
        self::assertStringContainsString('/secrets/smartucf-key.php', $gitignore);
    }

    public function testProductionCodeDoesNotReferenceCpAuthFile(): void
    {
        $root = dirname(__DIR__);
        foreach ([$root . '/admin', $root . '/system', $root . '/docs'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                self::assertStringNotContainsString('cp-auth.php', $contents, $file->getPathname());
                self::assertStringNotContainsString('CpAuthSecretProvider', $contents, $file->getPathname());
            }
        }
    }

    public function testApprovedDeploymentTreeIsUnchanged(): void
    {
        $root = dirname(__DIR__);
        self::assertFileExists($root . '/config/environment.php');
        self::assertDirectoryExists($root . '/keys');
        self::assertDirectoryExists($root . '/secrets');
        self::assertFileDoesNotExist($root . '/secrets/cp-auth.php.example');

        $secretFiles = glob($root . '/secrets/*.php') ?: [];
        $relative = array_values(array_filter(
            array_map(static fn(string $path): string => basename($path), $secretFiles),
            static fn(string $name): bool => $name !== 'index.php'
        ));
        foreach ($relative as $name) {
            self::assertSame(
                'smartucf-key.php',
                $name,
                'Only smartucf-key.php may exist as a manual PHP secret under secrets/'
            );
        }
    }

    public function testCredentialsRepositoryStoresEncryptedSecretInSettings(): void
    {
        $settings = \MtUniCredit\Tests\Support\Phase4TestHarness::settings();
        \MtUniCredit\Tests\Support\Phase4TestHarness::prepareCredentials($settings);

        $stored = $settings->get(
            \MtUniCredit\Tests\Support\Phase4TestHarness::TEST_STORE_ID,
            \Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository::SECRET_SETTING
        );
        self::assertIsString($stored);
        self::assertStringStartsWith(
            \Opencart\System\Library\Extension\MtUniCredit\ModuleSettingCipher::encryptedPrefix(),
            $stored
        );
        self::assertStringNotContainsString(
            \MtUniCredit\Tests\Support\Phase4TestHarness::TEST_SECRET,
            $stored
        );
    }
}
