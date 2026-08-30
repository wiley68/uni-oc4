<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use MtUniCredit\Tests\Support\ProductFinancingTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelErrorClass;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelOrderLifecycleService;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelOrderPayloadBuilder;
use Opencart\System\Library\Extension\MtUniCredit\CpServiceFactory;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptContext;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState;
use Opencart\System\Library\Extension\MtUniCredit\FinancingCustomerData;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleEncryptionKeyProvider;
use Opencart\System\Library\Extension\MtUniCredit\OperationLockRepository;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceClock;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\ProductOperationIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductSubmissionIssuer;
use PHPUnit\Framework\TestCase;

final class Phase10BControlPanelLifecycleTest extends TestCase
{
    private FinancingAttemptRepository $attempts;

    private OperationLockRepository $locks;

    protected function setUp(): void
    {
        if (!getenv('MT_UNI_CREDIT_INTEGRATION')) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for DB integration tests.');
        }
        if (str_contains($this->name(), 'PayloadBuilder') || str_contains($this->name(), 'NoSmartUcf')) {
            return;
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $this->attempts = new FinancingAttemptRepository($db);
        $this->locks = new OperationLockRepository($db);
    }

    public function testPayloadBuilderMatchesCpContract(): void
    {
        $builder = new ControlPanelOrderPayloadBuilder();
        $submission = OrderMaterializationTestHarness::productSubmission();
        $payload = $builder->build($submission, 12345, ProductFinancingTestHarness::shop());
        self::assertSame('12345', $payload['order_id']);
        self::assertSame('0888000000', $payload['phone']);
        self::assertSame(ModuleConstants::VERSION, $payload['version']);
        self::assertSame('BGN', $payload['currency']);
        self::assertArrayNotHasKey('status_id', $payload);

        // Checkout may send phone='' — builder must not invent a placeholder.
        $checkout = OrderMaterializationTestHarness::productSubmission();
        $checkout->customer = new FinancingCustomerData(0, 1, 'Ivan', 'Petrov', 'ivan@example.test', '');
        $emptyPhone = $builder->build($checkout, 99, ProductFinancingTestHarness::shop());
        self::assertSame('', $emptyPhone['phone']);
        self::assertSame(ModuleConstants::VERSION, $emptyPhone['version']);

        $process2Shop = ProductFinancingTestHarness::shop();
        $process2Shop['uni_proces'] = 1;
        $p2 = $builder->build($submission, 1, $process2Shop);
        self::assertArrayNotHasKey('status', $p2);
        self::assertArrayNotHasKey('status_id', $p2);
        foreach (['bank_sent_process1', 'bank_sent_process2', 'bank_send_failed_smartucf'] as $forbidden) {
            self::assertNotContains($forbidden, $p2);
        }
    }

    public function testProductCpSubmitPersistsIdAndState(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate(501);
        $orders = new \MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);
        $result = $this->submitProduct($service);
        self::assertTrue($result->success);
        self::assertSame('cp_order_prepared', $result->step);
        self::assertSame(501, $result->controlPanelOrderId);
        self::assertSame(1, $transport->countOrderCreates());

        $row = $this->attempts->findByOrderId(ProductFinancingTestHarness::STORE_ID, (int) $result->orderId);
        self::assertNotNull($row);
        self::assertSame(FinancingAttemptState::CP_CREATED, $row['state']);
        self::assertSame(501, (int) $row['control_panel_order_id']);
        self::assertNotSame('', (string) ($row['cp_payload'] ?? ''));
        self::assertSame(OrderMaterializationTestHarness::TEST_AWAITING_STATUS_ID, $orders->lastOrderStatusId());
    }

    public function testCpSubmittedReplayDoesNotCallCpAgain(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate(502);
        $orders = new \MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);
        $first = $this->submitProduct($service);
        $second = $this->submitProduct($service, (string) $this->attempts->findByOrderId(
            ProductFinancingTestHarness::STORE_ID,
            (int) $first->orderId
        )['submission_token']);
        self::assertTrue($second->replay);
        self::assertSame(502, $second->controlPanelOrderId);
        self::assertSame(1, $transport->countOrderCreates());
        self::assertSame(1, $orders->addOrderCallCount());
    }

    public function testAuth401ThenSuccessRetriesOnce(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueue(401, '{"success":false}');
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(201, [
            'success' => true,
            'data' => ['id' => 777, 'shop_id' => 1, 'created_at' => '2026-01-01 00:00:00'],
        ]);
        $orders = new \MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);
        $result = $this->submitProduct($service);
        self::assertSame(777, $result->controlPanelOrderId);
        self::assertSame(2, $transport->countOrderCreates());
    }

    public function testAuthHardFailurePreservesLocalOrder(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueue(401, '{"success":false}');
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueue(401, '{"success":false}');
        $orders = new \MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);
        try {
            $this->submitProduct($service);
            self::fail('Expected CP auth failure');
        } catch (ProductFinancingFlowException $exception) {
            self::assertSame(ControlPanelErrorClass::AUTH_FAILED, $exception->errorCode());
        }
        self::assertSame(1, $orders->addOrderCallCount());
        $orderId = $orders->lastOrderId();
        self::assertGreaterThan(0, $orderId);
        $row = $this->attempts->findByOrderId(ProductFinancingTestHarness::STORE_ID, $orderId);
        self::assertNotNull($row);
        self::assertSame(FinancingAttemptState::CP_FAILED_RETRYABLE, $row['state']);
        self::assertSame(ControlPanelErrorClass::AUTH_FAILED, $row['last_error_class']);
        self::assertNull($row['control_panel_order_id']);
    }

    public function testCpRejectionPreservesOrder(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(422, ['error' => 'validation', 'message' => 'phone required']);
        $orders = new \MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);
        try {
            $this->submitProduct($service);
            self::fail('Expected rejection');
        } catch (ProductFinancingFlowException $exception) {
            self::assertSame(ControlPanelErrorClass::REJECTED, $exception->errorCode());
        }
        self::assertSame(1, $orders->addOrderCallCount());
        $row = $this->attempts->findByOrderId(ProductFinancingTestHarness::STORE_ID, $orders->lastOrderId());
        self::assertSame(FinancingAttemptState::CP_FAILED_RETRYABLE, $row['state']);
        self::assertSame(ControlPanelErrorClass::REJECTED, $row['last_error_class']);
    }

    public function testInvalidResponseMissingCpId(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(201, ['success' => true, 'data' => ['shop_id' => 1]]);
        $orders = new \MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);
        try {
            $this->submitProduct($service);
            self::fail('Expected invalid response');
        } catch (ProductFinancingFlowException $exception) {
            self::assertSame(ControlPanelErrorClass::INVALID_RESPONSE, $exception->errorCode());
        }
        $row = $this->attempts->findByOrderId(ProductFinancingTestHarness::STORE_ID, $orders->lastOrderId());
        self::assertSame(ControlPanelErrorClass::INVALID_RESPONSE, $row['last_error_class']);
        self::assertNull($row['control_panel_order_id']);
    }

    public function testTimeoutThenRecoverViaIdempotentRepost(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueTimeout();
        $orders = new \MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);
        try {
            $this->submitProduct($service);
            self::fail('Expected timeout');
        } catch (ProductFinancingFlowException $exception) {
            self::assertSame(ControlPanelErrorClass::TIMEOUT, $exception->errorCode());
        }
        $orderId = $orders->lastOrderId();
        $row = $this->attempts->findByOrderId(ProductFinancingTestHarness::STORE_ID, $orderId);
        self::assertSame(FinancingAttemptState::CP_OUTCOME_UNKNOWN, $row['state']);
        self::assertNotSame('', (string) $row['cp_payload']);

        $transport->enableAutoAuthAndCreate(909);
        $service2 = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);
        $recovered = $this->submitProduct($service2, (string) $row['submission_token']);
        self::assertSame(909, $recovered->controlPanelOrderId);
        self::assertSame(1, $orders->addOrderCallCount());
        self::assertGreaterThanOrEqual(2, $transport->countOrderCreates());
    }

    public function testStaleCpSubmittingRecoversExistingOrder(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate(333);
        $orders = new \MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);
        $result = $this->submitProduct($service);
        $row = $this->attempts->findByOrderId(ProductFinancingTestHarness::STORE_ID, (int) $result->orderId);
        // Simulate crash after CP create before local persist.
        $this->attempts->transitionFromStates(
            (int) $row['attempt_id'],
            [FinancingAttemptState::CP_CREATED],
            FinancingAttemptState::CP_SUBMITTING
        );
        $db = PersistenceIntegrationHarness::connection();
        $table = $db->getPrefix() . 'mt_uni_credit_financing_attempt';
        $db->query(
            "UPDATE `{$table}` SET `control_panel_order_id` = NULL WHERE `attempt_id` = " . (int) $row['attempt_id']
        );

        $transport2 = new FakeCpHttpTransport();
        $transport2->enableAutoAuthAndCreate(333);
        $service2 = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport2);
        $recovered = $this->submitProduct($service2, (string) $row['submission_token']);
        self::assertSame(333, $recovered->controlPanelOrderId);
        self::assertSame(1, $orders->addOrderCallCount());
        self::assertSame(1, $transport2->countOrderCreates());
    }

    public function testLockReleasedAfterCpFailure(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueConnectionFailure();
        $orders = new \MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);
        try {
            $this->submitProduct($service);
        } catch (ProductFinancingFlowException $exception) {
            self::assertSame(ControlPanelErrorClass::TRANSPORT_FAILED, $exception->errorCode());
        }
        $row = $this->attempts->findByOrderId(ProductFinancingTestHarness::STORE_ID, $orders->lastOrderId());
        $owner = LockOwnerTokenGenerator::generate();
        self::assertTrue($this->locks->acquire(
            ProductFinancingTestHarness::STORE_ID,
            'product',
            (string) $row['operation_key_hash'],
            $owner
        ));
        $this->locks->release(
            ProductFinancingTestHarness::STORE_ID,
            'product',
            (string) $row['operation_key_hash'],
            $owner
        );
    }

    public function testInvalidTransitionCpCreatedToSubmittingRejected(): void
    {
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempt = $this->attempts->issueWithSubmissionToken(
            ProductFinancingTestHarness::STORE_ID,
            'product',
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            null,
            null
        );
        $this->attempts->attachOrder((int) $attempt['attempt_id'], 55);
        $this->attempts->transitionFromStates((int) $attempt['attempt_id'], [FinancingAttemptState::ISSUED], FinancingAttemptState::ORDER_CREATED);
        $this->attempts->persistControlPanelOrderId((int) $attempt['attempt_id'], 88);
        $this->attempts->transitionFromStates((int) $attempt['attempt_id'], [FinancingAttemptState::ORDER_CREATED], FinancingAttemptState::CP_CREATED);

        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate(99);
        $settings = Phase4TestHarness::settings();
        Phase4TestHarness::prepareCredentials($settings, ProductFinancingTestHarness::STORE_ID);
        $db = PersistenceIntegrationHarness::connection();
        $cp = CpServiceFactory::create(
            $db,
            $settings,
            ProductFinancingTestHarness::STORE_ID,
            Phase4TestHarness::TEST_SHOP_URL,
            Phase4TestHarness::TEST_SHOP_URL,
            $transport,
            new PersistenceClock(),
            null,
            ModuleEncryptionKeyProvider::testSecretInput()
        );
        $lifecycle = new ControlPanelOrderLifecycleService(
            $this->attempts,
            $this->locks,
            $cp['client'],
            new ControlPanelOrderPayloadBuilder()
        );
        $fresh = $this->attempts->findById((int) $attempt['attempt_id']);
        $result = $lifecycle->submitOrRecover(
            new FinancingAttemptContext($fresh),
            $submission,
            55,
            ProductFinancingTestHarness::shop(),
            LockOwnerTokenGenerator::generate()
        );
        self::assertTrue($result->success);
        self::assertTrue($result->replay);
        self::assertSame(88, $result->cpOrderId);
        self::assertSame(0, $transport->countOrderCreates());
    }

    public function testNoSmartUcfMarkersInPhase10BProductionCode(): void
    {
        $files = [
            dirname(__DIR__) . '/system/library/control_panel_order_lifecycle_service.php',
            dirname(__DIR__) . '/system/library/control_panel_order_payload_builder.php',
            dirname(__DIR__) . '/system/library/control_panel_client.php',
            dirname(__DIR__) . '/system/library/product_financing_submission_service.php',
            dirname(__DIR__) . '/system/library/cart_financing_submission_service.php',
            dirname(__DIR__) . '/system/library/checkout_financing_submission_service.php',
        ];
        $forbidden = ['sucfOnlineSessionStart', 'SmartUcfSession', 'bank_redirect', 'Process1', 'Process2'];
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            foreach ($forbidden as $marker) {
                self::assertStringNotContainsString($marker, $contents, $file);
            }
        }
    }

    private function submitProduct(object $service, ?string $token = null): \Opencart\System\Library\Extension\MtUniCredit\ProductFinancingResult
    {
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
        if ($token === null) {
            $attempt = (new ProductSubmissionIssuer($this->attempts, new PersistenceClock()))
                ->issueOrReuse(ProductFinancingTestHarness::STORE_ID, $operation, $actor, $selection);
            $token = (string) $attempt['submission_token'];
        }

        return $service->submit(
            ProductFinancingTestHarness::shop(),
            ProductFinancingTestHarness::STORE_ID,
            $token,
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
            ProductFinancingTestHarness::validPostedCustomer(),
            'test-unicid',
            '2026-08-28 12:00:00',
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
