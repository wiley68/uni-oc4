<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\DiagnosticDebugLogRepository;
use Opencart\System\Library\Extension\MtUniCredit\DiagnosticPayloadRedactor;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleLocalSettings;
use Opencart\System\Library\Extension\MtUniCredit\InMemoryModuleSettingStore;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\OrderBankStatusRepository;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfDiagnosticJournal;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfFailureClassifier;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleRepository;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleStates;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfSessionCoordinator;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfSessionException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

final class Phase11CSmartUcfDebugJournalTest extends TestCase
{
    public function testDebugOffDoesNotPersistSmartUcfDiagnostics(): void
    {
        [$coordinator, $attemptId, $submission, $db] = $this->coordinatorHarness(
            debugEnabled: false,
            client: $this->successClient()
        );

        self::assertTrue(
            $coordinator->run($attemptId, mt_uni_credit_valid_shop_snapshot(), $submission, 901, 99001)->isCreated()
        );
        self::assertNull((new DiagnosticDebugLogRepository($db))->findLatestByOrderId($submission->storeId, 901));
    }

    public function testDebugOnPersistsSuccessfulSmartUcfSession(): void
    {
        [$coordinator, $attemptId, $submission, $db] = $this->coordinatorHarness(
            debugEnabled: true,
            client: $this->successClient('session-debug-902', 902)
        );

        self::assertTrue(
            $coordinator->run($attemptId, mt_uni_credit_valid_shop_snapshot(), $submission, 902, 99002)->isCreated()
        );

        $log = (new DiagnosticDebugLogRepository($db))->findLatestByOrderId($submission->storeId, 902);
        self::assertNotNull($log);
        self::assertSame('success', $log['event_code']);
        self::assertSame(200, $log['http_code']);
        self::assertSame('902', (string) ($log['request']['orderNo'] ?? ''));
        self::assertSame('session-debug-902', (string) ($log['response']['sucfOnlineSessionID'] ?? ''));
    }

    public function testKnownRejectionPersistsDiagnosticJournal(): void
    {
        [$coordinator, $attemptId, $submission, $db] = $this->coordinatorHarness(
            debugEnabled: true,
            client: new class {
                public function createSession(): array
                {
                    throw new SmartUcfSessionException(
                        'Rejected',
                        false,
                        '{"error":"scheme rejected"}',
                        422,
                        SmartUcfSessionException::KIND_REMOTE
                    );
                }
            },
            orderId: 903
        );

        self::assertTrue(
            $coordinator->run($attemptId, mt_uni_credit_valid_shop_snapshot(), $submission, 903, 99003)->isFailed()
        );

        $log = (new DiagnosticDebugLogRepository($db))->findLatestByOrderId($submission->storeId, 903);
        self::assertNotNull($log);
        self::assertSame('remote_reject', $log['event_code']);
        self::assertSame(422, $log['http_code']);
        self::assertSame('scheme rejected', (string) ($log['response']['error'] ?? ''));
    }

    public function testTimeoutPreservesOutcomeUnknownClassificationInJournal(): void
    {
        [$coordinator, $attemptId, $submission, $db] = $this->coordinatorHarness(
            debugEnabled: true,
            client: new class {
                public function createSession(): array
                {
                    throw new SmartUcfSessionException(
                        'SmartUCF connection failed: timeout',
                        false,
                        '',
                        0,
                        SmartUcfSessionException::KIND_TRANSPORT
                    );
                }
            },
            orderId: 904
        );

        self::assertTrue(
            $coordinator->run($attemptId, mt_uni_credit_valid_shop_snapshot(), $submission, 904, 99004)->isOutcomeUnknown()
        );

        $log = (new DiagnosticDebugLogRepository($db))->findLatestByOrderId($submission->storeId, 904);
        self::assertNotNull($log);
        self::assertSame('transport_ambiguous', $log['event_code']);
        self::assertSame('transport_ambiguous', $log['outcome']);
        self::assertNotNull($log['transport_error']);
        self::assertNull($log['response']);
        $row = (new SmartUcfLifecycleRepository($db))->findByAttempt($attemptId);
        self::assertSame(SmartUcfLifecycleStates::OUTCOME_UNKNOWN, $row['smartucf_state']);
    }

    public function testSensitiveValuesAreRedactedInJournal(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required.');
        }

        PersistenceIntegrationHarness::resetTables();
        $repo = new DiagnosticDebugLogRepository(PersistenceIntegrationHarness::connection());
        $journal = new SmartUcfDiagnosticJournal($repo, static fn(int $storeId): bool => true);
        $journal->recordSmartUcfSession(
            PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT,
            905,
            OperationEntryPoint::PRODUCT,
            'https://online.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
            [
                'user' => 'merchant',
                'pass' => 'secret-pass',
                'clientEmail' => 'person@example.com',
                'Authorization' => 'Bearer abc.def.ghi',
            ],
            '{"access_token":"leaked-token","ok":true}',
            200,
            'token=transport-secret',
            'success'
        );

        $log = $repo->findLatestByOrderId(PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT, 905);
        self::assertNotNull($log);
        $encoded = json_encode($log, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['merchant', 'secret-pass', 'person@example.com', 'abc.def', 'leaked-token', 'transport-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
        self::assertStringContainsString('[REDACTED]', $encoded);
    }

    public function testDiagnosticRedactionPolicyMatchesExpandedKeys(): void
    {
        $redacted = DiagnosticPayloadRedactor::redact([
            'private_key_pem' => '-----BEGIN PRIVATE KEY-----',
            'cp_secret' => 'x',
            'encryption_key' => 'y',
            'clientfirstname' => 'Ivan',
        ]);
        self::assertSame('[REDACTED]', $redacted['private_key_pem']);
        self::assertSame('[REDACTED]', $redacted['cp_secret']);
        self::assertSame('[REDACTED]', $redacted['encryption_key']);
        self::assertSame('[REDACTED]', $redacted['clientfirstname']);
        self::assertStringContainsString('Bearer [REDACTED]', DiagnosticPayloadRedactor::redactText('Authorization: Bearer abc.def-token'));
    }

    public function testAdminDownloadContractIsWired(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/admin/controller/module/mt_uni_credit.php');
        $twig = (string) file_get_contents(dirname(__DIR__) . '/admin/view/template/module/mt_uni_credit.twig');

        self::assertStringContainsString('function downloadJournal', $controller);
        self::assertStringContainsString('hasPermission(\'modify\'', $controller);
        self::assertStringContainsString('Content-Disposition: attachment', $controller);
        self::assertStringContainsString('unipayment-smartucf-log-', $controller);
        self::assertStringContainsString('download_journal', $twig);
        self::assertStringNotContainsString('help_journal_unavailable', $twig);
        self::assertStringNotContainsString('type="button" class="btn btn-outline-secondary" disabled', $twig);
    }

    public function testCpDebugEndpointReturnsDiagnosticsForExistingRow(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required.');
        }

        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $storeId = PersistenceIntegrationHarness::TEST_STORE_ID;

        $journal = new SmartUcfDiagnosticJournal(new DiagnosticDebugLogRepository($db), static fn(): bool => true);
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempts = new FinancingAttemptRepository($db);
        $row = $attempts->issueWithSubmissionToken(
            $storeId,
            OperationEntryPoint::PRODUCT,
            hash('sha256', 'debug-journal-cp'),
            hash('sha256', 'actor-debug-journal'),
            hash('sha256', 'selection-debug-journal')
        );
        $orderId = 906;
        $attempts->attachOrder((int) $row['attempt_id'], $orderId);

        $journal->recordSmartUcfSession(
            $storeId,
            $orderId,
            OperationEntryPoint::PRODUCT,
            'https://online.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
            ['orderNo' => (string) $orderId],
            ['sucfOnlineSessionID' => 'cp-visible-session'],
            200,
            null,
            'success'
        );

        $log = (new DiagnosticDebugLogRepository($db))->findLatestByOrderId($storeId, $orderId);
        self::assertNotNull($log);
        self::assertSame('cp-visible-session', (string) ($log['response']['sucfOnlineSessionID'] ?? ''));
    }

    public function testCpLookupUsesShopOrderIdNotCpInternalId(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required.');
        }

        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $storeId = PersistenceIntegrationHarness::TEST_STORE_ID;
        $shopOrderId = 907;
        $cpInternalId = 888807;

        $journal = new SmartUcfDiagnosticJournal(new DiagnosticDebugLogRepository($db), static fn(): bool => true);
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempts = new FinancingAttemptRepository($db);
        $row = $attempts->issueWithSubmissionToken(
            $storeId,
            OperationEntryPoint::CHECKOUT,
            hash('sha256', 'debug-id-parity'),
            hash('sha256', 'actor-id-parity'),
            hash('sha256', 'selection-id-parity')
        );
        $attempts->attachOrder((int) $row['attempt_id'], $shopOrderId);
        $journal->recordSmartUcfSession(
            $storeId,
            $shopOrderId,
            OperationEntryPoint::CHECKOUT,
            'https://online.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
            ['orderNo' => (string) $shopOrderId],
            ['sucfOnlineSessionID' => 'parity-session'],
            200,
            null,
            'success'
        );

        self::assertNull((new DiagnosticDebugLogRepository($db))->findLatestByOrderId($storeId, $cpInternalId));
        self::assertNotNull((new DiagnosticDebugLogRepository($db))->findLatestByOrderId($storeId, $shopOrderId));
        unset($submission, $cpInternalId);
    }

    public function testWrongStoreCannotReadDiagnosticRow(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required.');
        }

        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $journal = new SmartUcfDiagnosticJournal(new DiagnosticDebugLogRepository($db), static fn(): bool => true);
        $journal->recordSmartUcfSession(
            PersistenceIntegrationHarness::TEST_STORE_ID_DEFAULT,
            908,
            OperationEntryPoint::PRODUCT,
            'https://online.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
            ['orderNo' => '908'],
            ['ok' => true],
            200,
            null,
            'success'
        );

        self::assertNull(
            (new DiagnosticDebugLogRepository($db))->findLatestByOrderId(PersistenceIntegrationHarness::TEST_STORE_ID_ONE, 908)
        );
    }

    public function testJournalPersistenceFailureDoesNotBreakSmartUcfSuccess(): void
    {
        $repo = new class(PersistenceIntegrationHarness::connection()) extends DiagnosticDebugLogRepository {
            public function insert(
                int $storeId,
                int $orderId,
                string $entryPoint,
                string $eventCode,
                int $httpStatus,
                array $summary
            ): bool {
                throw new \RuntimeException('journal storage failed');
            }
        };

        [$coordinator, $attemptId, $submission] = $this->coordinatorHarness(
            debugEnabled: true,
            client: $this->successClient('session-despite-journal-fail', 909),
            orderId: 909,
            journal: new SmartUcfDiagnosticJournal($repo, static fn(): bool => true)
        );

        self::assertTrue(
            $coordinator->run($attemptId, mt_uni_credit_valid_shop_snapshot(), $submission, 909, 99009)->isCreated()
        );
    }

    public function testRetentionCutoffIsThreeMonths(): void
    {
        $cutoff = DiagnosticDebugLogRepository::retentionCutoff(
            new \DateTimeImmutable('2026-08-18 12:00:00', new \DateTimeZone('UTC'))
        );
        self::assertSame('2026-05-18 12:00:00', $cutoff);
    }

    public function testVersionRemainsFrozen(): void
    {
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    private function successClient(string $sessionId = 'session-debug-902', int $orderId = 902): object
    {
        return new class($sessionId, $orderId) {
            public function __construct(private string $sessionId, private int $orderId) {}

            /** @return array<string, mixed> */
            public function createSession(): array
            {
                return [
                    'session_id' => $this->sessionId,
                    'redirect_url' => 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/' . $this->sessionId,
                    'http_code' => 200,
                    'raw_request' => json_encode(['orderNo' => (string) $this->orderId, 'onlineProductCode' => 'KOP'], JSON_THROW_ON_ERROR),
                    'raw_response' => json_encode(['sucfOnlineSessionID' => $this->sessionId], JSON_THROW_ON_ERROR),
                    'endpoint' => 'https://online.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
                ];
            }
        };
    }

    /**
     * @return array{SmartUcfSessionCoordinator, int, object, object}
     */
    private function coordinatorHarness(
        bool $debugEnabled,
        object $client,
        int $orderId = 902,
        ?SmartUcfDiagnosticJournal $journal = null
    ): array {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required.');
        }

        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $storeId = PersistenceIntegrationHarness::TEST_STORE_ID;
        $memorySettings = new InMemoryModuleSettingStore();
        $memorySettings->set($storeId, ModuleLocalSettings::DEBUG_ENABLED, $debugEnabled ? '1' : '0');

        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempts = new FinancingAttemptRepository($db);
        $row = $attempts->issueWithSubmissionToken(
            $storeId,
            OperationEntryPoint::PRODUCT,
            hash('sha256', 'debug-journal-' . $orderId),
            hash('sha256', 'actor-debug-' . $orderId),
            hash('sha256', 'selection-debug-' . $orderId)
        );
        $attempts->attachOrder((int) $row['attempt_id'], $orderId);

        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate();
        $services = Phase4TestHarness::services($transport, null, $db, $storeId);

        $journal ??= new SmartUcfDiagnosticJournal(
            new DiagnosticDebugLogRepository($db),
            static function (int $sid) use ($memorySettings, $storeId): bool {
                if ($sid !== $storeId) {
                    return false;
                }

                return ModuleLocalSettings::normalizeFlag(
                    $memorySettings->get($sid, ModuleLocalSettings::DEBUG_ENABLED) ?? '0'
                ) === 1;
            }
        );

        return [
            new SmartUcfSessionCoordinator(
                new SmartUcfLifecycleRepository($db),
                $client,
                new SmartUcfFailureClassifier(),
                new OrderBankStatusRepository($db),
                $services['client'],
                null,
                $journal
            ),
            (int) $row['attempt_id'],
            $submission,
            $db,
        ];
    }
}
