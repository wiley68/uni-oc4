<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\DeploymentHealthStatus;
use Opencart\System\Library\Extension\MtUniCredit\MtlsPrivateKeyPassphraseProvider;
use PHPUnit\Framework\TestCase;

final class Phase2SecretLoaderTest extends TestCase
{
    public function testMissingSecretFile(): void
    {
        $provider = new MtlsPrivateKeyPassphraseProvider(sys_get_temp_dir() . '/absent-smartucf-' . uniqid('', true) . '.php');
        self::assertNull($provider->resolve());
        self::assertFalse($provider->isConfigured());
        $health = $provider->health();
        self::assertSame(DeploymentHealthStatus::MISSING, $health['status']);
        self::assertFalse($health['configured']);
        $this->assertHealthDoesNotLeakSecrets($health);
    }

    public function testInvalidReturnValue(): void
    {
        $path = $this->writeSecret("<?php\nreturn 'not-array';\n");
        $provider = new MtlsPrivateKeyPassphraseProvider($path);
        self::assertNull($provider->resolve());
        self::assertSame(DeploymentHealthStatus::INVALID, $provider->health()['status']);
        @unlink($path);
    }

    public function testMissingRequiredKey(): void
    {
        $path = $this->writeSecret("<?php\nreturn ['other' => 'x'];\n");
        $provider = new MtlsPrivateKeyPassphraseProvider($path);
        self::assertNull($provider->resolve());
        self::assertSame(DeploymentHealthStatus::INVALID, $provider->health()['status']);
        @unlink($path);
    }

    public function testBlankPassphraseIsInvalid(): void
    {
        $path = $this->writeSecret("<?php\nreturn ['passphrase' => '   '];\n");
        $provider = new MtlsPrivateKeyPassphraseProvider($path);
        self::assertNull($provider->resolve());
        self::assertSame(DeploymentHealthStatus::INVALID, $provider->health()['status']);
        @unlink($path);
    }

    public function testValidConfiguration(): void
    {
        $path = $this->writeSecret("<?php\nreturn ['passphrase' => 'phase2-fixture-secret'];\n");
        $provider = new MtlsPrivateKeyPassphraseProvider($path);
        self::assertSame('phase2-fixture-secret', $provider->resolve());
        self::assertTrue($provider->isConfigured());
        $health = $provider->health();
        self::assertSame(DeploymentHealthStatus::HEALTHY, $health['status']);
        $this->assertHealthDoesNotLeakSecrets($health);
        $encoded = json_encode($health);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('phase2-fixture-secret', $encoded);
        @unlink($path);
    }

    public function testRelativePathMatchesPs9(): void
    {
        self::assertSame('secrets/smartucf-key.php', MtlsPrivateKeyPassphraseProvider::RELATIVE_PATH);
        self::assertSame('passphrase', MtlsPrivateKeyPassphraseProvider::ARRAY_KEY);
    }

    /** @param array<string, mixed> $health */
    private function assertHealthDoesNotLeakSecrets(array $health): void
    {
        $encoded = json_encode($health);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('passphrase', $encoded);
        self::assertArrayNotHasKey('value', $health);
        self::assertArrayNotHasKey('passphrase', $health);
    }

    private function writeSecret(string $contents): string
    {
        $path = sys_get_temp_dir() . '/smartucf-key-' . uniqid('', true) . '.php';
        file_put_contents($path, $contents);

        return $path;
    }
}
