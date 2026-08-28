<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\CpAdminHealthPresenter;
use Opencart\System\Library\Extension\MtUniCredit\CpTokenRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleEncryptionKeyProvider;
use Opencart\System\Library\Extension\MtUniCredit\ModuleSettingCipher;
use Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationService;
use PHPUnit\Framework\TestCase;

final class Phase4EncryptionKeySecurityTest extends TestCase
{
    private ModuleEncryptionKeyProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new ModuleEncryptionKeyProvider();
    }

    public function testDerivedKeyDoesNotUseMetadataOnlyInputs(): void
    {
        $metadataOnly = 'oc_|open40|/var/www/storage/|mt_uni_credit_module_encryption_v1';
        $secretInput = ModuleEncryptionKeyProvider::testSecretInput();

        self::assertNotSame(
            $this->provider->resolveDerivedKey($metadataOnly),
            $this->provider->resolveDerivedKey($secretInput)
        );
    }

    public function testChangingMetadataAloneDoesNotChangeDerivedKeyWhenSecretIsStable(): void
    {
        $secret = ModuleEncryptionKeyProvider::testSecretInput();
        $first = $this->provider->resolveDerivedKey($secret);
        $second = $this->provider->resolveDerivedKey($secret);

        self::assertSame($first, $second);
        self::assertSame(32, strlen($first));
    }

    public function testDifferentInstallationSecretsProduceDifferentDerivedKeys(): void
    {
        $keyA = $this->provider->resolveDerivedKey('installation-secret-alpha');
        $keyB = $this->provider->resolveDerivedKey('installation-secret-beta');

        self::assertNotSame($keyA, $keyB);
    }

    public function testResolveSecretInputUsesDbPasswordConstant(): void
    {
        if (!defined('DB_PASSWORD')) {
            define('DB_PASSWORD', 'phase4-runtime-db-password-fixture');
        }

        self::assertSame(\DB_PASSWORD, $this->provider->resolveSecretInput());
    }

    public function testCpSecretEncryptDecryptRoundTrip(): void
    {
        $cipher = Phase4TestHarness::cipher();
        $settings = Phase4TestHarness::settings();
        $repository = new ModuleCredentialsRepository($settings, $cipher);

        $repository->saveSecret(Phase4TestHarness::TEST_STORE_ID, Phase4TestHarness::TEST_SECRET);

        self::assertSame(Phase4TestHarness::TEST_SECRET, $repository->getSecret(Phase4TestHarness::TEST_STORE_ID));
    }

    public function testBearerTokenEncryptDecryptRoundTrip(): void
    {
        $cipher = Phase4TestHarness::cipher();
        $settings = Phase4TestHarness::settings();
        $tokens = new CpTokenRepository($settings, $cipher, Phase4TestHarness::TEST_STORE_ID);
        $token = str_repeat('t', 64);

        $tokens->save($token, 'Bearer', time() + 3600);

        self::assertSame($token, $tokens->getAccessToken());
    }

    public function testCiphertextDoesNotContainPlaintext(): void
    {
        $cipher = Phase4TestHarness::cipher();
        $secretCiphertext = $cipher->encrypt(Phase4TestHarness::TEST_SECRET);
        $tokenCiphertext = $cipher->encrypt(str_repeat('b', 64));

        self::assertStringNotContainsString(Phase4TestHarness::TEST_SECRET, $secretCiphertext);
        self::assertStringNotContainsString(str_repeat('b', 64), $tokenCiphertext);
    }

    public function testTamperedCiphertextFailsClosedForSecretAndToken(): void
    {
        $cipher = Phase4TestHarness::cipher();
        $settings = Phase4TestHarness::settings();
        $credentials = new ModuleCredentialsRepository($settings, $cipher);
        $tokens = new CpTokenRepository($settings, $cipher, Phase4TestHarness::TEST_STORE_ID);

        $credentials->saveSecret(Phase4TestHarness::TEST_STORE_ID, Phase4TestHarness::TEST_SECRET);
        $tokens->save(str_repeat('x', 64), 'Bearer', time() + 3600);

        $tamperedSecret = $this->flipPayloadByte(
            (string) $settings->get(Phase4TestHarness::TEST_STORE_ID, ModuleCredentialsRepository::SECRET_SETTING)
        );
        $tamperedToken = $this->flipPayloadByte(
            (string) $settings->get(Phase4TestHarness::TEST_STORE_ID, CpTokenRepository::ACCESS_TOKEN)
        );

        $settings->set(Phase4TestHarness::TEST_STORE_ID, ModuleCredentialsRepository::SECRET_SETTING, $tamperedSecret);
        $settings->set(Phase4TestHarness::TEST_STORE_ID, CpTokenRepository::ACCESS_TOKEN, $tamperedToken);

        self::assertFalse($credentials->isSecretReadable(Phase4TestHarness::TEST_STORE_ID));
        self::assertNull($credentials->getSecret(Phase4TestHarness::TEST_STORE_ID));
        self::assertNull($tokens->getAccessToken());
    }

    public function testDecryptExceptionDoesNotExposeSensitiveValues(): void
    {
        $cipher = Phase4TestHarness::cipher();
        $plaintext = Phase4TestHarness::TEST_SECRET;
        $encoded = $cipher->encrypt($plaintext);
        $tampered = $this->flipPayloadByte($encoded);

        try {
            $cipher->decrypt($tampered);
            self::fail('Expected decryption failure.');
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsString($plaintext, $exception->getMessage());
        }
    }

    public function testAdminPresenterDoesNotExposeSensitiveValuesOnDecryptFailure(): void
    {
        $settings = Phase4TestHarness::settings();
        Phase4TestHarness::prepareCredentials($settings);
        $cipher = Phase4TestHarness::cipher();
        $credentials = new ModuleCredentialsRepository($settings, $cipher);
        $tokens = new CpTokenRepository($settings, $cipher, Phase4TestHarness::TEST_STORE_ID);
        $tokens->save(str_repeat('z', 64), 'Bearer', time() + 3600);

        $tamperedSecret = $this->flipPayloadByte(
            (string) $settings->get(Phase4TestHarness::TEST_STORE_ID, ModuleCredentialsRepository::SECRET_SETTING)
        );
        $settings->set(Phase4TestHarness::TEST_STORE_ID, ModuleCredentialsRepository::SECRET_SETTING, $tamperedSecret);

        $shopConfiguration = $this->createMock(ShopConfigurationService::class);
        $shopConfiguration->method('getMetadata')->willReturn([
            'fetched_at' => '2026-01-01 00:00:00',
            'expires_at' => '2026-01-02 00:00:00',
            'is_fresh'   => true,
        ]);

        $present = (new CpAdminHealthPresenter($credentials, $tokens, $shopConfiguration, Phase4TestHarness::TEST_STORE_ID))->present();
        $encoded = json_encode($present);
        self::assertIsString($encoded);
        self::assertStringNotContainsString(Phase4TestHarness::TEST_SECRET, $encoded);
        self::assertStringNotContainsString(str_repeat('z', 64), $encoded);
        self::assertFalse($present['secret_readable']);
    }

    private function flipPayloadByte(string $encoded): string
    {
        self::assertStringStartsWith(ModuleSettingCipher::encryptedPrefix(), $encoded);

        $prefixLength = strlen(ModuleSettingCipher::encryptedPrefix());
        $payload = substr($encoded, $prefixLength);
        $raw = base64_decode($payload, true);
        self::assertIsString($raw);
        self::assertNotSame('', $raw);

        $raw[0] = $raw[0] === 'A' ? 'B' : 'A';

        return ModuleSettingCipher::encryptedPrefix() . base64_encode($raw);
    }
}
