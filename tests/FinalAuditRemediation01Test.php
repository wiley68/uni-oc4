<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
use Opencart\System\Library\Extension\MtUniCredit\DiagnosticDebugLogRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleEncryptionKeyProvider;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceTableNames;
use Opencart\System\Library\Extension\MtUniCredit\ProcessTwoSensitiveCipher;
use Opencart\System\Library\Extension\MtUniCredit\ProcessTwoSensitiveData;
use Opencart\System\Library\Extension\MtUniCredit\ProcessTwoSubmissionSupport;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\SecurityConstants;
use PHPUnit\Framework\TestCase;

/**
 * Final audit remediation 01 — F-01 / F-02 / F-03 regressions.
 */
final class FinalAuditRemediation01Test extends TestCase
{
    public function testProcessTwoCipherHasNoPredictableTestSecretFallback(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/process_two_sensitive_cipher.php'
        );
        self::assertStringNotContainsString('testSecretInput()', $src);
        self::assertStringContainsString('resolveSecretInput()', $src);
    }

    public function testAdminOrderListHasNoStoreZeroFallbackMerge(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/admin/controller/event/mt_uni_credit_admin_order.php'
        );
        self::assertStringContainsString('bankStatusLabelsForOrders', $src);
        self::assertStringNotContainsString('batchBankStatusLabels(0', $src);
        self::assertStringNotContainsString('Also resolve statuses for attempts that may be store_id=0', $src);
    }

    public function testPresentationRepositoryFindBankStatusHasNoStoreZeroFallback(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/financing_presentation_repository.php'
        );
        self::assertStringNotContainsString('batchBankStatusLabels(0, [$orderId])', $src);
        self::assertStringContainsString('bankStatusLabelsForOrders', $src);
    }

    public function testExplicitSecretEncryptDecryptRoundTrip(): void
    {
        $secret = ModuleEncryptionKeyProvider::testSecretInput();
        $cipher = new ProcessTwoSensitiveCipher($secret);
        $data = new ProcessTwoSensitiveData('1990011599', '0888123456');
        $enc = $cipher->encrypt($data);
        self::assertStringStartsWith('enc:v1:', $enc);
        $decoded = $cipher->decrypt($enc);
        self::assertSame('1990011599', $decoded->egn);
        self::assertSame('0888123456', $decoded->phone2);
    }

    public function testWrongSecretDecryptFails(): void
    {
        $cipher = new ProcessTwoSensitiveCipher(ModuleEncryptionKeyProvider::testSecretInput());
        $enc = $cipher->encrypt(new ProcessTwoSensitiveData('1990011599', '0888123456'));
        $other = new ProcessTwoSensitiveCipher(ModuleEncryptionKeyProvider::testSecretInput() . '-other');
        $this->expectException(\Throwable::class);
        $other->decrypt($enc);
    }

    public function testTamperedCiphertextFails(): void
    {
        $cipher = new ProcessTwoSensitiveCipher(ModuleEncryptionKeyProvider::testSecretInput());
        $enc = $cipher->encrypt(new ProcessTwoSensitiveData('1990011599', '0888123456'));
        $tampered = substr($enc, 0, -4) . 'xxxx';
        $this->expectException(\Throwable::class);
        $cipher->decrypt($tampered);
    }

    public function testEmptyExplicitSecretFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        new ProcessTwoSensitiveCipher('');
    }

    public function testMissingSecretAbortDoesNotPersistCiphertextOrBankStatus(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempts = new FinancingAttemptRepository($db);
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId,
            $submission->entryPoint,
            hash('sha256', 'f01-op'),
            hash('sha256', 'f01-actor'),
            hash('sha256', 'f01-sel')
        );
        $attemptId = (int) $row['attempt_id'];

        try {
            ProcessTwoSubmissionSupport::persist(
                $submission,
                $attemptId,
                $db,
                new ProcessTwoSensitiveData('1990011599', '0888123456'),
                ''
            );
            self::fail('Expected ProductFinancingFlowException');
        } catch (ProductFinancingFlowException $exception) {
            self::assertSame('process2_encryption_unavailable', $exception->errorCode());
            self::assertStringNotContainsString('secret', strtolower($exception->getMessage()));
            self::assertStringNotContainsString('DB_PASSWORD', $exception->getMessage());
        }

        $fresh = $attempts->findById($attemptId);
        self::assertNotNull($fresh);
        self::assertSame('', (string) ($fresh['process2_sensitive_enc'] ?? ''));
        self::assertStringNotContainsString('1990011599', json_encode($fresh, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('0888123456', json_encode($fresh, JSON_THROW_ON_ERROR));

        $bank = $db->query(
            'SELECT COUNT(*) AS `c` FROM `' . $db->getPrefix() . PersistenceTableNames::ORDER_BANK_STATUS . '`'
            . ' WHERE `status_id` = \'' . $db->escape(BankStatus::SENT_PROCESS2) . '\''
        );
        self::assertSame(0, (int) ($bank->row['c'] ?? -1));
    }

    public function testExplicitOverridePersistStillWorks(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempts = new FinancingAttemptRepository($db);
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId,
            OperationEntryPoint::PRODUCT,
            hash('sha256', 'f01-ok-op'),
            hash('sha256', 'f01-ok-actor'),
            hash('sha256', 'f01-ok-sel')
        );
        $attemptId = (int) $row['attempt_id'];
        ProcessTwoSubmissionSupport::persist(
            $submission,
            $attemptId,
            $db,
            new ProcessTwoSensitiveData('1990011599', '0888123456'),
            ModuleEncryptionKeyProvider::testSecretInput()
        );
        $fresh = $attempts->findById($attemptId);
        self::assertNotNull($fresh);
        $enc = (string) ($fresh['process2_sensitive_enc'] ?? '');
        self::assertStringStartsWith('enc:v1:', $enc);
        $decoded = (new ProcessTwoSensitiveCipher(ModuleEncryptionKeyProvider::testSecretInput()))->decrypt($enc);
        self::assertSame('1990011599', $decoded->egn);
    }

    public function testMultistoreBankStatusExactStoreMatch(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $repo = new FinancingPresentationRepository($db);
        $this->seedBankStatus($db, 0, 123, BankStatus::SENT_PROCESS1, BankStatus::LABEL_SENT_PROCESS1);
        $this->seedBankStatus($db, 2, 123, 'cp_sent', 'Създаден в КП Банка');

        self::assertSame(BankStatus::LABEL_SENT_PROCESS1, $repo->findBankStatusLabel(0, 123));
        self::assertSame('Създаден в КП Банка', $repo->findBankStatusLabel(2, 123));
    }

    public function testMissingBankStatusDoesNotFallbackToStoreZero(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $repo = new FinancingPresentationRepository($db);
        $this->seedBankStatus($db, 0, 123, BankStatus::SENT_PROCESS1, BankStatus::LABEL_SENT_PROCESS1);

        self::assertSame('', $repo->findBankStatusLabel(2, 123));
        self::assertSame(BankStatus::LABEL_SENT_PROCESS1, $repo->findBankStatusLabel(0, 123));
    }

    public function testAdminListMixedStoreCollidingOrderIds(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        $repo = new FinancingPresentationRepository($db);
        $this->seedBankStatus($db, 0, 123, BankStatus::SENT_PROCESS1, BankStatus::LABEL_SENT_PROCESS1);
        $this->seedBankStatus($db, 2, 123, 'cp_sent', 'Създаден в КП Банка');

        $orders = [
            ['order_id' => 123, 'store_id' => 0],
            ['order_id' => 123, 'store_id' => 2],
            ['order_id' => 999, 'store_id' => 2],
        ];
        $labels = $repo->bankStatusLabelsForOrders($orders, 99);
        self::assertSame(BankStatus::LABEL_SENT_PROCESS1, $labels[0]);
        self::assertSame('Създаден в КП Банка', $labels[1]);
        self::assertSame('', $labels[2]);
    }

    public function testDiagnosticPruneBoundedAndIndexed(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        (new PersistenceSchemaInstaller($db))->installAll();

        $table = $db->getPrefix() . PersistenceTableNames::DIAGNOSTIC_DEBUG_LOG;
        $index = $db->query(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_mt_uni_credit_diag_created'"
        );
        self::assertGreaterThan(0, (int) ($index->num_rows ?? 0));

        $storeId = PersistenceIntegrationHarness::TEST_STORE_ID;
        $old = '2020-01-01 00:00:00';
        $fresh = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        for ($i = 1; $i <= 5; $i++) {
            $db->query(
                "INSERT INTO `{$table}`
                    (`store_id`, `order_id`, `entry_point`, `event_code`, `http_status`, `summary_json`, `created_at`)
                 VALUES (
                    " . (int) $storeId . ",
                    " . (1000 + $i) . ",
                    'product',
                    'success',
                    200,
                    '{}',
                    '" . $db->escape($old) . "'
                 )"
            );
        }
        $db->query(
            "INSERT INTO `{$table}`
                (`store_id`, `order_id`, `entry_point`, `event_code`, `http_status`, `summary_json`, `created_at`)
             VALUES (
                " . (int) $storeId . ",
                2001,
                'product',
                'success',
                200,
                '{}',
                '" . $db->escape($fresh) . "'
             )"
        );

        $repo = new DiagnosticDebugLogRepository($db);
        self::assertSame(3, DiagnosticDebugLogRepository::RETENTION_MONTHS);
        $deleted = $repo->pruneOld(null, 3);
        self::assertSame(3, $deleted);
        $remainingOld = $db->query(
            "SELECT COUNT(*) AS `c` FROM `{$table}` WHERE `created_at` = '" . $db->escape($old) . "'"
        );
        self::assertSame(2, (int) ($remainingOld->row['c'] ?? -1));
        $deleted2 = $repo->pruneOld(null, 3);
        self::assertSame(2, $deleted2);
        $deleted3 = $repo->pruneOld(null, 3);
        self::assertSame(0, $deleted3);
        $freshCount = $db->query(
            "SELECT COUNT(*) AS `c` FROM `{$table}` WHERE `order_id` = 2001"
        );
        self::assertSame(1, (int) ($freshCount->row['c'] ?? -1));
        self::assertSame(SecurityConstants::CLEANUP_DEFAULT_BATCH_SIZE, 100);
    }

    public function testVersionRemainsFrozen(): void
    {
        self::assertSame(
            '2.0.2',
            \Opencart\System\Library\Extension\MtUniCredit\ModuleConstants::VERSION
        );
        $install = json_decode((string) file_get_contents(dirname(__DIR__) . '/install.json'), true);
        self::assertSame('2.0.2', $install['version'] ?? null);
    }

    /**
     * @param object $db
     */
    private function seedBankStatus(object $db, int $storeId, int $orderId, string $statusId, string $label): void
    {
        $table = $db->getPrefix() . PersistenceTableNames::ORDER_BANK_STATUS;
        $db->query(
            "INSERT INTO `{$table}`
                (`store_id`, `order_id`, `order_reference`, `status_id`, `status_label`, `updated_at`)
             VALUES (
                " . (int) $storeId . ",
                " . (int) $orderId . ",
                '" . $db->escape((string) $orderId) . "',
                '" . $db->escape($statusId) . "',
                '" . $db->escape($label) . "',
                '2026-01-01 00:00:00'
             )"
        );
    }
}
