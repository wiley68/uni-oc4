<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\CanonicalShopUrlProvider;
use Opencart\System\Library\Extension\MtUniCredit\CpAdminHealthPresenter;
use Opencart\System\Library\Extension\MtUniCredit\CpAuthSecretProvider;
use Opencart\System\Library\Extension\MtUniCredit\CpServiceFactory;
use Opencart\System\Library\Extension\MtUniCredit\FixedCpAuthSecretProvider;
use Opencart\System\Library\Extension\MtUniCredit\InMemoryModuleSettingStore;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleSettingCipher;
use Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationService;
use PHPUnit\Framework\TestCase;

final class Phase4AdminPresenterTest extends TestCase
{
    public function testPresenterNeverExposesBearerTokenOrSecret(): void
    {
        $settings = Phase4TestHarness::settings();
        Phase4TestHarness::prepareCredentials($settings);
        $credentials = new ModuleCredentialsRepository($settings, new FixedCpAuthSecretProvider(Phase4TestHarness::TEST_SECRET));
        $cipher = Phase4TestHarness::cipher();
        $tokens = new \Opencart\System\Library\Extension\MtUniCredit\CpTokenRepository(
            $settings,
            $cipher,
            Phase4TestHarness::TEST_STORE_ID
        );
        $tokens->save(str_repeat('z', 64), 'Bearer', time() + 3600);

        $shopConfiguration = $this->createMock(ShopConfigurationService::class);
        $shopConfiguration->method('getMetadata')->willReturn([
            'fetched_at' => '2026-01-01 00:00:00',
            'expires_at' => '2026-01-02 00:00:00',
            'is_fresh'   => true,
        ]);

        $presenter = new CpAdminHealthPresenter($credentials, $tokens, $shopConfiguration, Phase4TestHarness::TEST_STORE_ID);
        $present = $presenter->present();
        $encoded = json_encode($present);
        self::assertIsString($encoded);
        self::assertStringNotContainsString(str_repeat('z', 64), $encoded);
        self::assertStringNotContainsString(Phase4TestHarness::TEST_SECRET, $encoded);
        self::assertArrayNotHasKey('access_token', $present);
        self::assertArrayHasKey('auth_state', $present);
        self::assertArrayHasKey('cache_fresh', $present);
    }

    public function testTwigDoesNotRenderTokenOrSecretFields(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__) . '/admin/view/template/module/mt_uni_credit.twig');
        self::assertStringContainsString('health.auth_state_label', $twig);
        self::assertStringContainsString('health.token_expires_at', $twig);
        self::assertStringNotContainsString('access_token', strtolower($twig));
        self::assertStringNotContainsString('name="secret"', strtolower($twig));
        self::assertStringNotContainsString('{{ health.cp_secret', strtolower($twig));
    }

    public function testCanonicalShopUrlNormalization(): void
    {
        self::assertSame('https://shop.example.com', CanonicalShopUrlProvider::normalize('http://shop.example.com/'));
        self::assertSame('https://shop.example.com', (new CanonicalShopUrlProvider())->resolve('', 'http://shop.example.com'));
    }

    public function testTokenStorageUsesEncryptedPrefix(): void
    {
        $settings = Phase4TestHarness::settings();
        $cipher = new ModuleSettingCipher(Phase4TestHarness::TEST_SECRET);
        $encrypted = $cipher->encrypt('token-value');
        self::assertStringStartsWith(ModuleSettingCipher::encryptedPrefix(), $encrypted);
    }

    public function testCpAuthSecretComesFromDeploymentFilePath(): void
    {
        self::assertSame('secrets/cp-auth.php', CpAuthSecretProvider::RELATIVE_PATH);
    }
}
