<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\CpException;
use Opencart\System\Library\Extension\MtUniCredit\CpHttpException;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceClock;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceTableNames;
use Opencart\System\Library\Extension\MtUniCredit\ShopCacheRepository;
use Opencart\System\Library\Extension\MtUniCredit\ShopSnapshotValidationException;
use PHPUnit\Framework\TestCase;

final class ShopCacheLifecycleMatrixTest extends TestCase
{
    private const NOW = 1_700_000_000;

    protected function setUp(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required.');
        }
        PersistenceIntegrationHarness::resetTables();
    }

    public function testFreshPresentationIsLocalOnly(): void
    {
        [$stack, $transport] = $this->seedFresh();
        $before = count($transport->requests);
        self::assertSame(Phase4TestHarness::TEST_UNICID, $stack['shopConfiguration']->getForPresentation()['unicid']);
        self::assertCount($before, $transport->requests);
    }

    public function testStalePresentationRefreshesAndReplacesSnapshot(): void
    {
        [$stack, $transport] = $this->seedFresh();
        $this->expireAt(self::NOW - 1);
        $updated = mt_uni_credit_valid_shop_snapshot(['uni_container_txt1' => 'refreshed']);
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload($updated));
        self::assertSame('refreshed', $stack['shopConfiguration']->getForPresentation()['uni_container_txt1']);
        self::assertTrue($stack['shopConfiguration']->getMetadata()['is_fresh']);
    }

    /** @dataProvider transientProvider */
    public function testEligibleStalePresentationUsesLkg(string $kind): void
    {
        [$stack, $transport] = $this->seedFresh();
        $this->expireAt(self::NOW - 21600);
        $kind === 'timeout' ? $transport->enqueueTimeout() : $this->enqueueCanonicalFailure($transport, 503, 'temporary');
        self::assertSame(Phase4TestHarness::TEST_UNICID, $stack['shopConfiguration']->getForPresentation()['unicid']);
    }

    /** @return iterable<string, array{string}> */
    public static function transientProvider(): iterable
    {
        yield 'timeout' => ['timeout'];
        yield '5xx' => ['5xx'];
    }

    public function testTooOldPresentationFailsClosed(): void
    {
        [$stack, $transport] = $this->seedFresh();
        $this->expireAt(self::NOW - 21601);
        $transport->enqueueTimeout();
        $this->expectException(CpException::class);
        $stack['shopConfiguration']->getForPresentation();
    }

    public function testSubmissionNeverUsesEligibleLkgOnTimeout(): void
    {
        [$stack, $transport] = $this->seedFresh();
        $this->expireAt(self::NOW - 3600);
        $transport->enqueueTimeout();
        $this->expectException(CpException::class);
        $stack['shopConfiguration']->getForSubmission();
    }

    public function testStaleSubmissionUsesSuccessfulCurrentRefresh(): void
    {
        [$stack, $transport] = $this->seedFresh();
        $this->expireAt(self::NOW - 3600);
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload(mt_uni_credit_valid_shop_snapshot(['uni_container_txt1' => 'strict'])));
        self::assertSame('strict', $stack['shopConfiguration']->getForSubmission()['uni_container_txt1']);
    }

    public function testAuthoritativeFailurePurgesAndCannotResurrect(): void
    {
        [$stack, $transport] = $this->seedFresh();
        $this->expireAt(self::NOW - 3600);
        $this->enqueueCanonicalFailure($transport, 403, 'revoked');
        try {
            $stack['shopConfiguration']->getForPresentation();
            self::fail('Expected Class B failure.');
        } catch (CpHttpException) {
        }
        self::assertNull($stack['shopConfiguration']->getMetadata());
        self::assertFalse($stack['tokens']->hasToken());
        $transport->enqueueTimeout();
        try {
            $stack['shopConfiguration']->getForPresentation();
            self::fail('Purged state must not resurrect.');
        } catch (CpException) {
            self::assertNull($stack['shopConfiguration']->getMetadata());
        }
    }

    public function testClassC422PreservesRowAndTokenThenLaterTransientMayUseLkg(): void
    {
        [$stack, $transport] = $this->seedFresh();
        $this->expireAt(self::NOW - 3600);
        $before = $this->rawRow();
        $this->enqueueCanonicalFailure($transport, 422, 'invalid_configuration');
        try {
            $stack['shopConfiguration']->getForPresentation();
            self::fail('Expected Class C failure.');
        } catch (CpHttpException $exception) {
            self::assertFalse($exception->isTransient());
        }
        self::assertSame($before, $this->rawRow());
        self::assertTrue($stack['tokens']->hasToken());
        $transport->enqueueTimeout();
        self::assertSame(Phase4TestHarness::TEST_UNICID, $stack['shopConfiguration']->getForPresentation()['unicid']);
    }

    /** @dataProvider invalidResponseProvider */
    public function testInvalidResponsePreservesKnownGoodWithoutSameAttemptLkg(string $kind): void
    {
        [$stack, $transport] = $this->seedFresh();
        $this->expireAt(self::NOW - 3600);
        $before = $this->rawRow();
        if ($kind === 'malformed') {
            $transport->enqueue(200, '{bad-json');
        } else {
            $bad = mt_uni_credit_valid_shop_snapshot();
            unset($bad['kop']);
            $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload($bad));
        }
        try {
            $stack['shopConfiguration']->getForPresentation();
            self::fail('Invalid response must fail the current request.');
        } catch (\Throwable $exception) {
            self::assertTrue($exception instanceof CpException || $exception instanceof ShopSnapshotValidationException);
        }
        self::assertSame($before, $this->rawRow());
        self::assertTrue($stack['tokens']->hasToken());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidResponseProvider(): iterable
    {
        yield 'malformed JSON' => ['malformed'];
        yield 'invalid schema' => ['schema'];
    }

    public function testMissingSuccessPersistsAndMissingFailureFailsClosed(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        $stack = Phase4TestHarness::services($transport, null, PersistenceIntegrationHarness::connection(), Phase4TestHarness::TEST_STORE_ID, self::NOW);
        self::assertSame(Phase4TestHarness::TEST_UNICID, $stack['shopConfiguration']->getForPresentation()['unicid']);
        self::assertNotNull($stack['shopConfiguration']->getMetadata());

        PersistenceIntegrationHarness::resetTables();
        $failed = new FakeCpHttpTransport();
        $failed->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $failed->enqueueTimeout();
        $stack = Phase4TestHarness::services($failed, null, PersistenceIntegrationHarness::connection(), Phase4TestHarness::TEST_STORE_ID, self::NOW);
        $this->expectException(CpException::class);
        $stack['shopConfiguration']->getForPresentation();
    }

    public function testPushSuccessResetsTtlAndInvalidPushPreservesRow(): void
    {
        [$stack] = $this->seedFresh();
        $this->expireAt(self::NOW - 100);
        $pushed = mt_uni_credit_valid_shop_snapshot(['uni_container_txt1' => 'push']);
        self::assertTrue($stack['shopConfiguration']->replaceSnapshot(Phase4TestHarness::TEST_UNICID, $pushed));
        self::assertSame('push', $stack['shopConfiguration']->getForPresentation()['uni_container_txt1']);
        self::assertTrue($stack['shopConfiguration']->getMetadata()['is_fresh']);
        $before = $this->rawRow();
        unset($pushed['kop']);
        try {
            $stack['shopConfiguration']->replaceSnapshot(Phase4TestHarness::TEST_UNICID, $pushed);
            self::fail('Invalid push accepted.');
        } catch (ShopSnapshotValidationException) {
        }
        self::assertSame($before, $this->rawRow());
    }

    public function testCleanupRetainsThroughExactUsableUntilThenPurgesAfter(): void
    {
        [$stack] = $this->seedFresh();
        $this->expireAt(self::NOW - 21600);
        $repo = new ShopCacheRepository(PersistenceIntegrationHarness::connection(), new PersistenceClock(static fn(): int => self::NOW));
        self::assertSame(0, $repo->deleteExpiredBatch());
        self::assertNotNull($stack['shopConfiguration']->getMetadata());
        $this->expireAt(self::NOW - 21601);
        self::assertSame(1, $repo->deleteExpiredBatch());
        self::assertNull($stack['shopConfiguration']->getMetadata());
    }

    public function testMetadataProvidesSecretFreeLifecycleDiagnostics(): void
    {
        [$stack] = $this->seedFresh();
        $this->expireAt(self::NOW - 123);
        $metadata = $stack['shopConfiguration']->getMetadata();
        self::assertNotNull($metadata);
        self::assertFalse($metadata['is_fresh']);
        self::assertSame(123, $metadata['stale_age_seconds']);
        self::assertTrue($metadata['lkg_eligible']);
        self::assertSame(gmdate('Y-m-d H:i:s', self::NOW - 123 + 21600), $metadata['usable_until']);
        $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
        foreach (['token', 'secret', 'password', 'certificate', 'private_key', 'unicid'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($encoded));
        }
    }

    /** @return array{array<string,mixed>, FakeCpHttpTransport} */
    private function seedFresh(): array
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(200, Phase4TestHarness::shopSuccessPayload());
        $stack = Phase4TestHarness::services($transport, null, PersistenceIntegrationHarness::connection(), Phase4TestHarness::TEST_STORE_ID, self::NOW);
        $stack['client']->login();
        $stack['shopConfiguration']->refreshRemote();

        return [$stack, $transport];
    }

    private function expireAt(int $timestamp): void
    {
        $db = PersistenceIntegrationHarness::connection();
        $table = $db->getPrefix() . PersistenceTableNames::SHOP_CACHE;
        $db->query("UPDATE `{$table}` SET `expires_at` = '" . gmdate('Y-m-d H:i:s', $timestamp) . "'");
    }

    /** @return array<string,mixed> */
    private function rawRow(): array
    {
        $db = PersistenceIntegrationHarness::connection();
        $table = $db->getPrefix() . PersistenceTableNames::SHOP_CACHE;
        $result = $db->query("SELECT * FROM `{$table}` LIMIT 1");

        return $result->row;
    }

    private function enqueueCanonicalFailure(FakeCpHttpTransport $transport, int $status, string $error): void
    {
        $transport->enqueueJson($status, [
            'success' => false,
            'error' => $error,
            'message' => $error,
            'data' => new \stdClass(),
        ]);
    }
}
