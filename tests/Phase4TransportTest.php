<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelClient;
use Opencart\System\Library\Extension\MtUniCredit\CpAuthenticationException;
use Opencart\System\Library\Extension\MtUniCredit\CpConnectionException;
use Opencart\System\Library\Extension\MtUniCredit\CpHttpConstants;
use Opencart\System\Library\Extension\MtUniCredit\CpHttpException;
use Opencart\System\Library\Extension\MtUniCredit\CpInvalidPayloadException;
use Opencart\System\Library\Extension\MtUniCredit\CpMalformedJsonException;
use Opencart\System\Library\Extension\MtUniCredit\CpTimeoutException;
use Opencart\System\Library\Extension\MtUniCredit\CpTokenRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleSettingCipher;
use PHPUnit\Framework\TestCase;

final class Phase4TransportTest extends TestCase
{
    public function testCurlTransportUsesTlsAndBoundedTimeouts(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/system/library/curl_cp_http_transport.php');
        self::assertStringContainsString('CURLOPT_SSL_VERIFYPEER', $source);
        self::assertStringContainsString('CURLOPT_SSL_VERIFYHOST', $source);
        self::assertStringContainsString('CpHttpConstants::CONNECT_TIMEOUT_SECONDS', $source);
        self::assertStringContainsString('CpHttpConstants::TOTAL_TIMEOUT_SECONDS', $source);
        self::assertStringNotContainsString('CURLOPT_SSL_VERIFYPEER, false', $source);
    }

    public function testDefaultTimeoutsMatchContract(): void
    {
        self::assertSame(5, CpHttpConstants::CONNECT_TIMEOUT_SECONDS);
        self::assertSame(15, CpHttpConstants::TOTAL_TIMEOUT_SECONDS);
        self::assertSame(86400, \Opencart\System\Library\Extension\MtUniCredit\SecurityConstants::SHOP_CACHE_TTL_SECONDS);
    }

    public function testFakeTransportReturnsJsonSuccess(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, ['success' => true, 'data' => ['ok' => true]]);
        $response = $transport->request('GET', 'https://example.test/api/v1/shop', ['Accept' => 'application/json'], null);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"success":true', $response->getBody());
    }

    public function testMalformedJsonIsClassified(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueue(200, '{not-json');
        $client = $this->client($transport);
        $this->expectException(CpMalformedJsonException::class);
        $client->login();
    }

    public function testHttp4xxAnd5xxClassification(): void
    {
        $http4xx = new CpHttpException(403, ['error' => 'forbidden']);
        $http5xx = new CpHttpException(503, []);
        self::assertTrue($http4xx->isPermanentAuthOrConfiguration());
        self::assertFalse($http4xx->isTransient());
        self::assertTrue($http5xx->isTransient());
    }

    public function testTransportExceptionsAreTransient(): void
    {
        self::assertTrue((new CpConnectionException('x'))->isTransient());
        self::assertTrue((new CpTimeoutException('x'))->isTransient());
    }

    public function testLoginRequestUsesPayloadSecretButDoesNotPersistItInSettings(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $settings = Phase4TestHarness::settings();
        Phase4TestHarness::prepareCredentials($settings);
        $cipher = Phase4TestHarness::cipher();
        $client = new ControlPanelClient(
            new ModuleCredentialsRepository($settings, $cipher),
            new CpTokenRepository($settings, $cipher, Phase4TestHarness::TEST_STORE_ID),
            $transport,
            Phase4TestHarness::TEST_SHOP_URL,
            Phase4TestHarness::TEST_STORE_ID,
            'https://cp.example.test/api/v1'
        );
        $client->login();
        $storedToken = $settings->get(Phase4TestHarness::TEST_STORE_ID, CpTokenRepository::ACCESS_TOKEN);
        self::assertIsString($storedToken);
        self::assertStringStartsWith(ModuleSettingCipher::encryptedPrefix(), $storedToken);
        self::assertStringNotContainsString(Phase4TestHarness::TEST_SECRET, $storedToken);
        self::assertStringNotContainsString(str_repeat('a', 64), $storedToken);
    }

    private function client(FakeCpHttpTransport $transport): ControlPanelClient
    {
        $settings = Phase4TestHarness::settings();
        Phase4TestHarness::prepareCredentials($settings);
        $cipher = Phase4TestHarness::cipher();
        $credentials = new ModuleCredentialsRepository($settings, $cipher);
        $tokens = new CpTokenRepository($settings, $cipher, Phase4TestHarness::TEST_STORE_ID);

        return new ControlPanelClient(
            $credentials,
            $tokens,
            $transport,
            Phase4TestHarness::TEST_SHOP_URL,
            Phase4TestHarness::TEST_STORE_ID,
            'https://cp.example.test/api/v1'
        );
    }
}
