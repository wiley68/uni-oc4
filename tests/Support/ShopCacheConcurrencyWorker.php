<?php

declare(strict_types=1);

use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\CpHttpResponse;
use Opencart\System\Library\Extension\MtUniCredit\CpHttpTransport;
use Opencart\System\Library\Extension\MtUniCredit\MysqliDbConnection;

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/fixtures/cp_shop_snapshot.php';

$purpose = (string) ($argv[1] ?? 'presentation');
$counterFile = (string) ($argv[2] ?? '');
$barrierFile = (string) ($argv[3] ?? '');
$now = (int) ($argv[4] ?? time());
$prefix = (string) ($argv[5] ?? 'oc_mtuni_it_');
$remoteResult = (string) ($argv[6] ?? 'success');

if ($counterFile === '' || $barrierFile === '') {
    fwrite(STDERR, "missing worker arguments\n");
    exit(2);
}

require '/var/www/open40.avalonbg.com/config.php';
$host = DB_HOSTNAME === 'localhost' ? '127.0.0.1' : DB_HOSTNAME;
$mysqli = new mysqli($host, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int) DB_PORT);
$mysqli->set_charset('utf8mb4');
$db = new MysqliDbConnection($mysqli, $prefix);

$transport = new class($counterFile, $remoteResult) implements CpHttpTransport {
    public function __construct(private string $counterFile, private string $remoteResult) {}

    public function request(string $method, string $url, array $headers, ?array $payload): CpHttpResponse
    {
        if (str_contains($url, '/auth/login')) {
            return new CpHttpResponse(200, json_encode(Phase4TestHarness::loginSuccessPayload(), JSON_THROW_ON_ERROR));
        }
        if (str_contains($url, '/shop')) {
            $handle = fopen($this->counterFile, 'c+');
            if ($handle === false) {
                throw new RuntimeException('counter unavailable');
            }
            flock($handle, LOCK_EX);
            $current = trim((string) stream_get_contents($handle));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) ((int) $current + 1));
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
            usleep(700000);

            if ($this->remoteResult === 'failure') {
                return new CpHttpResponse(503, json_encode([
                    'success' => false,
                    'error' => 'temporary',
                    'message' => 'temporary',
                    'data' => new stdClass(),
                ], JSON_THROW_ON_ERROR));
            }

            return new CpHttpResponse(200, json_encode(Phase4TestHarness::shopSuccessPayload(), JSON_THROW_ON_ERROR));
        }

        throw new RuntimeException('unexpected request');
    }
};

$deadline = microtime(true) + 10.0;
while (!is_file($barrierFile)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "barrier timeout\n");
        exit(3);
    }
    usleep(10000);
}

try {
    $services = Phase4TestHarness::services(
        $transport,
        null,
        $db,
        Phase4TestHarness::TEST_STORE_ID,
        $now
    );
    $shop = $purpose === 'submission'
        ? $services['shopConfiguration']->getForSubmission()
        : $services['shopConfiguration']->getForPresentation();
    fwrite(STDOUT, json_encode(['ok' => true, 'unicid' => $shop['unicid'] ?? ''], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode(['ok' => false, 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR));
    exit(1);
}
