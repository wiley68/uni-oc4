<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
use Opencart\System\Library\Extension\MtUniCredit\DurableEurOrderProof;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingCustomerData;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OrderBankStatusRepository;
use Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationFlags;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfEndpointPolicy;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfFailureClassifier;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleRepository;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleStates;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfPayloadBuilder;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfSessionCoordinator;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfSessionException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

final class Phase11AProcess1ContractTest extends TestCase
{
    public function testProcessSelectorAndVersionRemainFrozen(): void
    {
        self::assertFalse(ShopConfigurationFlags::isSecondaryProcess(['uni_proces' => 0]));
        self::assertTrue(ShopConfigurationFlags::isSecondaryProcess(['uni_proces' => 1]));
        self::assertSame('2.0.3', ModuleConstants::VERSION);
    }

    public function testPayloadHasNoProcess2IdentityFieldsAndKeepsEmptyPhone(): void
    {
        $submission = OrderMaterializationTestHarness::productSubmission();
        $submission->customer = new FinancingCustomerData(0, 1, 'Ivan', 'Petrov', 'ivan@example.test', '');
        $submission->eurOrderProof = new DurableEurOrderProof(123, $submission->storeId, 1, 1.0, 1200.0, 1200.0);
        $payload = (new SmartUcfPayloadBuilder())->build($submission, mt_uni_credit_valid_shop_snapshot(), 123);

        self::assertSame('123', $payload['orderNo']);
        self::assertSame('', $payload['clientPhone']);
        self::assertSame($submission->financingCalculation->scheme->kopCode, $payload['onlineProductCode']);
        foreach (array_keys($payload) as $key) {
            self::assertDoesNotMatchRegularExpression('/egn|phone2/i', (string) $key);
        }
    }

    public static function durableAddresses(): array
    {
        return ['billing' => ['billing'], 'shipping fallback' => ['shipping'], 'no durable address' => ['missing']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('durableAddresses')]
    public function testCpCreatedResumeHydratesNativeEurOrderBeforeBankSend(string $addressSource): void
    {
        $bank = new class {
            public int $calls = 0;
            public function createSession(array $shop, object $submission, int $orderId): array
            {
                ++$this->calls;
                $payload = (new SmartUcfPayloadBuilder())->build($submission, $shop, $orderId);
                \PHPUnit\Framework\Assert::assertSame('600.00', $payload['totalPrice']);
                \PHPUnit\Framework\Assert::assertSame('300.00', $payload['items'][0]['singlePrice']);
                \PHPUnit\Framework\Assert::assertSame(600.0, (float) $payload['items'][0]['singlePrice'] * $payload['items'][0]['count']);
                \PHPUnit\Framework\Assert::assertSame('Ivan', $payload['clientFirstName']);
                return [
                    'session_id' => 'resume-850',
                    'redirect_url' => 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/resume-850',
                    'http_code' => 200,
                ];
            }
        };
        [$coordinator, $attemptId, $submission, $db] = $this->coordinatorWithClient($bank, 850, true, 0.5);
        $prefix = $db->getPrefix();
        $db->query("UPDATE `{$prefix}order` SET `firstname` = 'Ivan', `lastname` = 'Petrov',
            `email` = 'ivan@example.test', `telephone` = '0888000000',
            `payment_address_1` = 'Test 1', `payment_city` = 'Sofia'
            WHERE `order_id` = 850");
        $db->query("INSERT INTO `{$prefix}order_product`
            (`order_product_id`, `order_id`, `product_id`, `name`, `model`, `quantity`, `price`, `total`, `tax`)
            VALUES (8501, 850, 42, 'Test Product', 'SKU-42', 2, 500, 1000, 100)");
        if ($addressSource !== 'billing') {
            $shipping = $addressSource === 'shipping' ? 'Test 1' : '';
            $db->query("UPDATE `{$prefix}order` SET `payment_address_1` = '', `shipping_address_1` = '{$shipping}' WHERE `order_id` = 850");
        }
        $row = (new FinancingAttemptRepository($db))->findById($attemptId);
        $resume = \Opencart\System\Library\Extension\MtUniCredit\ResumeSubmissionFactory::create(
            $submission->entryPoint, $submission->storeId, null,
            (string) $row['operation_key_hash'], 850
        );
        if ($addressSource === 'missing') {
            try {
                $coordinator->run($attemptId, mt_uni_credit_valid_shop_snapshot(), $resume, 850, 901);
                self::fail('Missing durable address must fail before a bank claim.');
            } catch (\Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException $exception) {
                self::assertSame('currency_unavailable', $exception->errorCode());
            }
            self::assertSame(0, $bank->calls);
            self::assertSame(SmartUcfLifecycleStates::NOT_STARTED, (new SmartUcfLifecycleRepository($db))->findByAttempt($attemptId)['smartucf_state']);
            return;
        }
        $result = $coordinator->run($attemptId, mt_uni_credit_valid_shop_snapshot(), $resume, 850, 901);
        self::assertTrue($result->isCreated());
        self::assertSame(1, $bank->calls);
        self::assertSame('EUR', $resume->orderDraft->currencyCode);
        self::assertSame(600.0, $resume->financingCalculation->price);

        $replay = \Opencart\System\Library\Extension\MtUniCredit\ResumeSubmissionFactory::create(
            $submission->entryPoint, $submission->storeId, null,
            (string) $row['operation_key_hash'], 850
        );
        self::assertTrue($coordinator->run($attemptId, mt_uni_credit_valid_shop_snapshot(), $replay, 850, 901)->isCreated());
        self::assertSame(1, $bank->calls);

        PersistenceIntegrationHarness::seedNativeOrder(850, $submission->storeId, 1200.0, 'BGN', 2, 1.0);
        $stale = \Opencart\System\Library\Extension\MtUniCredit\ResumeSubmissionFactory::create(
            $submission->entryPoint, $submission->storeId, null,
            (string) $row['operation_key_hash'], 850
        );
        try {
            $coordinator->run($attemptId, mt_uni_credit_valid_shop_snapshot(), $stale, 850, 901);
            self::fail('Old non-EUR order replayed under the current EUR shop snapshot.');
        } catch (\Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException $expected) {
            self::assertSame('currency_unavailable', $expected->errorCode());
        }
        self::assertSame(1, $bank->calls);
    }

    public function testEndpointPolicyTrustsOnlyKnownUcfinHosts(): void
    {
        $policy = new SmartUcfEndpointPolicy();
        self::assertSame(
            'https://online.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
            $policy->buildSessionStartUrl('https://online.ucfin.bg/suos/api/otp/')
        );
        $this->expectException(\InvalidArgumentException::class);
        $policy->buildSessionStartUrl('https://attacker.example/suos/api/otp/');
    }

    public function testCoordinatorSuccessWritesOnlyProcess1Status(): void
    {
        [$coordinator, $attemptId, $submission, $db, $transport] = $this->coordinatorWithClient(
            new class {
                /** @return array{session_id: string, redirect_url: string, http_code: int} */
                public function createSession(array $shop, object $submission, int $localOrderId): array
                {
                    return [
                        'session_id' => 'session-123',
                        'redirect_url' => 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/session-123',
                        'http_code' => 200,
                    ];
                }
            }
        );

        $result = $coordinator->run($attemptId, mt_uni_credit_valid_shop_snapshot(), $submission, 811, 901);
        self::assertTrue($result->isCreated());
        $row = (new SmartUcfLifecycleRepository($db))->findByAttempt($attemptId);
        self::assertSame(SmartUcfLifecycleStates::CREATED, $row['smartucf_state']);
        $status = $db->query(
            'SELECT `status_id` FROM `' . $db->getPrefix() . 'mt_uni_credit_order_bank_status` WHERE `order_id` = 811'
        );
        self::assertSame(BankStatus::SENT_PROCESS1, $status->row['status_id']);
        self::assertNotSame(BankStatus::SENT_PROCESS2, $status->row['status_id']);
        $patches = array_values(array_filter(
            $transport->requests,
            static fn(array $request): bool => $request['method'] === 'PATCH'
                && str_contains($request['url'], '/orders/status')
        ));
        self::assertCount(1, $patches);
        self::assertSame('811', $patches[0]['payload']['order_id']);
        self::assertSame(BankStatus::SENT_PROCESS1, $patches[0]['payload']['status_id']);
        self::assertSame(BankStatus::LABEL_SENT_PROCESS1, $patches[0]['payload']['status']);
    }

    public function testCpPatchUsesShopOrderIdNotControlPanelInternalId(): void
    {
        [$coordinator, $attemptId, $submission, $db, $transport] = $this->coordinatorWithClient(
            new class {
                public int $calls = 0;

                /** @return array{session_id: string, redirect_url: string, http_code: int} */
                public function createSession(array $shop, object $submission, int $localOrderId): array
                {
                    $this->calls++;

                    return [
                        'session_id' => 'session-shop-id',
                        'redirect_url' => 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/session-shop-id',
                        'http_code' => 200,
                    ];
                }
            },
            820
        );

        $coordinator->run($attemptId, mt_uni_credit_valid_shop_snapshot(), $submission, 820, 999001);
        $patches = array_values(array_filter(
            $transport->requests,
            static fn(array $request): bool => $request['method'] === 'PATCH'
                && str_contains($request['url'], '/orders/status')
        ));
        self::assertSame('820', $patches[0]['payload']['order_id']);
        self::assertNotSame('999001', $patches[0]['payload']['order_id']);
    }

    public function testCpPatchFailureAfterSmartUcfSuccessDoesNotWriteFailureAndReplayReconcilesWithoutSecondSession(): void
    {
        $client = new class {
            public int $calls = 0;

            /** @return array{session_id: string, redirect_url: string, http_code: int} */
            public function createSession(array $shop, object $submission, int $localOrderId): array
            {
                $this->calls++;

                return [
                    'session_id' => 'session-replay',
                    'redirect_url' => 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/session-replay',
                    'http_code' => 200,
                ];
            }
        };
        [$coordinator, $attemptId, $submission, $db, $transport] = $this->coordinatorWithClient($client, 821);
        $transport->failStatusPatch = true;

        $first = $coordinator->run($attemptId, mt_uni_credit_valid_shop_snapshot(), $submission, 821, 9021);
        self::assertTrue($first->isCreated());
        self::assertSame(1, $client->calls);
        $status = $db->query(
            'SELECT `status_id` FROM `' . $db->getPrefix() . 'mt_uni_credit_order_bank_status` WHERE `order_id` = 821'
        );
        self::assertSame(BankStatus::SENT_PROCESS1, $status->row['status_id']);
        self::assertNotSame(BankStatus::SEND_FAILED_SMARTUCF, $status->row['status_id']);

        $transport->failStatusPatch = false;
        $second = $coordinator->run($attemptId, mt_uni_credit_valid_shop_snapshot(), $submission, 821, 9021);
        self::assertTrue($second->isCreated());
        self::assertSame(1, $client->calls);
        $patches = array_values(array_filter(
            $transport->requests,
            static fn(array $request): bool => $request['method'] === 'PATCH'
                && str_contains($request['url'], '/orders/status')
        ));
        self::assertGreaterThanOrEqual(2, count($patches));
        self::assertSame('821', $patches[array_key_last($patches)]['payload']['order_id']);
        self::assertSame(BankStatus::SENT_PROCESS1, $patches[array_key_last($patches)]['payload']['status_id']);
    }

    public function testKnownFailureWritesSmartUcfFailureWhileTimeoutStaysUnknown(): void
    {
        [$failed, $failedId, $submission, $db] = $this->coordinatorWithClient(
            new class {
                public function createSession(): array
                {
                    throw new SmartUcfSessionException(
                        'Rejected',
                        false,
                        '{"error":"rejected"}',
                        422,
                        SmartUcfSessionException::KIND_REMOTE
                    );
                }
            },
            812
        );
        self::assertTrue($failed->run($failedId, mt_uni_credit_valid_shop_snapshot(), $submission, 812, 902)->isFailed());
        $status = $db->query(
            'SELECT `status_id` FROM `' . $db->getPrefix() . 'mt_uni_credit_order_bank_status` WHERE `order_id` = 812'
        );
        self::assertSame(BankStatus::SEND_FAILED_SMARTUCF, $status->row['status_id']);

        [$timeout, $timeoutId, $timeoutSubmission, $timeoutDb] = $this->coordinatorWithClient(
            new class {
                public function createSession(): array
                {
                    throw new SmartUcfSessionException(
                        'Timeout',
                        false,
                        '',
                        0,
                        SmartUcfSessionException::KIND_TRANSPORT
                    );
                }
            },
            813,
            false
        );
        self::assertTrue(
            $timeout->run($timeoutId, mt_uni_credit_valid_shop_snapshot(), $timeoutSubmission, 813, 903)->isOutcomeUnknown()
        );
        $row = (new SmartUcfLifecycleRepository($timeoutDb))->findByAttempt($timeoutId);
        self::assertSame(SmartUcfLifecycleStates::OUTCOME_UNKNOWN, $row['smartucf_state']);
    }

    /**
     * @return array{SmartUcfSessionCoordinator, int, object, object, FakeCpHttpTransport}
     */
    private function coordinatorWithClient(object $client, int $orderId = 811, bool $reset = true, float $currencyValue = 1.0): array
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration database is unavailable.');
        }
        if ($reset) {
            PersistenceIntegrationHarness::resetTables();
        }
        $db = PersistenceIntegrationHarness::connection();
        (new \Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller($db))->installAll();
        $submission = OrderMaterializationTestHarness::productSubmission();
        if ($currencyValue !== 1.0) {
            $submission->orderDraft->currencyValue = $currencyValue;
            $submission->financingCalculation = OrderMaterializationTestHarness::calculation(1200.0 * $currencyValue);
        }
        $attempts = new FinancingAttemptRepository($db);
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId,
            $submission->entryPoint,
            hash('sha256', 'phase11-operation-' . $orderId),
            hash('sha256', 'phase11-actor-' . $orderId),
            hash('sha256', 'phase11-selection-' . $orderId),
            PersistenceIntegrationHarness::TEST_UNICID
        );
        $attempts->attachOrder((int) $row['attempt_id'], $orderId);
        PersistenceIntegrationHarness::seedSuccessfulEurAttempt((int) $row['attempt_id'], $orderId, $submission);
        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate();
        $services = Phase4TestHarness::services($transport, null, $db, $submission->storeId);

        return [
            new SmartUcfSessionCoordinator(
                new SmartUcfLifecycleRepository($db),
                $client,
                new SmartUcfFailureClassifier(),
                new OrderBankStatusRepository($db),
                $services['client'],
                \MtUniCredit\Tests\Support\StatusSyncTestFactory::create($db, $services['client'])
            ),
            (int) $row['attempt_id'],
            $submission,
            $db,
            $transport,
        ];
    }
}
