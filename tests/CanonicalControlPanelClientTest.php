<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelClient;
use Opencart\System\Library\Extension\MtUniCredit\CpInvalidPayloadException;
use Opencart\System\Library\Extension\MtUniCredit\CpTokenRepository;
use Opencart\System\Library\Extension\MtUniCredit\InboundApiEnvelope;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use PHPUnit\Framework\TestCase;

final class CanonicalControlPanelClientTest extends TestCase
{
    public function testLoginTokensOnlyFromDataAndShopUnicidMustMatch(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $client = $this->client($transport);
        $response = $client->login();
        self::assertArrayHasKey('data', $response);
        self::assertArrayHasKey('shop', $response['data']);
        self::assertSame(Phase4TestHarness::TEST_UNICID, $response['data']['shop']['unicid']);
    }

    public function testLegacyTopLevelTokensRejected(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, [
            'success' => true,
            'error' => null,
            'message' => 'ok',
            'data' => [
                'shop' => [
                    'id' => 1,
                    'name' => Phase4TestHarness::TEST_SHOP_URL,
                    'unicid' => Phase4TestHarness::TEST_UNICID,
                ],
            ],
            'access_token' => str_repeat('a', 64),
            'token_type' => 'Bearer',
            'expires_in' => 86400,
        ]);
        $this->expectException(CpInvalidPayloadException::class);
        $this->client($transport)->login();
    }

    public function testLogoutWithoutTokenReturnsCanonicalEnvelope(): void
    {
        $response = $this->client(new FakeCpHttpTransport())->logout();
        self::assertTrue($response['success']);
        self::assertNull($response['error']);
        self::assertSame('Logged out locally.', $response['message']);
        self::assertInstanceOf(\stdClass::class, $response['data']);
    }

    public function testCreateOrderIdentityAssertions(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate(42);
        $client = $this->client($transport);
        $response = $client->createOrder([
            'order_id' => '12345',
            'unicid' => Phase4TestHarness::TEST_UNICID,
            'phone' => '0888000000',
        ]);
        self::assertSame(42, (int) $response['data']['id']);
        self::assertSame('12345', (string) $response['data']['order_id']);
        self::assertSame(Phase4TestHarness::TEST_UNICID, (string) $response['data']['unicid']);
    }

    public function testUpdateOrderStatusRequiresEchoAndIdentity(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate();
        $client = $this->client($transport);
        $response = $client->updateOrderStatus('99', 'Изпратен Банка - Процес 1', 'bank_sent_process1');
        self::assertSame('99', (string) $response['data']['order_id']);
        self::assertSame('bank_sent_process1', (string) $response['data']['status_id']);
        self::assertNotSame('', (string) $response['data']['updated_at']);
        self::assertGreaterThan(0, (int) $response['data']['id']);
        self::assertGreaterThan(0, (int) $response['data']['shop_id']);
    }

    public function testDecodeSuccessEnvelopeRequiresMessageAndErrorNull(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, [
            'success' => true,
            'error' => null,
            'data' => [
                'access_token' => str_repeat('a', 64),
                'token_type' => 'Bearer',
                'expires_in' => 86400,
                'shop' => [
                    'id' => 1,
                    'name' => Phase4TestHarness::TEST_SHOP_URL,
                    'unicid' => Phase4TestHarness::TEST_UNICID,
                ],
            ],
        ]);
        $this->expectException(CpInvalidPayloadException::class);
        $this->client($transport)->login();
    }

    private function client(FakeCpHttpTransport $transport): ControlPanelClient
    {
        $settings = Phase4TestHarness::settings();
        Phase4TestHarness::prepareCredentials($settings);
        $cipher = Phase4TestHarness::cipher();

        return new ControlPanelClient(
            new ModuleCredentialsRepository($settings, $cipher),
            new CpTokenRepository($settings, $cipher, Phase4TestHarness::TEST_STORE_ID),
            $transport,
            Phase4TestHarness::TEST_SHOP_URL,
            Phase4TestHarness::TEST_STORE_ID,
            'https://cp.example.test/api/v1'
        );
    }
}
