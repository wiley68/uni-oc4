<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
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
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testPayloadHasNoProcess2IdentityFieldsAndKeepsEmptyPhone(): void
    {
        $submission = OrderMaterializationTestHarness::productSubmission();
        $submission->customer = new FinancingCustomerData(0, 1, 'Ivan', 'Petrov', 'ivan@example.test', '');
        $payload = (new SmartUcfPayloadBuilder())->build($submission, mt_uni_credit_valid_shop_snapshot(), 123);

        self::assertSame('123', $payload['orderNo']);
        self::assertSame('', $payload['clientPhone']);
        self::assertSame($submission->financingCalculation->scheme->kopCode, $payload['onlineProductCode']);
        foreach (array_keys($payload) as $key) {
            self::assertDoesNotMatchRegularExpression('/egn|phone2/i', (string) $key);
        }
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
        self::assertSame('901', $patches[0]['payload']['order_id']);
        self::assertSame(BankStatus::SENT_PROCESS1, $patches[0]['payload']['status_id']);
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
    private function coordinatorWithClient(object $client, int $orderId = 811, bool $reset = true): array
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
        $attempts = new FinancingAttemptRepository($db);
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId,
            $submission->entryPoint,
            hash('sha256', 'phase11-operation-' . $orderId),
            hash('sha256', 'phase11-actor-' . $orderId),
            hash('sha256', 'phase11-selection-' . $orderId)
        );
        $attempts->attachOrder((int) $row['attempt_id'], $orderId);
        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate();
        $services = Phase4TestHarness::services($transport, null, $db, $submission->storeId);

        return [
            new SmartUcfSessionCoordinator(
                new SmartUcfLifecycleRepository($db),
                $client,
                new SmartUcfFailureClassifier(),
                new OrderBankStatusRepository($db),
                $services['client']
            ),
            (int) $row['attempt_id'],
            $submission,
            $db,
            $transport,
        ];
    }
}
