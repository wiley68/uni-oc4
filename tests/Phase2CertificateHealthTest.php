<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\CertificateLocalPaths;
use Opencart\System\Library\Extension\MtUniCredit\CertificatePairValidator;
use Opencart\System\Library\Extension\MtUniCredit\DeploymentHealthService;
use Opencart\System\Library\Extension\MtUniCredit\DeploymentHealthStatus;
use Opencart\System\Library\Extension\MtUniCredit\ModuleDeploymentEnvironment;
use Opencart\System\Library\Extension\MtUniCredit\MtlsPrivateKeyPassphraseProvider;
use PHPUnit\Framework\TestCase;

final class Phase2CertificateHealthTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = dirname(__DIR__) . '/tests/fixtures/certificates';
    }

    public function testMissingCertificateAndKey(): void
    {
        $dir = sys_get_temp_dir() . '/mt-uni-keys-' . uniqid('', true);
        mkdir($dir);
        $paths = new CertificateLocalPaths($dir);
        $health = $this->service($paths)->evaluate();
        self::assertSame(DeploymentHealthStatus::MISSING, $health['certificate']['status']);
        self::assertSame(DeploymentHealthStatus::MISSING, $health['private_key']['status']);
        self::assertFalse($health['deployment_ready']);
        @rmdir($dir);
    }

    public function testInvalidPem(): void
    {
        $dir = $this->stagePair(
            $this->fixtureDir . '/invalid_cert.pem',
            $this->fixtureDir . '/invalid_key.pem'
        );
        $secret = $this->writeSecret('phase2-fixture-secret');
        $paths = new CertificateLocalPaths($dir);
        $secrets = new MtlsPrivateKeyPassphraseProvider($secret);
        $health = $this->service($paths, $secrets)->evaluate();
        self::assertSame(DeploymentHealthStatus::INVALID, $health['certificate']['status']);
        $this->cleanupDir($dir);
        @unlink($secret);
    }

    public function testValidMatchingPair(): void
    {
        $dir = $this->stagePair(
            $this->fixtureDir . '/matching_cert.pem',
            $this->fixtureDir . '/matching_key.pem'
        );
        $secret = $this->writeSecret('phase2-fixture-secret');
        $paths = new CertificateLocalPaths($dir);
        $secrets = new MtlsPrivateKeyPassphraseProvider($secret);
        $validator = new CertificatePairValidator($secrets);
        $pair = $paths->readPairBytes();
        self::assertNotNull($pair);
        $validated = $validator->validate($pair['certificate_pem'], $pair['private_key_pem']);
        self::assertNotSame('', $validated['not_after']);

        $health = $this->service($paths, $secrets, $validator)->evaluate();
        self::assertSame(DeploymentHealthStatus::HEALTHY, $health['certificate']['status']);
        self::assertSame(DeploymentHealthStatus::HEALTHY, $health['private_key']['status']);
        self::assertSame(DeploymentHealthStatus::HEALTHY, $health['certificate_validity']['status']);
        self::assertSame(DeploymentHealthStatus::HEALTHY, $health['certificate_key_match']['status']);
        self::assertTrue($health['deployment_ready']);
        $encoded = json_encode($health);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('BEGIN CERTIFICATE', $encoded);
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $encoded);
        self::assertStringNotContainsString('phase2-fixture-secret', $encoded);

        $this->cleanupDir($dir);
        @unlink($secret);
    }

    public function testMismatchedPair(): void
    {
        $dir = $this->stagePair(
            $this->fixtureDir . '/matching_cert.pem',
            $this->fixtureDir . '/other_key.pem'
        );
        $secret = $this->writeSecret('phase2-fixture-secret');
        $paths = new CertificateLocalPaths($dir);
        $secrets = new MtlsPrivateKeyPassphraseProvider($secret);
        $health = $this->service($paths, $secrets)->evaluate();
        self::assertSame(DeploymentHealthStatus::MISMATCH, $health['certificate_key_match']['status']);
        self::assertFalse($health['deployment_ready']);
        $this->cleanupDir($dir);
        @unlink($secret);
    }

    public function testExpiredCertificate(): void
    {
        $dir = $this->stagePair(
            $this->fixtureDir . '/expired_cert.pem',
            $this->fixtureDir . '/expired_key.pem'
        );
        $secret = $this->writeSecret('phase2-fixture-secret');
        $paths = new CertificateLocalPaths($dir);
        $secrets = new MtlsPrivateKeyPassphraseProvider($secret);
        $health = $this->service($paths, $secrets)->evaluate();
        self::assertSame(DeploymentHealthStatus::EXPIRED, $health['certificate_validity']['status']);
        self::assertFalse($health['deployment_ready']);
        $this->cleanupDir($dir);
        @unlink($secret);
    }

    public function testEncryptedKeyRequiresPassphrase(): void
    {
        $dir = $this->stagePair(
            $this->fixtureDir . '/matching_cert.pem',
            $this->fixtureDir . '/encrypted_matching_key.pem'
        );
        $wrong = $this->writeSecret('wrong-passphrase');
        $paths = new CertificateLocalPaths($dir);
        $secrets = new MtlsPrivateKeyPassphraseProvider($wrong);
        $health = $this->service($paths, $secrets)->evaluate();
        self::assertFalse($health['deployment_ready']);
        self::assertNotSame(DeploymentHealthStatus::HEALTHY, $health['certificate_key_match']['status']);

        $right = $this->writeSecret('phase2-test-passphrase');
        $secretsOk = new MtlsPrivateKeyPassphraseProvider($right);
        $healthOk = $this->service($paths, $secretsOk)->evaluate();
        self::assertSame(DeploymentHealthStatus::HEALTHY, $healthOk['certificate_key_match']['status']);
        self::assertTrue($healthOk['deployment_ready']);

        $this->cleanupDir($dir);
        @unlink($wrong);
        @unlink($right);
    }

    public function testFilenamesMatchPs9(): void
    {
        self::assertSame('avalon_cert.pem', CertificateLocalPaths::CERT_FILENAME);
        self::assertSame('avalon_private_key.pem', CertificateLocalPaths::KEY_FILENAME);
    }

    private function service(
        CertificateLocalPaths $paths,
        ?MtlsPrivateKeyPassphraseProvider $secrets = null,
        ?CertificatePairValidator $validator = null
    ): DeploymentHealthService {
        $secrets ??= new MtlsPrivateKeyPassphraseProvider(sys_get_temp_dir() . '/missing-' . uniqid('', true) . '.php');
        $validator ??= new CertificatePairValidator($secrets);

        return new DeploymentHealthService(
            new ModuleDeploymentEnvironment(),
            $secrets,
            $paths,
            $validator
        );
    }

    private function stagePair(string $certSource, string $keySource): string
    {
        $dir = sys_get_temp_dir() . '/mt-uni-keys-' . uniqid('', true);
        mkdir($dir);
        copy($certSource, $dir . '/' . CertificateLocalPaths::CERT_FILENAME);
        copy($keySource, $dir . '/' . CertificateLocalPaths::KEY_FILENAME);

        return $dir;
    }

    private function writeSecret(string $passphrase): string
    {
        $path = sys_get_temp_dir() . '/smartucf-key-' . uniqid('', true) . '.php';
        file_put_contents($path, "<?php\nreturn ['passphrase' => " . var_export($passphrase, true) . "];\n");

        return $path;
    }

    private function cleanupDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
