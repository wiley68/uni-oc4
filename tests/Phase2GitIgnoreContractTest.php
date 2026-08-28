<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase2GitIgnoreContractTest extends TestCase
{
    public function testGitignoreCoversSensitiveRuntimeFilenames(): void
    {
        $gitignore = (string) file_get_contents(dirname(__DIR__) . '/.gitignore');
        self::assertStringContainsString('/secrets/smartucf-key.php', $gitignore);
        self::assertStringNotContainsString('cp-auth.php', $gitignore);
        self::assertStringContainsString('/keys/*.pem', $gitignore);
        self::assertStringContainsString('/keys/.incoming/', $gitignore);
        self::assertStringContainsString('/keys/.ssl_state.json', $gitignore);
        self::assertStringContainsString('/keys/.sync.lock', $gitignore);
    }

    public function testProtectionFilesExistAndSensitiveRuntimeAbsentFromTreeOrIgnored(): void
    {
        $root = dirname(__DIR__);
        foreach (
            [
                'config/environment.php',
                'config/index.php',
                'keys/.htaccess',
                'keys/index.php',
                'secrets/.htaccess',
                'secrets/index.php',
            ] as $relative
        ) {
            self::assertFileExists($root . '/' . $relative, $relative);
        }

        // Runtime secrets must not be committed; they may or may not exist locally.
        if (is_file($root . '/secrets/smartucf-key.php')) {
            $check = [];
            exec('git -C ' . escapeshellarg($root) . ' check-ignore -v secrets/smartucf-key.php', $check, $code);
            self::assertSame(0, $code, 'secrets/smartucf-key.php must be gitignored when present');
        }

        foreach (['keys/avalon_cert.pem', 'keys/avalon_private_key.pem'] as $pem) {
            if (!is_file($root . '/' . $pem)) {
                continue;
            }
            $check = [];
            exec('git -C ' . escapeshellarg($root) . ' check-ignore -v ' . escapeshellarg($pem), $check, $code);
            self::assertSame(0, $code, $pem . ' must be gitignored when present');
        }
    }

    public function testDeploymentLayoutMatchesUniCreditConvention(): void
    {
        $root = dirname(__DIR__);
        self::assertDirectoryExists($root . '/config');
        self::assertDirectoryExists($root . '/keys');
        self::assertDirectoryExists($root . '/secrets');
        self::assertFileExists($root . '/config/environment.php');
    }
}
