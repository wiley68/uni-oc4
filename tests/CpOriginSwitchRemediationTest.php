<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\IsolatedCpRuntime;
use PHPUnit\Framework\TestCase;

final class CpOriginSwitchRemediationTest extends TestCase
{
    private function runFixture(string $code): mixed
    {
        $support = __DIR__ . '/Support/CpOriginFixtureDb.php';
        $fixtures = __DIR__ . '/fixtures/certificates/';
        $prefix = 'require ' . var_export($support, true) . '; $fixtures = ' . var_export($fixtures, true) . ';';
        $prefix .= <<<'PHP'
$switch = static function(string $url): void {
    file_put_contents(U\ExtensionRoot::path() . '/config/environment.php', '<?php return ' . var_export(['control_panel_url' => $url], true) . ';');
    clearstatcache();
};
$settings = new U\InMemoryModuleSettingStore();
$cipher = new U\ModuleSettingCipher(str_repeat('x', 32));
$db = new \MtUniCredit\Tests\Support\CpOriginFixtureDb();
PHP;
        return IsolatedCpRuntime::run($prefix . $code);
    }

    public function testTokenSwitchLogsInBeforeSendingAnyAuthenticatedRequest(): void
    {
        $result = $this->runFixture(<<<'PHP'
$tokens = new U\CpTokenRepository($settings, $cipher, 0);
$tokens->save('old-origin-token', 'Bearer', time() + 3600);
$oldMatches = $tokens->getAccessToken() === 'old-origin-token';
$credentials = new U\ModuleCredentialsRepository($settings, $cipher);
$credentials->setUnicid(0, 'fixture-unicid');
$credentials->saveSecret(0, 'fixture-secret');
$switch('https://cptest.ucfinonline.bg');
$foreign = $tokens->getAccessToken();
$transport = new class implements U\CpHttpTransport {
    public array $requests = [];
    public function request(string $method, string $url, array $headers, ?array $payload): U\CpHttpResponse {
        $this->requests[] = [$url, $headers['Authorization'] ?? null];
        $data = str_ends_with($url, '/auth/login')
            ? ['access_token' => 'fresh-origin-token', 'token_type' => 'Bearer', 'expires_in' => 3600,
                'shop' => ['id' => 1, 'name' => 'https://shop.merchant.net', 'unicid' => 'fixture-unicid']]
            : ['id' => 1];
        return new U\CpHttpResponse(200, json_encode(['success' => true, 'error' => null, 'message' => '', 'data' => $data]));
    }
};
(new U\ControlPanelClient($credentials, $tokens, $transport, 'https://shop.merchant.net', 0))->getShop();
echo json_encode([$oldMatches, $foreign, $transport->requests, $tokens->getAccessToken() === 'fresh-origin-token']);
PHP);
        self::assertSame([true, null, [
            ['https://cptest.ucfinonline.bg/api/v1/auth/login', null],
            ['https://cptest.ucfinonline.bg/api/v1/shop', 'Bearer fresh-origin-token'],
        ], true], $result);
    }

    public function testDeploymentChangeDuringLoginDiscardsResponseWithoutRelabelingToken(): void
    {
        self::assertSame([true, null, 1], $this->runFixture(<<<'PHP'
$tokens = new U\CpTokenRepository($settings, $cipher, 0);
$credentials = new U\ModuleCredentialsRepository($settings, $cipher);
$credentials->setUnicid(0, 'fixture-unicid');
$credentials->saveSecret(0, 'fixture-secret');
$transport = new class($switch) implements U\CpHttpTransport {
    public int $calls = 0;
    public function __construct(private $switch) {}
    public function request(string $method, string $url, array $headers, ?array $payload): U\CpHttpResponse {
        ++$this->calls;
        ($this->switch)('https://cptest.ucfinonline.bg');
        return new U\CpHttpResponse(200, json_encode(['success' => true, 'error' => null, 'message' => '',
            'data' => ['access_token' => 'old-origin-token', 'token_type' => 'Bearer', 'expires_in' => 3600,
                'shop' => ['id' => 1, 'name' => 'https://shop.merchant.net', 'unicid' => 'fixture-unicid']]]));
    }
};
$blocked = false;
try { (new U\ControlPanelClient($credentials, $tokens, $transport, 'https://shop.merchant.net', 0))->getShop(); }
catch (U\CpException) { $blocked = true; }
echo json_encode([$blocked, $tokens->getAccessToken(), $transport->calls]);
PHP));
    }

    public function testFreshCacheLkgAndCredentialHydrationNeverCrossOrigins(): void
    {
        $result = $this->runFixture(<<<'PHP'
$now = strtotime('2026-10-09 00:00:00 UTC');
$cache = new U\ShopCacheRepository($db, new U\PersistenceClock(static fn(): int => $now));
$cache->replaceValidated(0, 'fixture-unicid', ['id' => 1]);
$sameFresh = $cache->findFresh(0, 'fixture-unicid') !== null;
$sameMetadata = $cache->findMetadata(0, 'fixture-unicid') !== null;
$smart = new U\SmartUcfCredentialRepository($settings, $cipher);
$smart->saveCompletePair(0, 'fixture-user', 'fixture-password');
$samePair = $smart->hasCompleteReadablePair(0);
$saved = $db->cacheRow;
$switch('https://cp.ucfinonline.bg');
$foreignFresh = $cache->findFresh(0, 'fixture-unicid');
$foreignMetadata = $cache->findMetadata(0, 'fixture-unicid');
$db->cacheRow['expires_at'] = gmdate('Y-m-d H:i:s', $now - 60);
$foreignLkg = $cache->findLatest(0, 'fixture-unicid');
$foreignPair = $smart->hasCompleteReadablePair(0);
$hydrated = $smart->hydrateShopSnapshot(0, ['id' => 2]);
$unchanged = $settings->get(0, U\SmartUcfCredentialRepository::USER_SETTING) !== null;
$switch('https://uni.avalonbg.com');
$sameLkg = $cache->findMetadata(0, 'fixture-unicid')['lkg_eligible'] ?? false;
echo json_encode([$sameFresh, $sameMetadata, $samePair, $foreignFresh, $foreignMetadata, $foreignLkg,
    $foreignPair, $hydrated, $unchanged, $sameLkg]);
PHP);
        self::assertSame([true, true, true, null, null, null, false, ['id' => 2], true, true], $result);
    }

    public function testForeignAndLegacyCacheTriggerRefreshWithoutUsingLkg(): void
    {
        $fixture = var_export(__DIR__ . '/fixtures/cp_shop_snapshot.php', true);
        $code = 'require ' . $fixture . ';' . <<<'PHP'
$cache = new U\ShopCacheRepository($db);
$unicid = '123e4567-e89b-12d3-a456-426614174000';
$credentials = new U\ModuleCredentialsRepository($settings, $cipher);
$credentials->setUnicid(0, $unicid);
$tokens = new U\CpTokenRepository($settings, $cipher, 0);
$tokens->save('fixture-token', 'Bearer', time() + 3600);
$transport = new class implements U\CpHttpTransport {
    public int $calls = 0;
    public function request(string $method, string $url, array $headers, ?array $payload): U\CpHttpResponse {
        ++$this->calls;
        throw new U\CpConnectionException('Simulated temporary failure');
    }
};
$client = new U\ControlPanelClient($credentials, $tokens, $transport, 'https://shop.merchant.net', 0);
$smart = new U\SmartUcfCredentialRepository($settings, $cipher);
$service = new U\ShopConfigurationService($credentials, $cache, $client, $tokens, 0, $smart,
    new U\SmartUcfCredentialPersistence($smart, $cipher, $cache, $db), null, $db);
$shop = \mt_uni_credit_valid_shop_snapshot(['unicid' => $unicid]);
$cache->replaceValidated(0, $unicid, $shop);
$db->cacheRow['expires_at'] = gmdate('Y-m-d H:i:s', time() - 60);
$sameLkg = $service->getForPresentation() !== [];
$foreign = $db->cacheRow;
$envelope = json_decode($db->cacheRow['shop_data'], true);
$envelope['cp_origin'] = 'https://cptest.ucfinonline.bg:443';
$db->cacheRow['shop_data'] = json_encode($envelope);
$foreignRejected = false;
try { $service->getForPresentation(); } catch (U\CpConnectionException) { $foreignRejected = true; }
$db->cacheRow['shop_data'] = json_encode($shop);
$legacyRejected = false;
try { $service->getForPresentation(); } catch (U\CpConnectionException) { $legacyRejected = true; }
echo json_encode([$sameLkg, $foreignRejected, $legacyRejected, $transport->calls]);
PHP;
        self::assertSame([true, true, true, 3], $this->runFixture($code));
    }

    public function testLegacyEncryptedSecretsAndSnapshotsAreUnusable(): void
    {
        self::assertSame([null, false, null, null], $this->runFixture(<<<'PHP'
$settings->set(0, U\CpTokenRepository::ACCESS_TOKEN, $cipher->encrypt('legacy-token'));
$settings->set(0, U\CpTokenRepository::EXPIRES_AT, (string) (time() + 3600));
$settings->set(0, U\SmartUcfCredentialRepository::USER_SETTING, $cipher->encrypt('legacy-user'));
$settings->set(0, U\SmartUcfCredentialRepository::PASSWORD_SETTING, $cipher->encrypt('legacy-password'));
$db->cacheRow = ['shop_data' => json_encode(['id' => 1]), 'fetched_at' => '2026-10-09 00:00:00', 'expires_at' => '2099-01-01 00:00:00'];
$cache = new U\ShopCacheRepository($db);
echo json_encode([(new U\CpTokenRepository($settings, $cipher, 0))->getAccessToken(),
    (new U\SmartUcfCredentialRepository($settings, $cipher))->hasCompleteReadablePair(0),
    $cache->findFresh(0, 'fixture-unicid'), $cache->findLatest(0, 'fixture-unicid')]);
PHP));
    }

    public function testCertificateFailOpenRequiresMatchingOriginAndHashes(): void
    {
        self::assertSame([true, true, true, true], $this->runFixture(<<<'PHP'
$directory = U\ExtensionRoot::path() . '/keys';
mkdir($directory);
$store = new U\CertificateLocalStore($directory);
$cert = file_get_contents($fixtures . 'matching_cert.pem');
$key = file_get_contents($fixtures . 'matching_key.pem');
$store->replacePair($cert, $key, ['certificate_sha256' => hash('sha256', $cert), 'private_key_sha256' => hash('sha256', $key)]);
$tokens = new U\CpTokenRepository($settings, $cipher, 0);
$tokens->save('fixture-token', 'Bearer', time() + 3600);
$transport = new class implements U\CpHttpTransport {
    public function request(string $method, string $url, array $headers, ?array $payload): U\CpHttpResponse { throw new U\CpConnectionException('Temporary failure'); }
};
$credentials = new U\ModuleCredentialsRepository($settings, $cipher);
$sync = static fn(): U\CertificateSynchronizer => new U\CertificateSynchronizer(
    new U\ControlPanelClient($credentials, $tokens, $transport, 'https://shop.merchant.net', 0), $store);
$lease = $sync()->ensureCurrent(); $same = is_file($lease->certificatePath()); $lease->release();
$switch('https://cptest.ucfinonline.bg');
$tokens->save('new-token', 'Bearer', time() + 3600);
$foreignBlocked = false;
try { $sync()->ensureCurrent(); } catch (U\CertificateSyncException) { $foreignBlocked = true; }
$historical = file_get_contents($directory . '/avalon_cert.pem') === $cert;
unlink($directory . '/' . U\CertificateLocalStore::STATE_FILENAME);
$legacyBlocked = false;
try { $sync()->ensureCurrent(); } catch (U\CertificateSyncException) { $legacyBlocked = true; }
echo json_encode([$same, $foreignBlocked, $historical, $legacyBlocked]);
PHP));
    }

    public function testForeignAndLegacyDurableOperationsArePreservedAndBlocked(): void
    {
        self::assertSame([6, 0, true, 1], $this->runFixture(<<<'PHP'
U\CpOriginGuard::ensureSchema($db);
U\CpOriginGuard::ensureSchema($db);
$oldOrigin = U\CpOriginGuard::current();
$switch('https://cptest.ucfinonline.bg');
$blocked = 0;
$transport = new class implements U\CpHttpTransport {
    public int $calls = 0;
    public function request(string $method, string $url, array $headers, ?array $payload): U\CpHttpResponse {
        ++$this->calls;
        throw new RuntimeException('Cross-origin transport must not occur');
    }
};
$client = new U\ControlPanelClient(new U\ModuleCredentialsRepository($settings, $cipher),
    new U\CpTokenRepository($settings, $cipher, 0), $transport, 'https://shop.merchant.net', 0);
$lifecycle = new U\ControlPanelOrderLifecycleService(new U\FinancingAttemptRepository($db),
    new U\OperationLockRepository($db), $client, new U\ControlPanelOrderPayloadBuilder());
$submission = U\ResumeSubmissionFactory::create(U\OperationEntryPoint::PRODUCT, 0, null, str_repeat('a', 64), 17);
$preserved = true;
foreach ([$oldOrigin, null] as $origin) {
    foreach ([U\FinancingAttemptState::ORDER_CREATED, U\FinancingAttemptState::CP_FAILED_RETRYABLE, U\FinancingAttemptState::CP_CREATED] as $state) {
        $row = ['attempt_id' => 1, 'store_id' => 0, 'order_id' => 17, 'cp_origin' => $origin, 'state' => $state,
            'control_panel_order_id' => 123, 'cp_payload' => '{"order_id":"17","currency":"EUR"}'];
        $db->attemptRow = $row;
        try {
            if ($state === U\FinancingAttemptState::CP_CREATED) {
                U\FinancingControlPanelCompletion::resumeExistingCp($lifecycle, 1, $submission, 17, 123, []);
            } else {
                $lifecycle->submitOrRecover(new U\FinancingAttemptContext($row), $submission, 17, [], str_repeat('b', 32));
            }
        }
        catch (U\ProductFinancingFlowException $e) { if ($e->errorCode() === U\CpOriginGuard::RECONCILIATION_REQUIRED) ++$blocked; }
        $preserved = $preserved && $db->attemptRow === $row;
    }
}
foreach ($db->queries as $sql) if (str_starts_with($sql, 'UPDATE') || str_starts_with($sql, 'DELETE')) $preserved = false;
echo json_encode([$blocked, $transport->calls, $preserved, $db->alters]);
PHP));
    }

    public function testPendingAndConfirmedStatusFromForeignOrLegacyOriginCannotPatch(): void
    {
        self::assertSame([4, 0, true], $this->runFixture(<<<'PHP'
$oldOrigin = U\CpOriginGuard::current();
$switch('https://cp.ucfinonline.bg');
$port = new class implements U\ControlPanelOrderStatusPort {
    public int $calls = 0;
    public function updateOrderStatus(string $order, string $status, string $id): array { ++$this->calls; return []; }
};
$service = new U\ControlPanelStatusSyncService(new U\ControlPanelStatusSyncRepository($db), $port);
$blocked = 0; $preserved = true;
foreach ([$oldOrigin, null] as $origin) {
    foreach ([U\ControlPanelStatusSyncStates::PENDING, U\ControlPanelStatusSyncStates::CONFIRMED] as $state) {
        $row = ['attempt_id' => 1, 'cp_origin' => $origin, 'cp_status_sync_state' => $state,
            'cp_status_sync_status_id' => 'bank_sent_process1', 'cp_status_sync_status' => 'Sent'];
        $db->attemptRow = $row;
        if ($service->retryPending(1, '17') === U\ControlPanelStatusSyncStates::RECONCILIATION_REQUIRED) ++$blocked;
        $preserved = $preserved && $db->attemptRow === $row;
    }
}
echo json_encode([$blocked, $port->calls, $preserved]);
PHP));
    }
}
