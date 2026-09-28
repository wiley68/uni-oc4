<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceTableNames;
use Opencart\System\Library\Extension\MtUniCredit\ShopCacheRepository;
use PHPUnit\Framework\TestCase;

final class ShopCacheSingleFlightConcurrencyTest extends TestCase
{
    private const NOW = 1_700_000_000;

    protected function setUp(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration DB required.');
        }
        PersistenceIntegrationHarness::resetTables();
    }

    public function testEligibleStalePresentationUsesOneRemoteGet(): void
    {
        $this->seedSnapshot(self::NOW - 3600);
        $result = $this->runWorkers('presentation', 5);
        self::assertSame(1, $result['remote_gets']);
        self::assertSame(5, $result['successes']);
    }

    public function testStrictStaleSubmissionWaitsAndUsesOneRemoteGet(): void
    {
        $this->seedSnapshot(self::NOW - 3600);
        $result = $this->runWorkers('submission', 4);
        self::assertSame(1, $result['remote_gets']);
        self::assertSame(4, $result['successes']);
    }

    public function testFailedStrictSubmissionWaveFetchesOnceAndAllContendersFailClosed(): void
    {
        $this->seedSnapshot(self::NOW - 3600);
        $result = $this->runWorkers('submission', 4, 'failure');
        self::assertSame(1, $result['remote_gets']);
        self::assertSame(0, $result['successes']);
    }

    /** @dataProvider nonLkgStateProvider */
    public function testNonLkgStatesUseOneRemoteGet(string $state): void
    {
        if ($state === 'too_old') {
            $this->seedSnapshot(self::NOW - 21601);
        } elseif ($state === 'corrupt') {
            $this->seedCorruptSnapshot(self::NOW - 3600);
        }
        $result = $this->runWorkers('presentation', 4);
        self::assertSame(1, $result['remote_gets'], $state);
        self::assertSame(4, $result['successes'], $state);
    }

    /** @return iterable<string, array{string}> */
    public static function nonLkgStateProvider(): iterable
    {
        yield 'missing' => ['missing'];
        yield 'too old' => ['too_old'];
        yield 'corrupt' => ['corrupt'];
    }

    public function testOwnerConnectionLossAndScopeIsolationAndNonOwnerRelease(): void
    {
        $a = $this->newMysqli();
        $sameScope = $this->newMysqli();
        $otherScope = $this->newMysqli();
        $lockA = $this->lockName(Phase4TestHarness::TEST_STORE_ID, Phase4TestHarness::TEST_UNICID);
        $lockB = $this->lockName(Phase4TestHarness::TEST_STORE_ID + 1, Phase4TestHarness::TEST_UNICID);

        self::assertSame(1, $this->scalar($a, "SELECT GET_LOCK('{$lockA}', 0)"));
        self::assertSame(0, $this->scalar($sameScope, "SELECT GET_LOCK('{$lockA}', 0)"));
        self::assertSame(1, $this->scalar($otherScope, "SELECT GET_LOCK('{$lockB}', 0)"));
        self::assertSame(0, $this->scalar($sameScope, "SELECT RELEASE_LOCK('{$lockA}')"));

        $a->close();
        self::assertSame(1, $this->scalar($sameScope, "SELECT GET_LOCK('{$lockA}', 1)"));
        self::assertSame(1, $this->scalar($sameScope, "SELECT RELEASE_LOCK('{$lockA}')"));
        self::assertSame(1, $this->scalar($otherScope, "SELECT RELEASE_LOCK('{$lockB}')"));
    }

    private function seedSnapshot(int $expiresAt): void
    {
        $db = PersistenceIntegrationHarness::connection();
        (new ShopCacheRepository($db))->replaceValidated(
            Phase4TestHarness::TEST_STORE_ID,
            Phase4TestHarness::TEST_UNICID,
            mt_uni_credit_valid_shop_snapshot()
        );
        $table = $db->getPrefix() . PersistenceTableNames::SHOP_CACHE;
        $db->query("UPDATE `{$table}` SET `expires_at` = '" . gmdate('Y-m-d H:i:s', $expiresAt) . "'");
    }

    private function seedCorruptSnapshot(int $expiresAt): void
    {
        $db = PersistenceIntegrationHarness::connection();
        $this->seedSnapshot($expiresAt);
        $table = $db->getPrefix() . PersistenceTableNames::SHOP_CACHE;
        $db->query("UPDATE `{$table}` SET `shop_data` = '{broken'");
    }

    /** @return array{remote_gets:int, successes:int} */
    private function runWorkers(string $purpose, int $count, string $remoteResult = 'success'): array
    {
        $directory = sys_get_temp_dir() . '/mtuc-concurrency-' . bin2hex(random_bytes(5));
        mkdir($directory, 0700);
        $counter = $directory . '/count';
        $barrier = $directory . '/go';
        file_put_contents($counter, '0');
        $processes = [];
        for ($i = 0; $i < $count; ++$i) {
            $command = [PHP_BINARY, __DIR__ . '/Support/ShopCacheConcurrencyWorker.php', $purpose, $counter, $barrier, (string) self::NOW, PersistenceIntegrationHarness::connection()->getPrefix(), $remoteResult];
            $pipes = [];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        file_put_contents($barrier, 'go');
        $successes = 0;
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            $decoded = json_decode($stdout, true);
            if ($remoteResult === 'success') {
                self::assertSame(0, $status, $stderr . ' ' . $stdout);
            } else {
                self::assertSame(1, $status, $stderr . ' ' . $stdout);
            }
            if (is_array($decoded) && ($decoded['ok'] ?? false) === true) {
                ++$successes;
            }
        }
        $remoteGets = (int) trim((string) file_get_contents($counter));
        @unlink($barrier);
        @unlink($counter);
        @rmdir($directory);

        return ['remote_gets' => $remoteGets, 'successes' => $successes];
    }

    private function lockName(int $storeId, string $unicid): string
    {
        return 'mtuc_shop_' . substr(hash('sha256', $storeId . '|' . $unicid), 0, 40);
    }

    private function newMysqli(): \mysqli
    {
        require_once '/var/www/open40.avalonbg.com/config.php';
        $host = DB_HOSTNAME === 'localhost' ? '127.0.0.1' : DB_HOSTNAME;
        $mysqli = new \mysqli($host, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int) DB_PORT);
        $mysqli->set_charset('utf8mb4');

        return $mysqli;
    }

    private function scalar(\mysqli $mysqli, string $sql): int
    {
        $result = $mysqli->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_row();
        $result->free();

        return (int) ($row[0] ?? -1);
    }
}
