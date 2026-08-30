<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\CertificateLocalPaths;
use Opencart\System\Library\Extension\MtUniCredit\CertificateLocalStore;
use Opencart\System\Library\Extension\MtUniCredit\CertificatePairValidator;
use Opencart\System\Library\Extension\MtUniCredit\CertificateSyncException;
use Opencart\System\Library\Extension\MtUniCredit\CertificateSynchronizer;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelClient;
use Opencart\System\Library\Extension\MtUniCredit\CpTokenRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\InMemoryModuleSettingStore;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleSettingCipher;
use Opencart\System\Library\Extension\MtUniCredit\MtlsPrivateKeyPassphraseProvider;
use Opencart\System\Library\Extension\MtUniCredit\OrderBankStatusRepository;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfFailureClassifier;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleRepository;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleStates;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfSessionCoordinator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

final class Phase11ACertificateSyncTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }
    }

    public function testEmptyKeysDirectoryDownloadsBothFiles(): void
    {
        [$synchronizer, $transport, $directory, $certificate, $privateKey] = $this->synchronizer();
        $this->queueMetadataAndBundle($transport, $certificate, $privateKey);

        $lease = $synchronizer->ensureCurrent();
        self::assertSame($certificate, file_get_contents($directory . '/' . CertificateLocalPaths::CERT_FILENAME));
        self::assertSame($privateKey, file_get_contents($directory . '/' . CertificateLocalPaths::KEY_FILENAME));
        self::assertSame(1, $this->bundleRequestCount($transport));
        self::assertSame(0640, fileperms($directory . '/' . CertificateLocalPaths::CERT_FILENAME) & 0777);
        self::assertSame(0600, fileperms($directory . '/' . CertificateLocalPaths::KEY_FILENAME) & 0777);
        $lease->release();
    }

    public function testMatchingCertificateWithMissingKeyDownloadsPair(): void
    {
        [$synchronizer, $transport, $directory, $certificate, $privateKey] = $this->synchronizer();
        file_put_contents($directory . '/' . CertificateLocalPaths::CERT_FILENAME, $certificate);
        $this->queueMetadataAndBundle($transport, $certificate, $privateKey);

        $lease = $synchronizer->ensureCurrent();
        self::assertFileExists($directory . '/' . CertificateLocalPaths::KEY_FILENAME);
        self::assertSame(1, $this->bundleRequestCount($transport));
        $lease->release();
    }

    public function testCurrentPairDoesNotDownloadBundle(): void
    {
        [$synchronizer, $transport, $directory, $certificate, $privateKey] = $this->synchronizer();
        $this->writePair($directory, $certificate, $privateKey);
        $this->queueMetadata($transport, $certificate, $privateKey);

        $lease = $synchronizer->ensureCurrent();
        self::assertSame(0, $this->bundleRequestCount($transport));
        $lease->release();
    }

    public function testCertificateChecksumChangeRefreshesPair(): void
    {
        [$synchronizer, $transport, $directory, $certificate, $privateKey] = $this->synchronizer();
        $this->writePair($directory, $certificate . "\n", $privateKey);
        $this->queueMetadataAndBundle($transport, $certificate, $privateKey);

        $lease = $synchronizer->ensureCurrent();
        self::assertSame($certificate, file_get_contents($directory . '/' . CertificateLocalPaths::CERT_FILENAME));
        self::assertSame(1, $this->bundleRequestCount($transport));
        $lease->release();
    }

    public function testPrivateKeyChecksumChangeRefreshesPair(): void
    {
        [$synchronizer, $transport, $directory, $certificate, $privateKey] = $this->synchronizer();
        $this->writePair($directory, $certificate, $privateKey . "\n");
        $this->queueMetadataAndBundle($transport, $certificate, $privateKey);

        $lease = $synchronizer->ensureCurrent();
        self::assertSame($privateKey, file_get_contents($directory . '/' . CertificateLocalPaths::KEY_FILENAME));
        self::assertSame(1, $this->bundleRequestCount($transport));
        $lease->release();
    }

    public function testMetadataFailureWithoutLocalPairThrowsSyncException(): void
    {
        [$synchronizer, $transport] = $this->synchronizer();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(503, ['success' => false, 'error' => 'temporarily_unavailable']);

        try {
            $synchronizer->ensureCurrent();
            self::fail('Expected certificate sync failure.');
        } catch (CertificateSyncException $exception) {
            self::assertSame(CertificateSyncException::REASON_CP_TRANSPORT, $exception->reason());
        }
        self::assertSame(0, $this->bundleRequestCount($transport));
    }

    public function testBundleDownloadFailureThrowsSyncException(): void
    {
        [$synchronizer, $transport, , $certificate, $privateKey] = $this->synchronizer();
        $this->queueMetadata($transport, $certificate, $privateKey);
        $transport->enqueueJson(503, ['success' => false, 'error' => 'temporarily_unavailable']);

        try {
            $synchronizer->ensureCurrent();
            self::fail('Expected certificate bundle failure.');
        } catch (CertificateSyncException $exception) {
            self::assertSame(CertificateSyncException::REASON_REFRESH_FAILED, $exception->reason());
        }
        self::assertSame(1, $this->bundleRequestCount($transport));
    }

    public function testCoordinatorSyncFailureIsRetryableWithoutBankStatusOrSmartUcfCall(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration database is unavailable.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        (new PersistenceSchemaInstaller($db))->installAll();

        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempts = new FinancingAttemptRepository($db);
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId,
            $submission->entryPoint,
            hash('sha256', 'phase11-cert-sync-operation'),
            hash('sha256', 'phase11-cert-sync-actor'),
            hash('sha256', 'phase11-cert-sync-selection')
        );
        $attemptId = (int) $row['attempt_id'];
        $attempts->attachOrder($attemptId, 921);

        [$synchronizer, $transport, , , , $controlPanel] = $this->synchronizer();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(503, ['success' => false, 'error' => 'temporarily_unavailable']);
        $smartUcf = new class {
            public int $calls = 0;

            public function createSession(): array
            {
                ++$this->calls;

                return [];
            }
        };
        $coordinator = new SmartUcfSessionCoordinator(
            new SmartUcfLifecycleRepository($db),
            $smartUcf,
            new SmartUcfFailureClassifier(),
            new OrderBankStatusRepository($db),
            $controlPanel,
            $synchronizer
        );

        $result = $coordinator->run(
            $attemptId,
            mt_uni_credit_valid_shop_snapshot(['uni_sertificat' => 1]),
            $submission,
            921,
            922
        );

        self::assertTrue($result->isFailed());
        self::assertTrue($result->isRetryable());
        self::assertSame(0, $smartUcf->calls);
        $lifecycle = (new SmartUcfLifecycleRepository($db))->findByAttempt($attemptId);
        self::assertSame(SmartUcfLifecycleStates::FAILED, $lifecycle['smartucf_state']);
        self::assertSame(1, (int) $lifecycle['smartucf_retryable']);
        self::assertStringStartsWith(
            SmartUcfSessionCoordinator::ERROR_CREDENTIALS_SYNC_FAILED,
            (string) $lifecycle['smartucf_error_class']
        );
        $status = $db->query(
            'SELECT COUNT(*) AS `total` FROM `' . $db->getPrefix()
            . 'mt_uni_credit_order_bank_status` WHERE `order_id` = 921'
        );
        self::assertSame(0, (int) $status->row['total']);
        self::assertSame(0, count(array_filter(
            $transport->requests,
            static fn(array $request): bool => $request['method'] === 'PATCH'
                && str_contains($request['url'], '/orders/status')
        )));
    }

    /**
     * @return array{CertificateSynchronizer, FakeCpHttpTransport, string, string, string, ControlPanelClient}
     */
    private function synchronizer(): array
    {
        $directory = sys_get_temp_dir() . '/mt-uni-cert-sync-' . bin2hex(random_bytes(8));
        mkdir($directory, 0750);
        $this->temporaryDirectories[] = $directory;
        $fixtures = __DIR__ . '/fixtures/certificates';
        $certificate = (string) file_get_contents($fixtures . '/matching_cert.pem');
        $privateKey = (string) file_get_contents($fixtures . '/matching_key.pem');
        $passphrases = new MtlsPrivateKeyPassphraseProvider(
            null,
            static fn(): array => ['passphrase' => 'phase2-fixture-secret']
        );
        $validator = new CertificatePairValidator($passphrases);
        $store = new CertificateLocalStore($directory, $validator);
        $transport = new FakeCpHttpTransport();
        $controlPanel = $this->controlPanel($transport);

        return [
            new CertificateSynchronizer($controlPanel, $store, $validator),
            $transport,
            $directory,
            $certificate,
            $privateKey,
            $controlPanel,
        ];
    }

    private function controlPanel(FakeCpHttpTransport $transport): ControlPanelClient
    {
        $settings = new InMemoryModuleSettingStore();
        Phase4TestHarness::prepareCredentials($settings, Phase4TestHarness::TEST_STORE_ID);
        $cipher = Phase4TestHarness::cipher();

        return new ControlPanelClient(
            new ModuleCredentialsRepository($settings, $cipher),
            new CpTokenRepository($settings, $cipher, Phase4TestHarness::TEST_STORE_ID),
            $transport,
            Phase4TestHarness::TEST_SHOP_URL,
            Phase4TestHarness::TEST_STORE_ID,
            'https://cp.example.test/api/v1',
            static fn(): int => 1_700_000_000
        );
    }

    private function queueMetadata(FakeCpHttpTransport $transport, string $certificate, string $privateKey): void
    {
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, [
            'success' => true,
            'data' => [
                'available' => true,
                'ssl_revision' => 'revision-1',
                'certificate_sha256' => hash('sha256', $certificate),
                'private_key_sha256' => hash('sha256', $privateKey),
            ],
        ]);
    }

    private function queueMetadataAndBundle(
        FakeCpHttpTransport $transport,
        string $certificate,
        string $privateKey
    ): void {
        $this->queueMetadata($transport, $certificate, $privateKey);
        $transport->enqueueJson(200, [
            'success' => true,
            'data' => [
                'available' => true,
                'ssl_revision' => 'revision-1',
                'certificate_sha256' => hash('sha256', $certificate),
                'private_key_sha256' => hash('sha256', $privateKey),
                'certificate_pem' => $certificate,
                'private_key_pem' => $privateKey,
            ],
        ]);
    }

    private function writePair(string $directory, string $certificate, string $privateKey): void
    {
        file_put_contents($directory . '/' . CertificateLocalPaths::CERT_FILENAME, $certificate);
        file_put_contents($directory . '/' . CertificateLocalPaths::KEY_FILENAME, $privateKey);
    }

    private function bundleRequestCount(FakeCpHttpTransport $transport): int
    {
        return count(array_filter(
            $transport->requests,
            static fn(array $request): bool => str_ends_with($request['url'], '/ssl/certificate/bundle')
        ));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
