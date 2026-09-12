<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\CredentialAtomicFakeDb;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\DbMutationBoundary;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartModuleSettingStore;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceException;
use Opencart\System\Library\Extension\MtUniCredit\ShopCacheRepository;
use Opencart\System\Library\Extension\MtUniCredit\ShopSnapshotValidationException;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfCredentialPersistence;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfCredentialRepository;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/fixtures/cp_shop_snapshot.php';

/**
 * Atomic SmartUCF credential pair + sanitized cache persistence.
 *
 * Uses an in-process transactional fake that models MySQL GET_LOCK + START TRANSACTION
 * semantics (committed vs working state). True multi-connection concurrency is not available
 * in this harness; serialization is asserted via exclusive lock + deterministic ordering.
 */
final class SmartUcfCredentialAtomicPersistenceTest extends TestCase
{
    private const STORE_ID = Phase4TestHarness::TEST_STORE_ID;

    private const UNICID = Phase4TestHarness::TEST_UNICID;

    public function testSuccessfulUpdateCommitsPairAndSanitizedCacheTogether(): void
    {
        [$db, $repo, $persistence, $cache] = $this->wiring();
        $repo->saveCompletePair(self::STORE_ID, 'old-u', 'old-p');
        self::assertSame(0, $db->openTransactions);

        $written = $persistence->persistValidatedSnapshot(
            self::STORE_ID,
            self::UNICID,
            mt_uni_credit_valid_shop_snapshot([
                'uni_user' => 'new-u',
                'uni_password' => 'new-p',
            ])
        );

        self::assertSame(0, $db->openTransactions);
        self::assertSame('new-u', $repo->getUsername(self::STORE_ID));
        self::assertSame('new-p', $repo->getPassword(self::STORE_ID));
        self::assertArrayNotHasKey('uni_user', $written);
        $cached = $cache->findLatest(self::STORE_ID, self::UNICID);
        self::assertNotNull($cached);
        self::assertArrayNotHasKey('uni_user', $cached['shop_data']);
        self::assertArrayNotHasKey('uni_password', $cached['shop_data']);
        self::assertSame($db->committedShopData(self::STORE_ID, self::UNICID), $cached['shop_data']);
    }

    public function testSecondCredentialWriteFailureRollsBackBoth(): void
    {
        [$db, $repo, $persistence] = $this->wiring();
        $repo->saveCompletePair(self::STORE_ID, 'old-u', 'old-p');
        $prior = $repo->captureRawPair(self::STORE_ID);

        $db->settingWriteCount = 0;
        $db->failOnSettingWriteNumber = 2;
        try {
            $persistence->persistValidatedSnapshot(
                self::STORE_ID,
                self::UNICID,
                mt_uni_credit_valid_shop_snapshot([
                    'uni_user' => 'new-u',
                    'uni_password' => 'new-p',
                ])
            );
            self::fail('Expected second setting write failure');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Forced setting write failure', $exception->getMessage());
        }

        self::assertSame(0, $db->openTransactions);
        self::assertSame($prior, $repo->captureRawPair(self::STORE_ID));
        self::assertSame('old-u', $repo->getUsername(self::STORE_ID));
        self::assertSame('old-p', $repo->getPassword(self::STORE_ID));
        self::assertNull($db->committedShopData(self::STORE_ID, self::UNICID));
    }

    public function testCacheWriteFailureRollsBackPair(): void
    {
        [$db, $repo, $persistence] = $this->wiring();
        $repo->saveCompletePair(self::STORE_ID, 'old-u', 'old-p');
        $prior = $repo->captureRawPair(self::STORE_ID);
        $db->failNextCacheWrite = true;

        try {
            $persistence->persistValidatedSnapshot(
                self::STORE_ID,
                self::UNICID,
                mt_uni_credit_valid_shop_snapshot([
                    'uni_user' => 'new-u',
                    'uni_password' => 'new-p',
                ])
            );
            self::fail('Expected cache write failure');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Forced cache persistence failure', $exception->getMessage());
        }

        self::assertSame($prior, $repo->captureRawPair(self::STORE_ID));
        self::assertSame('old-u', $repo->getUsername(self::STORE_ID));
        self::assertNull($db->committedShopData(self::STORE_ID, self::UNICID));
    }

    public function testLockFailureCausesNoMutation(): void
    {
        [$db, $repo, $persistence] = $this->wiring();
        $repo->saveCompletePair(self::STORE_ID, 'old-u', 'old-p');
        $prior = $repo->captureRawPair(self::STORE_ID);
        $db->settingWriteCount = 0;
        $db->failNextLock = true;

        try {
            $persistence->persistValidatedSnapshot(
                self::STORE_ID,
                self::UNICID,
                mt_uni_credit_valid_shop_snapshot([
                    'uni_user' => 'new-u',
                    'uni_password' => 'new-p',
                ])
            );
            self::fail('Expected lock failure');
        } catch (PersistenceException $exception) {
            self::assertStringContainsString('exclusive mutation lock', $exception->getMessage());
        }

        self::assertSame($prior, $repo->captureRawPair(self::STORE_ID));
        self::assertSame(0, $db->settingWriteCount);
        self::assertNull($db->committedShopData(self::STORE_ID, self::UNICID));
    }

    public function testCommittedReadersNeverObserveMixedPair(): void
    {
        [$db, $repo, $persistence] = $this->wiring();
        $repo->saveCompletePair(self::STORE_ID, 'old-u', 'old-p');
        $oldPair = $repo->captureRawPair(self::STORE_ID);

        $db->afterEachSettingWrite = function () use ($db, $oldPair): void {
            $committed = $db->committedCredentialRawPair(self::STORE_ID);
            if ($committed['user'] !== $oldPair['user'] || $committed['password'] !== $oldPair['password']) {
                self::assertSame($committed['user'] !== null, $committed['password'] !== null);
                self::assertNotSame($oldPair['user'], $committed['user']);
                self::assertNotSame($oldPair['password'], $committed['password']);
            } else {
                self::assertSame($oldPair, $committed);
            }
            $db->committedObservations[] = $committed;
        };

        $persistence->persistValidatedSnapshot(
            self::STORE_ID,
            self::UNICID,
            mt_uni_credit_valid_shop_snapshot([
                'uni_user' => 'new-u',
                'uni_password' => 'new-p',
            ])
        );

        self::assertNotEmpty($db->committedObservations);
        foreach ($db->committedObservations as $observation) {
            self::assertTrue(
                ($observation['user'] === $oldPair['user'] && $observation['password'] === $oldPair['password'])
                || ($observation['user'] !== $oldPair['user'] && $observation['password'] !== $oldPair['password'])
            );
            self::assertFalse(
                $observation['user'] !== $oldPair['user'] && $observation['password'] === $oldPair['password'],
                'Committed reader observed new user + old password'
            );
            self::assertFalse(
                $observation['user'] === $oldPair['user'] && $observation['password'] !== $oldPair['password'],
                'Committed reader observed old user + new password'
            );
        }
        self::assertSame('new-u', $repo->getUsername(self::STORE_ID));
        self::assertSame('new-p', $repo->getPassword(self::STORE_ID));
    }

    public function testProcess2AbsentDoesNotMutateCredentials(): void
    {
        [$db, $repo, $persistence] = $this->wiring();
        $repo->saveCompletePair(self::STORE_ID, 'keep-u', 'keep-p');
        $prior = $repo->captureRawPair(self::STORE_ID);
        $db->settingWriteCount = 0;

        $snapshot = mt_uni_credit_valid_shop_snapshot(['uni_proces' => 1]);
        unset($snapshot['uni_user'], $snapshot['uni_password']);
        $persistence->persistValidatedSnapshot(self::STORE_ID, self::UNICID, $snapshot);

        self::assertSame(0, $db->settingWriteCount);
        self::assertSame($prior, $repo->captureRawPair(self::STORE_ID));
        self::assertNotNull($db->committedShopData(self::STORE_ID, self::UNICID));
    }

    public function testConcurrentRotationsSerializeDeterministically(): void
    {
        [$db, $repo, $persistence] = $this->wiring();
        $repo->saveCompletePair(self::STORE_ID, 'v0-u', 'v0-p');

        $order = [];
        $db->onLockAcquired = static function (string $name) use (&$order): void {
            $order[] = 'acquire:' . $name;
        };
        $db->onLockReleased = static function (string $name) use (&$order): void {
            $order[] = 'release:' . $name;
        };

        $persistence->persistValidatedSnapshot(
            self::STORE_ID,
            self::UNICID,
            mt_uni_credit_valid_shop_snapshot(['uni_user' => 'v1-u', 'uni_password' => 'v1-p'])
        );
        $persistence->persistValidatedSnapshot(
            self::STORE_ID,
            self::UNICID,
            mt_uni_credit_valid_shop_snapshot(['uni_user' => 'v2-u', 'uni_password' => 'v2-p'])
        );

        $lock = DbMutationBoundary::smartUcfCredentialLockName(self::STORE_ID, self::UNICID);
        self::assertSame([
            'acquire:' . $lock,
            'release:' . $lock,
            'acquire:' . $lock,
            'release:' . $lock,
        ], $order);
        self::assertSame('v2-u', $repo->getUsername(self::STORE_ID));
        self::assertSame('v2-p', $repo->getPassword(self::STORE_ID));
        self::assertSame([], $db->heldLocks);
    }

    public function testInvalidPairRejectedBeforeLockOrMutation(): void
    {
        [$db, $repo, $persistence] = $this->wiring();
        $repo->saveCompletePair(self::STORE_ID, 'keep-u', 'keep-p');
        $prior = $repo->captureRawPair(self::STORE_ID);
        $db->settingWriteCount = 0;
        $db->lockAcquireAttempts = [];

        $bad = mt_uni_credit_valid_shop_snapshot(['uni_user' => 'only']);
        unset($bad['uni_password']);
        try {
            $persistence->persistValidatedSnapshot(self::STORE_ID, self::UNICID, $bad);
            self::fail('Expected validation failure');
        } catch (ShopSnapshotValidationException $exception) {
            self::assertNotEmpty($exception->violations());
        }

        self::assertSame([], $db->lockAcquireAttempts);
        self::assertSame(0, $db->settingWriteCount);
        self::assertSame($prior, $repo->captureRawPair(self::STORE_ID));
    }

    public function testHydrationUsesPairedSnapshotNotOneSide(): void
    {
        [$db, $repo] = $this->wiring();
        $settings = new OpenCartModuleSettingStore($db);
        $cipher = Phase4TestHarness::cipher();
        $settings->set(self::STORE_ID, SmartUcfCredentialRepository::USER_SETTING, $cipher->encrypt('only-user'));
        $hydrated = $repo->hydrateShopSnapshot(self::STORE_ID, ['x' => 1]);
        self::assertArrayNotHasKey('uni_user', $hydrated);
        self::assertArrayNotHasKey('uni_password', $hydrated);
    }

    /**
     * @return array{0: CredentialAtomicFakeDb, 1: SmartUcfCredentialRepository, 2: SmartUcfCredentialPersistence, 3: ShopCacheRepository}
     */
    private function wiring(): array
    {
        $db = new CredentialAtomicFakeDb();
        $cipher = Phase4TestHarness::cipher();
        $settings = new OpenCartModuleSettingStore($db);
        $repo = new SmartUcfCredentialRepository($settings, $cipher);
        $cache = new ShopCacheRepository($db);
        $persistence = new SmartUcfCredentialPersistence($repo, $cipher, $cache, $db);

        return [$db, $repo, $persistence, $cache];
    }
}
