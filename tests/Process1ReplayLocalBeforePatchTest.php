<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use MtUniCredit\Tests\Support\RecordingStatusPort;
use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelClient;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelStatusSyncRepository;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelStatusSyncService;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelStatusSyncStates;
use Opencart\System\Library\Extension\MtUniCredit\CpTokenRepository;
use Opencart\System\Library\Extension\MtUniCredit\DbConnection;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\OrderBankStatusRepository;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceTableNames;
use Opencart\System\Library\Extension\MtUniCredit\ProcessTwoLifecycleStates;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfFailureClassifier;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleRepository;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleStates;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfSessionCoordinator;
use PHPUnit\Framework\TestCase;

/**
 * P1 replay authority + production-wired post-SmartUCF missing-target recovery.
 */
final class Process1ReplayLocalBeforePatchTest extends TestCase
{
    public function testReplayRestoresMissingLocalFactBeforePatch(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(1, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'order_id' => 9001,
            'sync_state' => ControlPanelStatusSyncStates::PENDING,
            'status_id' => BankStatus::SENT_PROCESS1,
            'status' => BankStatus::LABEL_SENT_PROCESS1,
        ]);
        $port = new RecordingStatusPort();
        $smartUcf = $this->blockingSmartUcf();
        $coordinator = $this->coordinator($db, $port, $smartUcf);
        $submission = OrderMaterializationTestHarness::productSubmission();

        $result = $coordinator->run(1, mt_uni_credit_valid_shop_snapshot(), $submission, 9001, 501);
        self::assertTrue($result->isCreated());
        self::assertSame(0, $smartUcf->calls);
        self::assertSame(1, $port->calls);
        $local = (new OrderBankStatusRepository($db))->findCurrentStatus($submission->storeId, 9001);
        self::assertNotNull($local);
        self::assertSame(BankStatus::SENT_PROCESS1, $local['status_id']);
        self::assertSame(ControlPanelStatusSyncStates::CONFIRMED, $db->attempts[1]['cp_status_sync_state']);
    }

    public function testLocalWriteFailureDoesNotPatchAndLaterReplaySucceeds(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(2, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'order_id' => 9002,
            'sync_state' => ControlPanelStatusSyncStates::PENDING,
            'status_id' => BankStatus::SENT_PROCESS1,
            'status' => BankStatus::LABEL_SENT_PROCESS1,
        ]);
        $db->failNextBankStatusWrite = true;
        $port = new RecordingStatusPort();
        $smartUcf = $this->blockingSmartUcf();
        $coordinator = $this->coordinator($db, $port, $smartUcf);
        $submission = OrderMaterializationTestHarness::productSubmission();

        $first = $coordinator->run(2, mt_uni_credit_valid_shop_snapshot(), $submission, 9002, 502);
        self::assertTrue($first->isCreated());
        self::assertSame(0, $port->calls);

        $db->failNextBankStatusWrite = false;
        $second = $coordinator->run(2, mt_uni_credit_valid_shop_snapshot(), $submission, 9002, 502);
        self::assertTrue($second->isCreated());
        self::assertSame(1, $port->calls);
        self::assertSame(0, $smartUcf->calls);
    }

    public function testConfirmedTargetRestoresLocalWithoutSecondPatch(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(4, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'order_id' => 9004,
            'sync_state' => ControlPanelStatusSyncStates::CONFIRMED,
            'status_id' => BankStatus::SENT_PROCESS1,
            'status' => BankStatus::LABEL_SENT_PROCESS1,
        ]);
        $port = new RecordingStatusPort();
        $coordinator = $this->coordinator($db, $port, $this->blockingSmartUcf());
        $submission = OrderMaterializationTestHarness::productSubmission();

        $result = $coordinator->run(4, mt_uni_credit_valid_shop_snapshot(), $submission, 9004, 504);
        self::assertTrue($result->isCreated());
        self::assertSame(0, $port->calls);
        $local = (new OrderBankStatusRepository($db))->findCurrentStatus($submission->storeId, 9004);
        self::assertNotNull($local);
        self::assertSame(BankStatus::SENT_PROCESS1, $local['status_id']);
    }

    public function testWrongStatusTextDoesNotMutateLocalOrPatch(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(6, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'order_id' => 9006,
            'sync_state' => ControlPanelStatusSyncStates::PENDING,
            'status_id' => BankStatus::SENT_PROCESS1,
            'status' => 'Wrong Process 1 Label',
        ]);
        $port = new RecordingStatusPort();
        $coordinator = $this->coordinator($db, $port, $this->blockingSmartUcf());
        $submission = OrderMaterializationTestHarness::productSubmission();

        $result = $coordinator->run(6, mt_uni_credit_valid_shop_snapshot(), $submission, 9006, 506);
        self::assertTrue($result->isCreated());
        self::assertSame(0, $port->calls);
        self::assertNull((new OrderBankStatusRepository($db))->findCurrentStatus($submission->storeId, 9006));
    }

    public function testTerminalFailedDoesNotRestoreLocalOrPatch(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(8, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'order_id' => 9008,
            'sync_state' => ControlPanelStatusSyncStates::TERMINAL_FAILED,
            'status_id' => BankStatus::SENT_PROCESS1,
            'status' => BankStatus::LABEL_SENT_PROCESS1,
            'error_class' => 'cp_status_semantic_conflict',
        ]);
        $port = new RecordingStatusPort();
        $coordinator = $this->coordinator($db, $port, $this->blockingSmartUcf());
        $submission = OrderMaterializationTestHarness::productSubmission();

        $result = $coordinator->run(8, mt_uni_credit_valid_shop_snapshot(), $submission, 9008, 508);
        self::assertTrue($result->isCreated());
        self::assertSame(0, $port->calls);
        self::assertNull((new OrderBankStatusRepository($db))->findCurrentStatus($submission->storeId, 9008));
    }

    public function testProductionReplayRecoversMissingTargetWhenIdentityValid(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(20, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'order_id' => 9020,
            'sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
            'status_id' => null,
            'status' => null,
        ]);
        $port = new RecordingStatusPort();
        $smartUcf = $this->blockingSmartUcf();
        $coordinator = $this->coordinator($db, $port, $smartUcf);
        $submission = OrderMaterializationTestHarness::productSubmission();

        // Production entry: coordinator->run(), not direct recovery helper.
        $result = $coordinator->run(20, mt_uni_credit_valid_shop_snapshot(), $submission, 9020, 520);
        self::assertTrue($result->isCreated());
        self::assertSame(0, $smartUcf->calls);
        self::assertSame(1, $port->calls);
        $local = (new OrderBankStatusRepository($db))->findCurrentStatus($submission->storeId, 9020);
        self::assertNotNull($local);
        self::assertSame(BankStatus::SENT_PROCESS1, $local['status_id']);
        self::assertSame(BankStatus::SENT_PROCESS1, $db->attempts[20]['cp_status_sync_status_id']);
        self::assertSame(BankStatus::LABEL_SENT_PROCESS1, $db->attempts[20]['cp_status_sync_status']);
        self::assertSame(ControlPanelStatusSyncStates::CONFIRMED, $db->attempts[20]['cp_status_sync_state']);
    }

    public function testWrongOrderBlocksRecoveryViaProductionReplay(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(21, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'order_id' => 123,
            'sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
            'status_id' => null,
            'status' => null,
        ]);
        $port = new RecordingStatusPort();
        $coordinator = $this->coordinator($db, $port, $this->blockingSmartUcf());
        $submission = OrderMaterializationTestHarness::productSubmission();

        $result = $coordinator->run(21, mt_uni_credit_valid_shop_snapshot(), $submission, 124, 521);
        self::assertTrue($result->isCreated());
        self::assertSame(0, $port->calls);
        self::assertSame(ControlPanelStatusSyncStates::NOT_NEEDED, $db->attempts[21]['cp_status_sync_state']);
        self::assertNull($db->attempts[21]['cp_status_sync_status_id']);
        self::assertNull((new OrderBankStatusRepository($db))->findCurrentStatus($submission->storeId, 124));
    }

    public function testNullUnicidBlocksRecoveryViaProductionReplay(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(22, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'order_id' => 9022,
            'unicid' => null,
            'sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
            'status_id' => null,
            'status' => null,
        ]);
        $port = new RecordingStatusPort();
        $coordinator = $this->coordinator($db, $port, $this->blockingSmartUcf());
        $submission = OrderMaterializationTestHarness::productSubmission();

        $result = $coordinator->run(22, mt_uni_credit_valid_shop_snapshot(), $submission, 9022, 522);
        self::assertTrue($result->isCreated());
        self::assertSame(0, $port->calls);
        self::assertSame(ControlPanelStatusSyncStates::NOT_NEEDED, $db->attempts[22]['cp_status_sync_state']);
    }

    public function testEmptyUnicidBlocksRecoveryViaProductionReplay(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(23, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'order_id' => 9023,
            'unicid' => '',
            'sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
            'status_id' => null,
            'status' => null,
        ]);
        $port = new RecordingStatusPort();
        $coordinator = $this->coordinator($db, $port, $this->blockingSmartUcf());
        $submission = OrderMaterializationTestHarness::productSubmission();

        $result = $coordinator->run(23, mt_uni_credit_valid_shop_snapshot(), $submission, 9023, 523);
        self::assertTrue($result->isCreated());
        self::assertSame(0, $port->calls);
        self::assertSame(ControlPanelStatusSyncStates::NOT_NEEDED, $db->attempts[23]['cp_status_sync_state']);
    }

    public function testWrongUnicidBlocksRecoveryViaProductionReplay(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(24, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'order_id' => 9024,
            'unicid' => 'other-shop-unicid',
            'sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
            'status_id' => null,
            'status' => null,
        ]);
        $port = new RecordingStatusPort();
        $coordinator = $this->coordinator($db, $port, $this->blockingSmartUcf());
        $submission = OrderMaterializationTestHarness::productSubmission();

        $result = $coordinator->run(24, mt_uni_credit_valid_shop_snapshot(), $submission, 9024, 524);
        self::assertTrue($result->isCreated());
        self::assertSame(0, $port->calls);
        self::assertSame(ControlPanelStatusSyncStates::NOT_NEEDED, $db->attempts[24]['cp_status_sync_state']);
    }

    public function testLocalProcess2BlocksRecoveryViaProductionReplay(): void
    {
        $db = new Process1ReplayFakeDb();
        $storeId = OrderMaterializationTestHarness::productSubmission()->storeId;
        $db->seedAttempt(25, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'order_id' => 9025,
            'sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
            'status_id' => null,
            'status' => null,
        ]);
        $db->bankStatuses[$storeId . ':9025'] = [
            'store_id' => $storeId,
            'order_id' => 9025,
            'status_id' => BankStatus::SENT_PROCESS2,
            'status_label' => BankStatus::LABEL_SENT_PROCESS2,
        ];
        $port = new RecordingStatusPort();
        $coordinator = $this->coordinator($db, $port, $this->blockingSmartUcf());
        $submission = OrderMaterializationTestHarness::productSubmission();

        $result = $coordinator->run(25, mt_uni_credit_valid_shop_snapshot(), $submission, 9025, 525);
        self::assertTrue($result->isCreated());
        self::assertSame(0, $port->calls);
        self::assertSame(ControlPanelStatusSyncStates::NOT_NEEDED, $db->attempts[25]['cp_status_sync_state']);
        $local = (new OrderBankStatusRepository($db))->findCurrentStatus($submission->storeId, 9025);
        self::assertSame(BankStatus::SENT_PROCESS2, $local['status_id']);
    }

    public function testOutcomeUnknownDoesNotInvokeRecovery(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(26, [
            'smartucf_state' => SmartUcfLifecycleStates::OUTCOME_UNKNOWN,
            'order_id' => 9026,
            'sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
            'status_id' => null,
            'status' => null,
            'redirect_url' => '',
        ]);
        $port = new RecordingStatusPort();
        $smartUcf = $this->blockingSmartUcf();
        $coordinator = $this->coordinator($db, $port, $smartUcf);
        $submission = OrderMaterializationTestHarness::productSubmission();

        $result = $coordinator->run(26, mt_uni_credit_valid_shop_snapshot(), $submission, 9026, 526);
        self::assertTrue($result->isOutcomeUnknown());
        self::assertSame(0, $smartUcf->calls);
        self::assertSame(0, $port->calls);
        self::assertSame(ControlPanelStatusSyncStates::NOT_NEEDED, $db->attempts[26]['cp_status_sync_state']);
    }

    public function testPendingTargetUsesOrdinaryReplayNotRecoveryReAdmission(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(27, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'order_id' => 9027,
            'sync_state' => ControlPanelStatusSyncStates::PENDING,
            'status_id' => BankStatus::SENT_PROCESS1,
            'status' => BankStatus::LABEL_SENT_PROCESS1,
        ]);
        $port = new RecordingStatusPort();
        $coordinator = $this->coordinator($db, $port, $this->blockingSmartUcf());
        $submission = OrderMaterializationTestHarness::productSubmission();

        $result = $coordinator->run(27, mt_uni_credit_valid_shop_snapshot(), $submission, 9027, 527);
        self::assertTrue($result->isCreated());
        self::assertSame(1, $port->calls);
        // Target was already pending — ordinary replay PATCHes; no re-admission from not_needed.
        self::assertSame(BankStatus::SENT_PROCESS1, $db->attempts[27]['cp_status_sync_status_id']);
    }

    public function testWrongStoreBlocksRecoveryViaProductionReplay(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(30, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'store_id' => PersistenceIntegrationHarness::TEST_STORE_ID,
            'order_id' => 9030,
            'sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
            'status_id' => null,
            'status' => null,
        ]);
        $port = new RecordingStatusPort();
        $smartUcf = $this->blockingSmartUcf();
        $transport = new FakeCpHttpTransport();
        $coordinator = $this->coordinator($db, $port, $smartUcf, $transport);
        $submission = OrderMaterializationTestHarness::productSubmission();
        $submission->storeId = PersistenceIntegrationHarness::TEST_STORE_ID_B;
        self::assertNotSame($db->attempts[30]['store_id'], $submission->storeId);

        $createsBefore = $transport->countOrderCreates();
        $result = $coordinator->run(30, mt_uni_credit_valid_shop_snapshot(), $submission, 9030, 530);
        self::assertTrue($result->isCreated());
        self::assertSame(0, $smartUcf->calls);
        self::assertSame(0, $port->calls);
        self::assertSame($createsBefore, $transport->countOrderCreates());
        self::assertSame(ControlPanelStatusSyncStates::NOT_NEEDED, $db->attempts[30]['cp_status_sync_state']);
        self::assertNull($db->attempts[30]['cp_status_sync_status_id']);
        self::assertNull((new OrderBankStatusRepository($db))->findCurrentStatus(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            9030
        ));
        self::assertNull((new OrderBankStatusRepository($db))->findCurrentStatus(
            PersistenceIntegrationHarness::TEST_STORE_ID_B,
            9030
        ));
    }

    public function testProgressedProcess2StateBlocksRecoveryViaProductionReplay(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(31, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'order_id' => 9031,
            'sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
            'status_id' => null,
            'status' => null,
            'process2_state' => ProcessTwoLifecycleStates::PREPARING,
        ]);
        $port = new RecordingStatusPort();
        $smartUcf = $this->blockingSmartUcf();
        $transport = new FakeCpHttpTransport();
        $coordinator = $this->coordinator($db, $port, $smartUcf, $transport);
        $submission = OrderMaterializationTestHarness::productSubmission();

        $createsBefore = $transport->countOrderCreates();
        $result = $coordinator->run(31, mt_uni_credit_valid_shop_snapshot(), $submission, 9031, 531);
        self::assertTrue($result->isCreated());
        self::assertSame(0, $smartUcf->calls);
        self::assertSame(0, $port->calls);
        self::assertSame($createsBefore, $transport->countOrderCreates());
        self::assertSame(ProcessTwoLifecycleStates::PREPARING, $db->attempts[31]['process2_state']);
        self::assertSame(ControlPanelStatusSyncStates::NOT_NEEDED, $db->attempts[31]['cp_status_sync_state']);
        self::assertNull($db->attempts[31]['cp_status_sync_status_id']);
        self::assertNull((new OrderBankStatusRepository($db))->findCurrentStatus($submission->storeId, 9031));
    }

    public function testRecoveryLocalWriteFailureLeavesPendingAndLaterReplayPatches(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(32, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'order_id' => 9032,
            'sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
            'status_id' => null,
            'status' => null,
        ]);
        $db->failNextBankStatusWrite = true;
        $port = new RecordingStatusPort();
        $smartUcf = $this->blockingSmartUcf();
        $transport = new FakeCpHttpTransport();
        $coordinator = $this->coordinator($db, $port, $smartUcf, $transport);
        $submission = OrderMaterializationTestHarness::productSubmission();

        $createsBefore = $transport->countOrderCreates();
        $first = $coordinator->run(32, mt_uni_credit_valid_shop_snapshot(), $submission, 9032, 532);
        self::assertTrue($first->isCreated());
        self::assertSame(0, $port->calls);
        self::assertSame(0, $smartUcf->calls);
        self::assertSame($createsBefore, $transport->countOrderCreates());
        self::assertSame(ControlPanelStatusSyncStates::PENDING, $db->attempts[32]['cp_status_sync_state']);
        self::assertSame(BankStatus::SENT_PROCESS1, $db->attempts[32]['cp_status_sync_status_id']);
        self::assertSame(BankStatus::LABEL_SENT_PROCESS1, $db->attempts[32]['cp_status_sync_status']);
        self::assertNull((new OrderBankStatusRepository($db))->findCurrentStatus($submission->storeId, 9032));

        $db->failNextBankStatusWrite = false;
        $createsMid = $transport->countOrderCreates();
        $second = $coordinator->run(32, mt_uni_credit_valid_shop_snapshot(), $submission, 9032, 532);
        self::assertTrue($second->isCreated());
        self::assertSame(1, $port->calls);
        self::assertSame(0, $smartUcf->calls);
        self::assertSame($createsMid, $transport->countOrderCreates());
        $local = (new OrderBankStatusRepository($db))->findCurrentStatus($submission->storeId, 9032);
        self::assertNotNull($local);
        self::assertSame(BankStatus::SENT_PROCESS1, $local['status_id']);
        self::assertSame(ControlPanelStatusSyncStates::CONFIRMED, $db->attempts[32]['cp_status_sync_state']);
    }

    public function testRecoveryReplayDoesNotCreateSecondCpOrder(): void
    {
        $db = new Process1ReplayFakeDb();
        $db->seedAttempt(33, [
            'smartucf_state' => SmartUcfLifecycleStates::CREATED,
            'order_id' => 9033,
            'sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
            'status_id' => null,
            'status' => null,
        ]);
        $port = new RecordingStatusPort();
        $smartUcf = $this->blockingSmartUcf();
        $transport = new FakeCpHttpTransport();
        $coordinator = $this->coordinator($db, $port, $smartUcf, $transport);
        $submission = OrderMaterializationTestHarness::productSubmission();

        // CP order already exists conceptually; transport starts with zero POST /orders.
        $createsBefore = $transport->countOrderCreates();
        self::assertSame(0, $createsBefore);

        $result = $coordinator->run(33, mt_uni_credit_valid_shop_snapshot(), $submission, 9033, 533);
        self::assertTrue($result->isCreated());
        self::assertSame(0, $smartUcf->calls);
        self::assertSame($createsBefore, $transport->countOrderCreates());
        self::assertSame(0, $transport->countOrderCreates());
        self::assertSame(1, $port->calls);
        self::assertSame(BankStatus::SENT_PROCESS1, $db->attempts[33]['cp_status_sync_status_id']);
        self::assertSame(ControlPanelStatusSyncStates::CONFIRMED, $db->attempts[33]['cp_status_sync_state']);
        $local = (new OrderBankStatusRepository($db))->findCurrentStatus($submission->storeId, 9033);
        self::assertNotNull($local);
        self::assertSame(BankStatus::SENT_PROCESS1, $local['status_id']);
    }

    private function blockingSmartUcf(): object
    {
        return new class {
            public int $calls = 0;

            public function createSession(): array
            {
                $this->calls++;
                throw new \LogicException('Replay must not create a second SmartUCF session.');
            }
        };
    }

    private function coordinator(
        Process1ReplayFakeDb $db,
        RecordingStatusPort $port,
        object $smartUcfClient,
        ?FakeCpHttpTransport $transport = null
    ): SmartUcfSessionCoordinator {
        $settings = Phase4TestHarness::settings();
        Phase4TestHarness::prepareCredentials($settings);
        $cipher = Phase4TestHarness::cipher();
        $transport ??= new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate(901);
        $cp = new ControlPanelClient(
            new ModuleCredentialsRepository($settings, $cipher),
            new CpTokenRepository($settings, $cipher, Phase4TestHarness::TEST_STORE_ID),
            $transport,
            Phase4TestHarness::TEST_SHOP_URL,
            Phase4TestHarness::TEST_STORE_ID,
            'https://cp.example.test/api/v1'
        );

        return new SmartUcfSessionCoordinator(
            new SmartUcfLifecycleRepository($db),
            $smartUcfClient,
            new SmartUcfFailureClassifier(),
            new OrderBankStatusRepository($db),
            $cp,
            new ControlPanelStatusSyncService(new ControlPanelStatusSyncRepository($db), $port)
        );
    }
}

/**
 * Minimal SQL-shaped fake covering SmartUCF lifecycle + bank status + CP sync CAS.
 */
final class Process1ReplayFakeDb implements DbConnection
{
    /** @var array<int, array<string, mixed>> */
    public array $attempts = [];

    /** @var array<string, array<string, mixed>> */
    public array $bankStatuses = [];

    public bool $failNextBankStatusWrite = false;

    private int $affected = 0;

    /**
     * @param array{
     *     smartucf_state?: string,
     *     store_id?: int,
     *     order_id?: int|string|null,
     *     unicid?: ?string,
     *     sync_state?: string,
     *     status_id?: ?string,
     *     status?: ?string,
     *     error_class?: ?string,
     *     redirect_url?: string,
     *     process2_state?: string
     * } $overrides
     */
    public function seedAttempt(int $attemptId, array $overrides = []): void
    {
        $statusText = $overrides['status'] ?? null;
        if (is_string($statusText) && $statusText === '') {
            $statusText = null;
        }
        $this->attempts[$attemptId] = [
            'attempt_id' => $attemptId,
            'store_id' => $overrides['store_id']
                ?? OrderMaterializationTestHarness::productSubmission()->storeId,
            'order_id' => $overrides['order_id'] ?? $attemptId,
            'unicid' => array_key_exists('unicid', $overrides)
                ? $overrides['unicid']
                : 'test-unicid',
            'smartucf_state' => $overrides['smartucf_state'] ?? SmartUcfLifecycleStates::CREATED,
            'smartucf_session_id' => 'sess-' . $attemptId,
            'smartucf_redirect_url' => $overrides['redirect_url']
                ?? ('https://onlinetest.ucfin.bg/sucf-online/Request/Start/sess-' . $attemptId),
            'smartucf_http_code' => 200,
            'smartucf_error_class' => null,
            'smartucf_retryable' => 0,
            'smartucf_claimed_at' => '2026-01-01 00:00:00',
            'smartucf_completed_at' => '2026-01-01 00:00:01',
            'process2_state' => $overrides['process2_state'] ?? ProcessTwoLifecycleStates::NOT_STARTED,
            'cp_status_sync_state' => $overrides['sync_state'] ?? ControlPanelStatusSyncStates::NOT_NEEDED,
            'cp_status_sync_status_id' => $overrides['status_id'] ?? null,
            'cp_status_sync_status' => $statusText,
            'cp_status_sync_error_class' => $overrides['error_class'] ?? null,
            'cp_status_sync_updated_at' => '2026-01-01 00:00:00',
        ];
    }

    public function query(string $sql): object
    {
        if (preg_match('/FROM `[^`]+' . preg_quote(PersistenceTableNames::FINANCING_ATTEMPT, '/') . '`[\s\S]*WHERE `attempt_id` = (\d+)/', $sql, $m)
            && preg_match('/^\s*SELECT/i', $sql)
        ) {
            $row = $this->attempts[(int) $m[1]] ?? null;

            return $this->result($row === null ? [] : [$row]);
        }

        if (str_contains($sql, 'UPDATE') && str_contains($sql, PersistenceTableNames::FINANCING_ATTEMPT)) {
            if (!preg_match('/WHERE `attempt_id` = (\d+)/', $sql, $m)) {
                return $this->result([]);
            }
            $id = (int) $m[1];
            if (!isset($this->attempts[$id])) {
                $this->affected = 0;

                return $this->result([]);
            }

            if (preg_match(
                "/SET[\s\S]*`cp_status_sync_state` = '" . preg_quote(ControlPanelStatusSyncStates::CONFIRMED, '/') . "'/",
                $sql
            )) {
                if (($this->attempts[$id]['cp_status_sync_state'] ?? '') !== ControlPanelStatusSyncStates::PENDING) {
                    $this->affected = 0;

                    return $this->result([]);
                }
                $this->attempts[$id]['cp_status_sync_state'] = ControlPanelStatusSyncStates::CONFIRMED;
                $this->affected = 1;

                return $this->result([]);
            }

            if (preg_match(
                "/SET[\s\S]*`cp_status_sync_state` = '" . preg_quote(ControlPanelStatusSyncStates::PENDING, '/') . "'/",
                $sql
            )) {
                if (($this->attempts[$id]['cp_status_sync_state'] ?? '') !== ControlPanelStatusSyncStates::NOT_NEEDED) {
                    $this->affected = 0;

                    return $this->result([]);
                }
                $this->attempts[$id]['cp_status_sync_state'] = ControlPanelStatusSyncStates::PENDING;
                $this->attempts[$id]['cp_status_sync_status_id'] = BankStatus::SENT_PROCESS1;
                $this->attempts[$id]['cp_status_sync_status'] = BankStatus::LABEL_SENT_PROCESS1;
                $this->attempts[$id]['cp_status_sync_error_class'] = null;
                $this->affected = 1;

                return $this->result([]);
            }
            $this->affected = 0;

            return $this->result([]);
        }

        if (str_contains($sql, 'INSERT INTO') && str_contains($sql, PersistenceTableNames::ORDER_BANK_STATUS)) {
            if ($this->failNextBankStatusWrite) {
                throw new \RuntimeException('Forced local bank status write failure.');
            }
            if (!preg_match(
                "/VALUES \(\s*(\d+)\s*,\s*(\d+)\s*,\s*'[^']*'\s*,\s*'([^']*)'\s*,\s*'([^']*)'\s*,/s",
                $sql,
                $m
            )) {
                throw new \RuntimeException('Unable to parse bank status insert.');
            }
            $storeId = (int) $m[1];
            $orderId = (int) $m[2];
            $statusId = (string) $m[3];
            $statusLabel = (string) $m[4];
            $key = $storeId . ':' . $orderId;
            $existing = $this->bankStatuses[$key] ?? null;
            if ($existing !== null) {
                $existingId = (string) ($existing['status_id'] ?? '');
                if (
                    ($existingId === BankStatus::SENT_PROCESS1 && $statusId === BankStatus::SENT_PROCESS2)
                    || ($existingId === BankStatus::SENT_PROCESS2 && $statusId === BankStatus::SENT_PROCESS1)
                ) {
                    $this->affected = 0;

                    return $this->result([]);
                }
            }
            $this->bankStatuses[$key] = [
                'store_id' => $storeId,
                'order_id' => $orderId,
                'status_id' => $statusId,
                'status_label' => $statusLabel,
            ];
            $this->affected = 1;

            return $this->result([]);
        }

        if (str_contains($sql, PersistenceTableNames::ORDER_BANK_STATUS) && preg_match('/^\s*SELECT/i', $sql)) {
            if (!preg_match('/store_id` = (\d+)[\s\S]*order_id` = (\d+)/', $sql, $m)) {
                return $this->result([]);
            }
            $row = $this->bankStatuses[$m[1] . ':' . $m[2]] ?? null;

            return $this->result($row === null ? [] : [$row]);
        }

        return $this->result([]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function result(array $rows): object
    {
        return new class ($rows) {
            public int $num_rows;
            /** @var array<string, mixed> */
            public array $row;
            /** @var list<array<string, mixed>> */
            public array $rows;

            /** @param list<array<string, mixed>> $rows */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
                $this->num_rows = count($rows);
                $this->row = $rows[0] ?? [];
            }
        };
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function countAffected(): int
    {
        return $this->affected;
    }

    public function getLastId(): int
    {
        return 0;
    }

    public function getPrefix(): string
    {
        return 'oc_';
    }
}
