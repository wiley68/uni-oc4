<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\CredentialAtomicFakeDb;
use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use MtUniCredit\Tests\Support\RecordingStatusPort;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelClient;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelStatusSyncRepository;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelStatusSyncService;
use Opencart\System\Library\Extension\MtUniCredit\CpTokenRepository;
use Opencart\System\Library\Extension\MtUniCredit\DbConnection;
use Opencart\System\Library\Extension\MtUniCredit\DiagnosticPayloadRedactor;
use Opencart\System\Library\Extension\MtUniCredit\InMemoryModuleSettingStore;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleSettingCipher;
use Opencart\System\Library\Extension\MtUniCredit\ModuleSettingStore;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartModuleSettingStore;
use Opencart\System\Library\Extension\MtUniCredit\OrderBankStatusRepository;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceTableNames;
use Opencart\System\Library\Extension\MtUniCredit\ShopCacheRepository;
use Opencart\System\Library\Extension\MtUniCredit\ShopSnapshotValidationException;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfCredentialPairClassifier;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfCredentialPersistence;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfCredentialRepository;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfFailureClassifier;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleRepository;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleStates;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfPayloadBuilder;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfSessionCoordinator;
use Opencart\System\Library\Extension\MtUniCredit\ValidatedFinancingSubmission;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/fixtures/cp_shop_snapshot.php';

/**
 * SmartUCF credential hardening — dedicated encrypted storage, pair contract, fail-before-network.
 */
final class SmartUcfCredentialHardeningTest extends TestCase
{
    private const STORE_ID = Phase4TestHarness::TEST_STORE_ID;

    private const UNICID = Phase4TestHarness::TEST_UNICID;

    public function testClassifierProcess1PairStates(): void
    {
        self::assertSame(
            SmartUcfCredentialPairClassifier::COMPLETE,
            SmartUcfCredentialPairClassifier::classify([
                'uni_user' => 'u',
                'uni_password' => 'p',
            ])
        );
        self::assertSame(
            SmartUcfCredentialPairClassifier::ABSENT,
            SmartUcfCredentialPairClassifier::classify(['uni_proces' => 0])
        );
        self::assertSame(
            SmartUcfCredentialPairClassifier::INVALID,
            SmartUcfCredentialPairClassifier::classify(['uni_user' => 'u'])
        );
        self::assertSame(
            SmartUcfCredentialPairClassifier::INVALID,
            SmartUcfCredentialPairClassifier::classify(['uni_password' => 'p'])
        );
        self::assertSame(
            SmartUcfCredentialPairClassifier::INVALID,
            SmartUcfCredentialPairClassifier::classify(['uni_user' => '  ', 'uni_password' => 'p'])
        );
        self::assertSame(
            SmartUcfCredentialPairClassifier::INVALID,
            SmartUcfCredentialPairClassifier::classify(['uni_user' => 'u', 'uni_password' => ''])
        );
        self::assertSame(
            SmartUcfCredentialPairClassifier::INVALID,
            SmartUcfCredentialPairClassifier::classify(['uni_user' => 1, 'uni_password' => 'p'])
        );
    }

    public function testProcess1CompletePairPersistedEncryptedAndStrippedFromCache(): void
    {
        [$settings, $repo, $persistence, $cacheDb] = $this->wiring();
        $snapshot = mt_uni_credit_valid_shop_snapshot([
            'uni_user' => 'demo-user',
            'uni_password' => 'demo-secret-password',
        ]);

        $written = $persistence->persistValidatedSnapshot(self::STORE_ID, self::UNICID, $snapshot);

        self::assertArrayNotHasKey('uni_user', $written);
        self::assertArrayNotHasKey('uni_password', $written);
        self::assertTrue($cacheDb->wroteShopCache);
        self::assertFalse($cacheDb->sqlContainedPlaintextCredential);

        $rawUser = $settings->get(self::STORE_ID, SmartUcfCredentialRepository::USER_SETTING);
        $rawPass = $settings->get(self::STORE_ID, SmartUcfCredentialRepository::PASSWORD_SETTING);
        self::assertIsString($rawUser);
        self::assertIsString($rawPass);
        self::assertStringStartsWith(ModuleSettingCipher::encryptedPrefix(), $rawUser);
        self::assertStringStartsWith(ModuleSettingCipher::encryptedPrefix(), $rawPass);
        self::assertNotSame('demo-user', $rawUser);
        self::assertNotSame('demo-secret-password', $rawPass);
        self::assertSame('demo-user', $repo->getUsername(self::STORE_ID));
        self::assertSame('demo-secret-password', $repo->getPassword(self::STORE_ID));
    }

    public function testProcess1AbsentAndPartialRejectedWithoutMutation(): void
    {
        [, $repo, $persistence] = $this->wiring();
        $repo->saveCompletePair(self::STORE_ID, 'keep-user', 'keep-pass');
        $prior = $repo->captureRawPair(self::STORE_ID);

        foreach ($this->process1RejectedSnapshots() as $label => $snapshot) {
            try {
                $persistence->persistValidatedSnapshot(self::STORE_ID, self::UNICID, $snapshot);
                self::fail('Expected rejection: ' . $label);
            } catch (ShopSnapshotValidationException $exception) {
                self::assertNotEmpty($exception->violations());
            }
            self::assertSame($prior, $repo->captureRawPair(self::STORE_ID), $label);
            self::assertSame('keep-user', $repo->getUsername(self::STORE_ID), $label);
        }
    }

    public function testProcess2BothAbsentPreservesEncryptedPairByteForByte(): void
    {
        [, $repo, $persistence, $cacheDb] = $this->wiring();
        $repo->saveCompletePair(self::STORE_ID, 'p2-user', 'p2-pass');
        $prior = $repo->captureRawPair(self::STORE_ID);

        $snapshot = mt_uni_credit_valid_shop_snapshot(['uni_proces' => 1]);
        unset($snapshot['uni_user'], $snapshot['uni_password']);
        $written = $persistence->persistValidatedSnapshot(self::STORE_ID, self::UNICID, $snapshot);

        self::assertSame($prior, $repo->captureRawPair(self::STORE_ID));
        self::assertArrayNotHasKey('uni_user', $written);
        self::assertArrayNotHasKey('uni_password', $written);
        self::assertTrue($cacheDb->wroteShopCache);
        self::assertSame('p2-user', $repo->getUsername(self::STORE_ID));
    }

    public function testProcess2CompletePairRotatesAndPartialRejected(): void
    {
        [, $repo, $persistence] = $this->wiring();
        $repo->saveCompletePair(self::STORE_ID, 'old-user', 'old-pass');
        $prior = $repo->captureRawPair(self::STORE_ID);

        $rotated = mt_uni_credit_valid_shop_snapshot([
            'uni_proces' => 1,
            'uni_user' => 'new-user',
            'uni_password' => 'new-pass',
        ]);
        $persistence->persistValidatedSnapshot(self::STORE_ID, self::UNICID, $rotated);
        self::assertNotSame($prior, $repo->captureRawPair(self::STORE_ID));
        self::assertSame('new-user', $repo->getUsername(self::STORE_ID));
        self::assertSame('new-pass', $repo->getPassword(self::STORE_ID));

        $afterRotate = $repo->captureRawPair(self::STORE_ID);
        foreach ($this->process2RejectedSnapshots() as $label => $bad) {
            try {
                $persistence->persistValidatedSnapshot(self::STORE_ID, self::UNICID, $bad);
                self::fail('Expected Process 2 partial rejection: ' . $label);
            } catch (ShopSnapshotValidationException $exception) {
                self::assertNotEmpty($exception->violations());
            }
            self::assertSame($afterRotate, $repo->captureRawPair(self::STORE_ID), $label);
        }
    }

    public function testEncryptionRoundTripTamperWrongKeyPlaintextRejected(): void
    {
        $settings = new InMemoryModuleSettingStore();
        $cipher = Phase4TestHarness::cipher();
        $repo = new SmartUcfCredentialRepository($settings, $cipher);
        $repo->saveCompletePair(self::STORE_ID, 'same-plain', 'same-plain');
        $firstUser = (string) $settings->get(self::STORE_ID, SmartUcfCredentialRepository::USER_SETTING);
        $repo->saveCompletePair(self::STORE_ID, 'same-plain', 'same-plain');
        $secondUser = (string) $settings->get(self::STORE_ID, SmartUcfCredentialRepository::USER_SETTING);
        self::assertNotSame($firstUser, $secondUser);
        self::assertSame('same-plain', $repo->getUsername(self::STORE_ID));

        $settings->set(self::STORE_ID, SmartUcfCredentialRepository::USER_SETTING, $firstUser . 'x');
        self::assertNull($repo->getUsername(self::STORE_ID));

        $settings->set(self::STORE_ID, SmartUcfCredentialRepository::USER_SETTING, 'plaintext-not-allowed');
        self::assertNull($repo->getUsername(self::STORE_ID));

        $repo->saveCompletePair(self::STORE_ID, 'u', 'p');
        $wrong = new SmartUcfCredentialRepository($settings, new ModuleSettingCipher(str_repeat('z', 32)));
        self::assertNull($wrong->getUsername(self::STORE_ID));
        self::assertNull($wrong->getPassword(self::STORE_ID));
    }

    public function testAtomicRollbackOnCredentialWriteFailure(): void
    {
        [$settings, $repo, $persistence, $db] = $this->wiring();
        $repo->saveCompletePair(self::STORE_ID, 'old-u', 'old-p');
        $prior = $repo->captureRawPair(self::STORE_ID);

        $db->settingWriteCount = 0;
        $db->failOnSettingWriteNumber = 1;
        try {
            $persistence->persistValidatedSnapshot(
                self::STORE_ID,
                self::UNICID,
                mt_uni_credit_valid_shop_snapshot(['uni_user' => 'new-u', 'uni_password' => 'new-p'])
            );
            self::fail('Expected write failure');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Forced setting write failure', $exception->getMessage());
        }
        self::assertSame($prior, $repo->captureRawPair(self::STORE_ID));
        self::assertFalse($db->wroteShopCache);
        self::assertNull($db->committedShopData(self::STORE_ID, self::UNICID));

        $db->settingWriteCount = 0;
        $db->failOnSettingWriteNumber = 2;
        $db->wroteShopCache = false;
        try {
            $persistence->persistValidatedSnapshot(
                self::STORE_ID,
                self::UNICID,
                mt_uni_credit_valid_shop_snapshot(['uni_user' => 'new-u', 'uni_password' => 'new-p'])
            );
            self::fail('Expected second-write failure');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Forced setting write failure', $exception->getMessage());
        }
        self::assertSame($prior, $repo->captureRawPair(self::STORE_ID));
        self::assertSame('old-u', $repo->getUsername(self::STORE_ID));
        self::assertSame('old-p', $repo->getPassword(self::STORE_ID));
        self::assertSame(
            $settings->get(self::STORE_ID, SmartUcfCredentialRepository::USER_SETTING),
            $prior['user']
        );
    }

    public function testAtomicRollbackOnCachePersistenceFailure(): void
    {
        [, $repo, $persistence, $db] = $this->wiring();
        $repo->saveCompletePair(self::STORE_ID, 'old-u', 'old-p');
        $prior = $repo->captureRawPair(self::STORE_ID);
        $db->failNextCacheWrite = true;

        try {
            $persistence->persistValidatedSnapshot(
                self::STORE_ID,
                self::UNICID,
                mt_uni_credit_valid_shop_snapshot(['uni_user' => 'new-u', 'uni_password' => 'new-p'])
            );
            self::fail('Expected cache failure');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Forced cache persistence failure', $exception->getMessage());
        }
        self::assertSame($prior, $repo->captureRawPair(self::STORE_ID));
        self::assertSame('old-u', $repo->getUsername(self::STORE_ID));
        self::assertFalse($db->wroteShopCache);
        self::assertNull($db->committedShopData(self::STORE_ID, self::UNICID));
    }

    public function testRuntimeGuardBlocksSmartUcfClientWithoutCredentials(): void
    {
        $cases = [
            'missing both' => [],
            'user only' => ['uni_user' => 'u'],
            'password only' => ['uni_password' => 'p'],
            'blank user' => ['uni_user' => '', 'uni_password' => 'p'],
            'blank password' => ['uni_user' => 'u', 'uni_password' => ''],
        ];
        foreach ($cases as $label => $shopOverrides) {
            $client = new CredentialGuardSmartUcfClient();
            $db = new SmartUcfCredentialLifecycleFakeDb();
            $db->seedAttempt(1);
            $coordinator = $this->coordinator($client, $db);
            $shop = mt_uni_credit_valid_shop_snapshot();
            unset($shop['uni_user'], $shop['uni_password']);
            $shop = array_merge($shop, $shopOverrides);

            $result = $coordinator->run(
                1,
                $shop,
                OrderMaterializationTestHarness::productSubmission(),
                9001,
                501
            );
            self::assertTrue($result->isFailed(), $label);
            self::assertSame('smartucf_credentials_unavailable', $result->errorClass(), $label);
            self::assertSame(0, $client->calls, $label);
        }
    }

    public function testValidPairReachesSmartUcfClientOnce(): void
    {
        $client = new CredentialGuardSmartUcfClient();
        $client->succeed = true;
        $db = new SmartUcfCredentialLifecycleFakeDb();
        $db->seedAttempt(2);
        $coordinator = $this->coordinator($client, $db);
        $shop = mt_uni_credit_valid_shop_snapshot([
            'uni_user' => 'demo-user',
            'uni_password' => 'demo-secret-password',
            'uni_sertificat' => 0,
        ]);
        $result = $coordinator->run(
            2,
            $shop,
            OrderMaterializationTestHarness::productSubmission(),
            9002,
            502
        );
        self::assertTrue($result->isCreated());
        self::assertSame(1, $client->calls);
    }

    public function testPayloadBuilderRejectsEmptyCredentials(): void
    {
        $builder = new SmartUcfPayloadBuilder();
        $shop = mt_uni_credit_valid_shop_snapshot();
        unset($shop['uni_user'], $shop['uni_password']);
        $this->expectException(\InvalidArgumentException::class);
        $builder->build(OrderMaterializationTestHarness::productSubmission(), $shop, 1);
    }

    public function testDiagnosticsRedactCredentialsButKeepSessionId(): void
    {
        $redacted = DiagnosticPayloadRedactor::redact([
            'user' => 'secret-user',
            'pass' => 'secret-pass',
            'uni_user' => 'secret-uni-user',
            'uni_password' => 'secret-uni-pass',
            'sucfOnlineSessionID' => 'keep-session-id',
        ]);
        self::assertSame('[REDACTED]', $redacted['user']);
        self::assertSame('[REDACTED]', $redacted['pass']);
        self::assertSame('[REDACTED]', $redacted['uni_user']);
        self::assertSame('[REDACTED]', $redacted['uni_password']);
        self::assertSame('keep-session-id', $redacted['sucfOnlineSessionID']);
    }

    public function testHydrationNeverWritesBackOneSidedPair(): void
    {
        $settings = new InMemoryModuleSettingStore();
        $cipher = Phase4TestHarness::cipher();
        $repo = new SmartUcfCredentialRepository($settings, $cipher);
        $settings->set(
            self::STORE_ID,
            SmartUcfCredentialRepository::USER_SETTING,
            $cipher->encrypt('only-user')
        );
        $hydrated = $repo->hydrateShopSnapshot(self::STORE_ID, ['unicid' => self::UNICID]);
        self::assertArrayNotHasKey('uni_user', $hydrated);
        self::assertArrayNotHasKey('uni_password', $hydrated);
    }

    public function testInstallWithoutCredentialsAndLaterProvisioning(): void
    {
        [$settings, $repo, $persistence] = $this->wiring();
        self::assertNull($repo->getUsername(self::STORE_ID));
        self::assertNull($repo->getPassword(self::STORE_ID));

        $persistence->persistValidatedSnapshot(
            self::STORE_ID,
            self::UNICID,
            mt_uni_credit_valid_shop_snapshot([
                'uni_user' => 'provisioned-user',
                'uni_password' => 'provisioned-pass',
            ])
        );
        self::assertSame('provisioned-user', $repo->getUsername(self::STORE_ID));
        $repo->deletePair(self::STORE_ID);
        self::assertNull($repo->getUsername(self::STORE_ID));
        self::assertNull($settings->get(self::STORE_ID, SmartUcfCredentialRepository::USER_SETTING));
        self::assertNull($settings->get(self::STORE_ID, SmartUcfCredentialRepository::PASSWORD_SETTING));
    }

    /**
     * @return array{0: ModuleSettingStore, 1: SmartUcfCredentialRepository, 2: SmartUcfCredentialPersistence, 3: CredentialAtomicFakeDb}
     */
    private function wiring(): array
    {
        $db = new CredentialAtomicFakeDb();
        $cipher = Phase4TestHarness::cipher();
        $settings = new OpenCartModuleSettingStore($db);
        $repo = new SmartUcfCredentialRepository($settings, $cipher);
        $persistence = new SmartUcfCredentialPersistence(
            $repo,
            $cipher,
            new ShopCacheRepository($db),
            $db
        );

        return [$settings, $repo, $persistence, $db];
    }

    /** @return array<string, array<string, mixed>> */
    private function process1RejectedSnapshots(): array
    {
        $absent = mt_uni_credit_valid_shop_snapshot();
        unset($absent['uni_user'], $absent['uni_password']);

        $userOnly = mt_uni_credit_valid_shop_snapshot(['uni_user' => 'only-user']);
        unset($userOnly['uni_password']);

        $passOnly = mt_uni_credit_valid_shop_snapshot(['uni_password' => 'only-pass']);
        unset($passOnly['uni_user']);

        return [
            'absent' => $absent,
            'user-only' => $userOnly,
            'password-only' => $passOnly,
            'blank-user' => mt_uni_credit_valid_shop_snapshot(['uni_user' => '  ', 'uni_password' => 'x']),
            'blank-password' => mt_uni_credit_valid_shop_snapshot(['uni_user' => 'x', 'uni_password' => '']),
            'non-string' => mt_uni_credit_valid_shop_snapshot(['uni_user' => 12, 'uni_password' => 'x']),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function process2RejectedSnapshots(): array
    {
        $userOnly = mt_uni_credit_valid_shop_snapshot(['uni_proces' => 1, 'uni_user' => 'x']);
        unset($userOnly['uni_password']);
        $passOnly = mt_uni_credit_valid_shop_snapshot(['uni_proces' => 1, 'uni_password' => 'y']);
        unset($passOnly['uni_user']);

        return [
            'user-only' => $userOnly,
            'password-only' => $passOnly,
            'blank' => mt_uni_credit_valid_shop_snapshot([
                'uni_proces' => 1,
                'uni_user' => '',
                'uni_password' => 'y',
            ]),
        ];
    }

    private function coordinator(object $client, SmartUcfCredentialLifecycleFakeDb $db): SmartUcfSessionCoordinator
    {
        $settings = Phase4TestHarness::settings();
        Phase4TestHarness::prepareCredentials($settings);
        $cipher = Phase4TestHarness::cipher();
        $transport = new FakeCpHttpTransport();
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
            $client,
            new SmartUcfFailureClassifier(),
            new OrderBankStatusRepository($db),
            $cp,
            new ControlPanelStatusSyncService(
                new ControlPanelStatusSyncRepository($db),
                new RecordingStatusPort()
            )
        );
    }
}

/** @internal */
final class CredentialGuardSmartUcfClient
{
    public int $calls = 0;

    public bool $succeed = false;

    /**
     * @param array<string, mixed> $shop
     * @return array{session_id: string, redirect_url: string, http_code: int}
     */
    public function createSession(
        array $shop,
        ValidatedFinancingSubmission $submission,
        int $localOrderId,
        mixed $lease = null
    ): array {
        $this->calls++;
        if (!$this->succeed) {
            throw new \LogicException('SmartUCF client must not be called.');
        }

        return [
            'session_id' => 'sess-ok',
            'redirect_url' => 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/sess-ok',
            'http_code' => 200,
        ];
    }
}


/** @internal */
final class SmartUcfCredentialLifecycleFakeDb implements DbConnection
{
    /** @var array<int, array<string, mixed>> */
    public array $attempts = [];

    private int $affected = 0;

    public function seedAttempt(int $attemptId): void
    {
        $this->attempts[$attemptId] = [
            'attempt_id' => $attemptId,
            'store_id' => Phase4TestHarness::TEST_STORE_ID,
            'order_id' => 9000 + $attemptId,
            'unicid' => 'test-unicid',
            'smartucf_state' => SmartUcfLifecycleStates::NOT_STARTED,
            'smartucf_session_id' => null,
            'smartucf_redirect_url' => null,
            'smartucf_http_code' => 0,
            'smartucf_error_class' => null,
            'smartucf_retryable' => 0,
            'smartucf_claimed_at' => null,
            'smartucf_completed_at' => null,
            'process2_state' => 'not_started',
            'cp_status_sync_state' => 'not_needed',
            'cp_status_sync_status_id' => null,
            'cp_status_sync_status' => null,
            'cp_status_sync_error_class' => null,
            'updated_at' => '2026-01-01 00:00:00',
        ];
    }

    public function query(string $sql): object
    {
        if (preg_match('/WHERE `attempt_id` = (\d+)/', $sql, $m) && preg_match('/^\s*SELECT/i', $sql)) {
            $row = $this->attempts[(int) $m[1]] ?? null;

            return $this->result($row === null ? [] : [$row]);
        }

        if (str_contains($sql, 'UPDATE') && preg_match('/WHERE `attempt_id` = (\d+)/', $sql, $m)) {
            $id = (int) $m[1];
            if (!isset($this->attempts[$id])) {
                $this->affected = 0;

                return $this->result([]);
            }
            if (str_contains($sql, "smartucf_state` = '" . SmartUcfLifecycleStates::SUBMITTING . "'")) {
                $state = (string) ($this->attempts[$id]['smartucf_state'] ?? '');
                if ($state !== SmartUcfLifecycleStates::NOT_STARTED
                    && !($state === SmartUcfLifecycleStates::FAILED && (int) ($this->attempts[$id]['smartucf_retryable'] ?? 0) === 1)
                ) {
                    $this->affected = 0;

                    return $this->result([]);
                }
                $this->attempts[$id]['smartucf_state'] = SmartUcfLifecycleStates::SUBMITTING;
                $this->attempts[$id]['smartucf_claimed_at'] = '2026-01-01 00:00:00';
                $this->affected = 1;

                return $this->result([]);
            }
            if (str_contains($sql, "smartucf_state` = '" . SmartUcfLifecycleStates::CREATED . "'")) {
                $this->attempts[$id]['smartucf_state'] = SmartUcfLifecycleStates::CREATED;
                $this->attempts[$id]['smartucf_session_id'] = 'sess-ok';
                $this->attempts[$id]['smartucf_redirect_url'] = 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/sess-ok';
                $this->affected = 1;

                return $this->result([]);
            }
            if (str_contains($sql, "`cp_status_sync_state` = 'pending'")) {
                $this->attempts[$id]['cp_status_sync_state'] = 'pending';
                $this->attempts[$id]['cp_status_sync_status_id'] = 'bank_sent_process1';
                $this->attempts[$id]['cp_status_sync_status'] = 'Изпратен Банка - Процес 1';
                $this->affected = 1;

                return $this->result([]);
            }
            if (str_contains($sql, "`cp_status_sync_state` = 'confirmed'")) {
                $this->attempts[$id]['cp_status_sync_state'] = 'confirmed';
                $this->affected = 1;

                return $this->result([]);
            }
            $this->affected = 1;

            return $this->result([]);
        }

        if (str_contains($sql, 'INSERT INTO') && str_contains($sql, PersistenceTableNames::ORDER_BANK_STATUS)) {
            $this->affected = 1;

            return $this->result([]);
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
