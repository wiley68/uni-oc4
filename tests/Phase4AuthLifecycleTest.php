<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelClient;
use Opencart\System\Library\Extension\MtUniCredit\CpAuthenticationException;
use Opencart\System\Library\Extension\MtUniCredit\CpInvalidPayloadException;
use Opencart\System\Library\Extension\MtUniCredit\CpTokenRepository;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleSettingCipher;
use Opencart\System\Library\Extension\MtUniCredit\ShopCacheRepository;
use PHPUnit\Framework\TestCase;

final class Phase4AuthLifecycleTest extends TestCase
{
    public function testValidLoginPersistsEncryptedToken(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $stack = $this->stack($transport);
        $stack['client']->login();
        self::assertTrue($stack['tokens']->hasToken());
        self::assertSame(str_repeat('a', 64), $stack['tokens']->getAccessToken());
        $stored = $stack['settings']->get(Phase4TestHarness::TEST_STORE_ID, CpTokenRepository::ACCESS_TOKEN);
        self::assertIsString($stored);
        self::assertStringStartsWith(ModuleSettingCipher::encryptedPrefix(), $stored);
    }

    public function testInvalidCredentialsDoNotPersistToken(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(401, ['error' => 'invalid']);
        $stack = $this->stack($transport);
        try {
            $stack['client']->login();
            self::fail('Expected authentication failure');
        } catch (CpAuthenticationException $exception) {
        }
        self::assertFalse($stack['tokens']->hasToken());
    }

    public function testMalformedLoginResponseInvalidatesToken(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, ['success' => true, 'access_token' => '', 'token_type' => 'Bearer', 'expires_in' => 86400, 'shop' => []]);
        $stack = $this->stack($transport);
        $this->expectException(CpInvalidPayloadException::class);
        $stack['client']->login();
    }

    public function testRefreshRotatesToken(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, [
            'success' => true,
            'access_token' => str_repeat('b', 64),
            'token_type' => 'Bearer',
            'expires_in' => 86400,
        ]);
        $stack = $this->stack($transport, now: 1_700_000_000);
        $stack['client']->login();
        $stack['client']->refreshToken();
        self::assertSame(str_repeat('b', 64), $stack['tokens']->getAccessToken());
    }

    public function testRefreshFailureLeavesSafeState(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(401, ['error' => 'invalid']);
        $stack = $this->stack($transport);
        $stack['client']->login();
        try {
            $stack['client']->refreshToken();
            self::fail('Expected refresh auth failure');
        } catch (CpAuthenticationException $exception) {
        }
        self::assertFalse($stack['tokens']->hasToken());
    }

    public function testLogoutAlwaysInvalidatesLocalToken(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, ['success' => true]);
        $stack = $this->stack($transport);
        $stack['client']->login();
        $stack['client']->logout();
        self::assertFalse($stack['tokens']->hasToken());
    }

    public function testLogoutInvalidatesEvenWhenRemoteFails(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(503, ['error' => 'down']);
        $stack = $this->stack($transport);
        $stack['client']->login();
        try {
            $stack['client']->logout();
            self::fail('Expected remote logout HTTP failure');
        } catch (\Throwable $exception) {
        }
        self::assertFalse($stack['tokens']->hasToken());
    }

    public function test401RetryExactlyOnceThenInvalidate(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(401, ['error' => 'expired']);
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(401, ['error' => 'expired again']);
        $stack = $this->stack($transport, now: 1_700_000_000 + 86400 + 120);
        try {
            $stack['client']->getShop();
            self::fail('Expected second 401 failure');
        } catch (CpAuthenticationException $exception) {
        }
        self::assertFalse($stack['tokens']->hasToken());
        self::assertCount(4, $transport->requests);
    }

    public function test401RetrySucceedsAfterReauth(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(401, ['error' => 'expired']);
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        $stack = $this->stack($transport, now: 1_700_000_000 + 86400 + 120);
        $response = $stack['client']->getShop();
        self::assertArrayHasKey('data', $response);
        self::assertTrue($stack['tokens']->hasToken());
        self::assertCount(4, $transport->requests);
    }

    public function testCredentialChangeInvalidatesTokenAndScopedCache(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required (MT_UNI_CREDIT_INTEGRATION=1).');
        }

        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        $stack = Phase4TestHarness::services($transport);
        $stack['client']->login();
        $stack['shopConfiguration']->refreshRemote();
        self::assertNotNull($stack['shopConfiguration']->getMetadata());

        $cache = new ShopCacheRepository(\MtUniCredit\Tests\Support\PersistenceIntegrationHarness::connection());
        $otherStoreCacheBefore = $cache->findLatest(
            \MtUniCredit\Tests\Support\PersistenceIntegrationHarness::TEST_STORE_ID_B,
            \MtUniCredit\Tests\Support\PersistenceIntegrationHarness::TEST_UNICID_B
        );

        $stack['credentialChange']->onCredentialsChanged(Phase4TestHarness::TEST_UNICID, 'new-unicid-value');
        self::assertFalse($stack['tokens']->hasToken());
        self::assertNull($stack['shopConfiguration']->getMetadata());

        if ($otherStoreCacheBefore !== null) {
            self::assertNotNull($cache->findLatest(
                \MtUniCredit\Tests\Support\PersistenceIntegrationHarness::TEST_STORE_ID_B,
                \MtUniCredit\Tests\Support\PersistenceIntegrationHarness::TEST_UNICID_B
            ));
        }
    }

    public function testDefaultStoreZeroTokenAndCredentialInvalidationIsIsolated(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required (MT_UNI_CREDIT_INTEGRATION=1).');
        }

        $settings = Phase4TestHarness::settings();
        $db = PersistenceIntegrationHarness::connection();
        PersistenceIntegrationHarness::resetTables();

        $storeZero = PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT;
        $storeOne = PersistenceIntegrationHarness::TEST_STORE_ID_ONE;

        $transportZero = new FakeCpHttpTransport();
        $transportZero->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transportZero->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        $stackZero = Phase4TestHarness::services($transportZero, $settings, $db, $storeZero);
        $stackZero['client']->login();
        $stackZero['shopConfiguration']->refreshRemote();
        self::assertTrue($stackZero['tokens']->hasToken());
        self::assertNotNull($stackZero['shopConfiguration']->getMetadata());

        $transportOne = new FakeCpHttpTransport();
        $transportOne->enqueueJson(200, Phase4TestHarness::loginSuccessPayload([
            'access_token' => str_repeat('b', 64),
        ]));
        $transportOne->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        Phase4TestHarness::prepareCredentials($settings, $storeOne);
        $stackOne = Phase4TestHarness::services($transportOne, $settings, $db, $storeOne);
        // Avoid wiping store-zero cache rows installed above.
        $stackOne['client']->login();
        $cache = new ShopCacheRepository($db);
        $cache->replaceValidated($storeOne, Phase4TestHarness::TEST_UNICID, ['shop' => 'store-one']);
        self::assertTrue($stackOne['tokens']->hasToken());
        self::assertNotNull($cache->findLatest($storeOne, Phase4TestHarness::TEST_UNICID));
        self::assertNotNull($cache->findLatest($storeZero, Phase4TestHarness::TEST_UNICID));

        $stackZero['credentialChange']->onCredentialsChanged(Phase4TestHarness::TEST_UNICID, 'changed-unicid-store-0');
        self::assertFalse($stackZero['tokens']->hasToken());
        self::assertNull($cache->findLatest($storeZero, Phase4TestHarness::TEST_UNICID));
        self::assertTrue($stackOne['tokens']->hasToken());
        self::assertNotNull($cache->findLatest($storeOne, Phase4TestHarness::TEST_UNICID));

        // Reverse: store 1 credential change must not wipe store 0 token/cache after re-seed.
        $transportZeroB = new FakeCpHttpTransport();
        $transportZeroB->enqueueJson(200, Phase4TestHarness::loginSuccessPayload([
            'access_token' => str_repeat('c', 64),
        ]));
        $transportZeroB->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        Phase4TestHarness::prepareCredentials($settings, $storeZero);
        $stackZeroB = Phase4TestHarness::services($transportZeroB, $settings, $db, $storeZero);
        $stackZeroB['client']->login();
        $cache->replaceValidated($storeZero, Phase4TestHarness::TEST_UNICID, ['shop' => 'store-zero-again']);

        $stackOne['credentialChange']->onCredentialsChanged(Phase4TestHarness::TEST_UNICID, 'changed-unicid-store-1');
        self::assertFalse($stackOne['tokens']->hasToken());
        self::assertNull($cache->findLatest($storeOne, Phase4TestHarness::TEST_UNICID));
        self::assertTrue($stackZeroB['tokens']->hasToken());
        self::assertNotNull($cache->findLatest($storeZero, Phase4TestHarness::TEST_UNICID));
    }

    /** @return array{client: ControlPanelClient, tokens: CpTokenRepository, settings: \Opencart\System\Library\Extension\MtUniCredit\InMemoryModuleSettingStore} */
    private function stack(FakeCpHttpTransport $transport, int $now = 1_700_000_000): array
    {
        $settings = Phase4TestHarness::settings();
        Phase4TestHarness::prepareCredentials($settings);
        $cipher = Phase4TestHarness::cipher();
        $credentials = new ModuleCredentialsRepository($settings, $cipher);
        $tokens = new CpTokenRepository($settings, $cipher, Phase4TestHarness::TEST_STORE_ID);
        $client = new ControlPanelClient(
            $credentials,
            $tokens,
            $transport,
            Phase4TestHarness::TEST_SHOP_URL,
            Phase4TestHarness::TEST_STORE_ID,
            'https://cp.example.test/api/v1',
            static fn(): int => $now
        );

        return [
            'client'   => $client,
            'tokens'   => $tokens,
            'settings' => $settings,
        ];
    }
}
