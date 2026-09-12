<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use MtUniCredit\Tests\Support\ProductFinancingTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelClient;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelErrorClass;
use Opencart\System\Library\Extension\MtUniCredit\CpAuthenticationException;
use Opencart\System\Library\Extension\MtUniCredit\CpInvalidPayloadException;
use Opencart\System\Library\Extension\MtUniCredit\CpMalformedJsonException;
use Opencart\System\Library\Extension\MtUniCredit\CpTokenRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceClock;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\ProductOperationIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductSubmissionIssuer;
use PHPUnit\Framework\TestCase;

/**
 * OC4-REAUDIT-02 — canonical 401 parsing; no automatic POST /orders replay.
 */
final class CanonicalCreate401NoReplayTest extends TestCase
{
    private ?FinancingAttemptRepository $attempts = null;

    protected function setUp(): void
    {
        if (!$this->needsIntegration()) {
            return;
        }
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for create-lifecycle 401 tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $this->attempts = new FinancingAttemptRepository(PersistenceIntegrationHarness::connection());
    }

    public function testCanonical401OnCreateDoesNotReplayPostOrders(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(401, [
            'success' => false,
            'error' => 'unauthorized',
            'message' => 'Token expired',
            'data' => new \stdClass(),
        ]);
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(201, [
            'success' => true,
            'error' => null,
            'message' => 'created',
            'data' => [
                'id' => 999,
                'shop_id' => 1,
                'order_id' => '1',
                'unicid' => Phase4TestHarness::TEST_UNICID,
                'created_at' => '2026-01-01 00:00:00',
            ],
        ]);

        $orders = new \MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);
        try {
            $this->submit($service);
            self::fail('Expected ambiguous create failure');
        } catch (ProductFinancingFlowException $exception) {
            self::assertSame(ControlPanelErrorClass::AUTH_FAILED, $exception->errorCode());
        }

        self::assertSame(1, $transport->countOrderCreates());
        $row = $this->attempts->findByOrderId(ProductFinancingTestHarness::STORE_ID, $orders->lastOrderId());
        self::assertNotNull($row);
        self::assertSame(FinancingAttemptState::CP_OUTCOME_UNKNOWN, $row['state']);
        self::assertSame(ControlPanelErrorClass::AUTH_FAILED, $row['last_error_class']);
        self::assertNull($row['control_panel_order_id']);
        self::assertNotSame('bank_send_failed_cp', (string) ($row['last_error_class'] ?? ''));
    }

    public function testBare401OnCreateIsNotAuthEvidenceAndDoesNotReplay(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueue(401, 'unauthorized');

        $client = $this->client($transport);
        try {
            $client->createOrder(['order_id' => '41', 'unicid' => Phase4TestHarness::TEST_UNICID]);
            self::fail('Expected noncanonical 401 rejection');
        } catch (CpMalformedJsonException|CpInvalidPayloadException $exception) {
            self::assertNotInstanceOf(CpAuthenticationException::class, $exception);
        }
        self::assertSame(1, $transport->countOrderCreates());
    }

    public function testPartial401EnvelopeOnCreateIsNotAuthEvidence(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueue(401, '{"success":false}');

        $client = $this->client($transport);
        $this->expectException(CpInvalidPayloadException::class);
        $client->createOrder(['order_id' => '42', 'unicid' => Phase4TestHarness::TEST_UNICID]);
    }

    public function testMalformedJson401OnCreateIsNotAuthEvidence(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueue(401, '{not-json');

        $client = $this->client($transport);
        $this->expectException(CpMalformedJsonException::class);
        $client->createOrder(['order_id' => '43', 'unicid' => Phase4TestHarness::TEST_UNICID]);
    }

    public function testLegacyFailFirstCreate401FlagNoLongerReplays(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->failFirstOrderCreateWith401 = true;
        $transport->enableAutoAuthAndCreate(777);
        $orders = new \MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);
        try {
            $this->submit($service);
            self::fail('Expected create ambiguity after canonical 401');
        } catch (ProductFinancingFlowException $exception) {
            self::assertSame(ControlPanelErrorClass::AUTH_FAILED, $exception->errorCode());
        }
        self::assertSame(1, $transport->countOrderCreates());
        $row = $this->attempts->findByOrderId(ProductFinancingTestHarness::STORE_ID, $orders->lastOrderId());
        self::assertSame(FinancingAttemptState::CP_OUTCOME_UNKNOWN, $row['state']);
    }

    public function testShopGetCanonical401StillRetriesOnce(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(401, [
            'success' => false,
            'error' => 'unauthorized',
            'message' => 'expired',
            'data' => new \stdClass(),
        ]);
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload([
            'access_token' => str_repeat('b', 64),
        ]));
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());

        $client = $this->client($transport);
        $shop = $client->getShop();
        self::assertIsArray($shop);
        $shopGets = array_values(array_filter(
            $transport->requests,
            static fn(array $r): bool => strtoupper($r['method']) === 'GET' && str_contains($r['url'], '/shop')
        ));
        self::assertCount(2, $shopGets);
    }

    public function testPatchCanonical401StillRetriesOnce(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(401, [
            'success' => false,
            'error' => 'unauthorized',
            'message' => 'expired',
            'data' => new \stdClass(),
        ]);
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload([
            'access_token' => str_repeat('c', 64),
        ]));
        $transport->enqueueJson(200, [
            'success' => true,
            'error' => null,
            'message' => 'Статусът е обновен',
            'data' => [
                'id' => 1,
                'shop_id' => 1,
                'order_id' => '55',
                'status_id' => 'bank_sent_process1',
                'status' => 'Изпратен Банка - Процес 1',
                'updated_at' => '2026-01-01 00:00:01',
            ],
        ]);

        $client = $this->client($transport);
        $client->updateOrderStatus('55', 'Изпратен Банка - Процес 1', 'bank_sent_process1');
        $patches = array_values(array_filter(
            $transport->requests,
            static fn(array $r): bool => strtoupper($r['method']) === 'PATCH' && str_contains($r['url'], '/orders/status')
        ));
        self::assertCount(2, $patches);
    }

    private function needsIntegration(): bool
    {
        return str_contains($this->name(), 'CreateDoesNotReplay')
            || str_contains($this->name(), 'LegacyFailFirst');
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

    private function submit(object $service): \Opencart\System\Library\Extension\MtUniCredit\ProductFinancingResult
    {
        self::assertNotNull($this->attempts);
        $line = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 42, 1, []);
        $actor = ProductFinancingTestHarness::actorBinding();
        $scheme = ProductFinancingTestHarness::defaultSchemeSelection();
        $selection = ProductFinancingTestHarness::selectionHash(
            $line,
            $scheme['scheme_key'],
            $scheme['scheme_type'],
            $scheme['kop_code'],
            $scheme['months'],
            $scheme['filter_id'],
            $scheme['first_installment'],
            $actor
        );
        $operation = ProductOperationIdentity::hash(ProductFinancingTestHarness::STORE_ID, 42, [], 1, 'BGN');
        $attempt = (new ProductSubmissionIssuer($this->attempts, new PersistenceClock()))
            ->issueOrReuse(
                ProductFinancingTestHarness::STORE_ID,
                $operation,
                $actor,
                $selection,
                null,
                PersistenceIntegrationHarness::TEST_UNICID
            );

        $shop = ProductFinancingTestHarness::shop();
        $shop['uni_proces'] = 0;
        $posted = ProductFinancingTestHarness::validPostedCustomer();

        return $service->submit(
            $shop,
            ProductFinancingTestHarness::STORE_ID,
            (string) $attempt['submission_token'],
            $actor,
            'sess-a',
            0,
            1,
            42,
            1,
            [],
            'BGN',
            'standard',
            $scheme['scheme_type'],
            $scheme['kop_code'],
            $scheme['months'],
            $scheme['filter_id'],
            $scheme['scheme_key'],
            $scheme['first_installment'],
            $posted,
            'test-unicid',
            '2026-01-01 00:00:00',
            1,
            'bg-bg',
            1,
            1.0,
            'Store',
            'https://example.test/',
            'INV-',
            LockOwnerTokenGenerator::generate()
        );
    }
}
