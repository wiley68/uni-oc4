<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelClient;
use Opencart\System\Library\Extension\MtUniCredit\CpHttpException;
use Opencart\System\Library\Extension\MtUniCredit\CpInvalidPayloadException;
use Opencart\System\Library\Extension\MtUniCredit\CpMalformedJsonException;
use Opencart\System\Library\Extension\MtUniCredit\CpTokenRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use PHPUnit\Framework\TestCase;

/**
 * REVIEW-06 / REVIEW-10 — canonical failure envelopes and strict response types.
 */
final class CanonicalCpFailureEnvelopeRemediationTest extends TestCase
{
    public function testNon2xxCanonicalFailureBecomesCpHttpException(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueue(409, json_encode([
            'success' => false,
            'error' => 'semantic_conflict',
            'message' => 'Conflict',
            'data' => new \stdClass(),
        ], JSON_THROW_ON_ERROR));

        $client = $this->client($transport);
        try {
            $client->createOrder(['order_id' => '1', 'unicid' => Phase4TestHarness::TEST_UNICID]);
            self::fail('Expected CpHttpException');
        } catch (CpHttpException $exception) {
            self::assertTrue($exception->isCanonicalFailure());
            self::assertSame('semantic_conflict', $exception->getCanonicalError());
            self::assertSame(409, $exception->getStatusCode());
        }
    }

    public function testMalformedNon2xxIsNotHttpTerminalPayload(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueue(409, '{"success":false}');

        $client = $this->client($transport);
        $this->expectException(CpInvalidPayloadException::class);
        $client->createOrder(['order_id' => '1', 'unicid' => Phase4TestHarness::TEST_UNICID]);
    }

    public function testBareJsonNon2xxIsMalformedOrInvalid(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueue(500, 'not-json');

        $client = $this->client($transport);
        $this->expectException(CpMalformedJsonException::class);
        $client->createOrder(['order_id' => '1', 'unicid' => Phase4TestHarness::TEST_UNICID]);
    }

    public function testStringIdRejectedByCreateIdentity(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueue(200, json_encode([
            'success' => true,
            'error' => null,
            'message' => 'ok',
            'data' => [
                'id' => '42',
                'shop_id' => 1,
                'order_id' => '123',
                'unicid' => Phase4TestHarness::TEST_UNICID,
                'created_at' => '2026-01-01 00:00:00',
            ],
        ], JSON_THROW_ON_ERROR));

        $client = $this->client($transport);
        $this->expectException(CpInvalidPayloadException::class);
        $client->createOrder(['order_id' => '123', 'unicid' => Phase4TestHarness::TEST_UNICID]);
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
