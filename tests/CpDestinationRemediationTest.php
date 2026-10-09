<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\IsolatedCpRuntime;
use Opencart\System\Library\Extension\MtUniCredit as U;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CpDestinationRemediationTest extends TestCase
{
    public static function validRoots(): iterable
    {
        foreach (['https://uni.avalonbg.com', 'https://cptest.ucfinonline.bg', 'https://cp.ucfinonline.bg',
            'https://future-cp.merchant.net'] as $root) {
            yield [$root];
        }
    }

    #[DataProvider('validRoots')]
    public function testCanonicalFileAloneSelectsEveryDestination(string $root): void
    {
        $result = IsolatedCpRuntime::run(<<<'PHP'
$env = new U\ModuleDeploymentEnvironment();
echo json_encode([$env->controlPanelUrl(), $env->controlPanelApiBaseUrl(), $env->controlPanelOrigin()]);
PHP, $root);
        self::assertSame([$root, $root . '/api/v1', $root . ':443'], $result);
    }

    public static function invalidRoots(): iterable
    {
        foreach (['', 'not-a-url', 'http://merchant.net', 'https://', 'https:///merchant.net',
            'https://u:p@merchant.net', 'https://merchant.net?', 'https://merchant.net?x=1',
            'https://merchant.net#', 'https://merchant.net#fragment', 'https://merchant.net/path',
            'https://merchant.net//', 'https://merchant.net:8443', 'https://127.0.0.1',
            'https://[::1]', 'https://10.0.0.1', 'https://localhost', 'https://cp.localhost',
            'https://cp.local', 'https://cp.example.com', 'https://cp.lan', 'https://cp.test', 'https://cp.internal', 'https://cp.onion',
            'https://2130706433', 'https://127.1', 'https://bad-.net', 'https://bad_name.net',
            "https://merchant.net\n/path", 'https://merchant.net\\@127.0.0.1'] as $url) {
            yield [$url];
        }
    }

    #[DataProvider('invalidRoots')]
    public function testInvalidDestinationCannotReachClientTransport(string $url): void
    {
        $result = IsolatedCpRuntime::run(<<<'PHP'
$transport = new class implements U\CpHttpTransport {
    public int $calls = 0;
    public function request(string $method, string $url, array $headers, ?array $payload): U\CpHttpResponse {
        ++$this->calls;
        throw new RuntimeException('Unexpected transport');
    }
};
$settings = new U\InMemoryModuleSettingStore();
$cipher = new U\ModuleSettingCipher(str_repeat('x', 32));
$rejected = false;
try {
    $client = new U\ControlPanelClient(new U\ModuleCredentialsRepository($settings, $cipher),
        new U\CpTokenRepository($settings, $cipher, 0), $transport, 'https://shop.merchant.net', 0);
    $client->getShop();
} catch (U\CpException) { $rejected = true; }
echo json_encode([$rejected, $transport->calls]);
PHP, $url);
        self::assertSame([true, 0], $result);
    }

    public function testNormalizationIncludesEffectivePortAndNoIndependentApiBase(): void
    {
        self::assertSame('https://cp.merchant.net', U\CpDestinationPolicy::canonicalRoot('HTTPS://CP.MERCHANT.NET:443/'));
        self::assertSame(['credentials', 'tokens', 'transport', 'shopName', 'storeId', 'clock'],
            array_map(static fn($p) => $p->getName(), (new \ReflectionMethod(U\ControlPanelClient::class, '__construct'))->getParameters()));
        self::assertCount(0, (new \ReflectionMethod(U\ModuleDeploymentEnvironment::class, '__construct'))->getParameters());
    }

    public static function forbiddenAddresses(): iterable
    {
        foreach (['0.0.0.0', '10.0.0.1', '100.64.0.1', '127.0.0.1', '169.254.169.254',
            '172.16.0.1', '192.168.0.1', '192.0.0.9', '192.0.2.1', '198.18.0.1',
            '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255', '::', '::1',
            '::ffff:8.8.8.8', '64:ff9b::808:808', 'fc00::1', 'fe80::1', 'ff02::1',
            '2001:db8::1', '2001::1', '2002:808:808::1', '3fff::1'] as $ip) {
            yield [$ip];
        }
    }

    #[DataProvider('forbiddenAddresses')]
    public function testUnsafeOrMixedDnsResultsAreRejectedBeforeCurlStarts(string $ip): void
    {
        $result = IsolatedCpRuntime::run('define("IP_FIXTURE", ' . var_export($ip, true) . ');' . <<<'PHP'
eval('namespace Opencart\\System\\Library\\Extension\\MtUniCredit; function curl_init($url) { $GLOBALS["curl_starts"]++; return \\curl_init($url); }');
$GLOBALS['curl_starts'] = 0;
$ip = IP_FIXTURE;
$query = static fn(string $host): array => [
    ['type' => 'A', 'ip' => '8.8.8.8'],
    str_contains($ip, ':') ? ['type' => 'AAAA', 'ipv6' => $ip] : ['type' => 'A', 'ip' => $ip],
];
$rejected = false;
try {
    (new U\CurlCpHttpTransport(5, 15, $query))->request('GET',
        (new U\ModuleDeploymentEnvironment())->controlPanelApiBaseUrl() . '/shop', [], null);
} catch (U\CpException) { $rejected = true; }
echo json_encode([$rejected, $GLOBALS['curl_starts']]);
PHP);
        self::assertSame([true, 0], $result);
    }

    public function testDnsAliasesAndRecordCountsAreBounded(): void
    {
        $policy = new U\CpDestinationPolicy();
        self::assertSame(['8.8.8.8', '2606:4700:4700::1111'], $policy->resolve('cp.merchant.net',
            static fn(string $host): array => $host === 'cp.merchant.net'
                ? [['type' => 'CNAME', 'target' => 'edge.merchant.net.']]
                : [['type' => 'A', 'ip' => '8.8.8.8'], ['type' => 'AAAA', 'ipv6' => '2606:4700:4700::1111']]));
        foreach ([
            static fn(string $host): array => [['type' => 'CNAME', 'target' => $host]],
            static fn(string $host): array => [['type' => 'CNAME', 'target' => 'x.' . $host]],
            static fn(string $host): array => array_fill(0, 65, ['type' => 'A', 'ip' => '8.8.8.8']),
            static fn(string $host): array => [],
        ] as $query) {
            try { $policy->resolve('cp.merchant.net', $query); self::fail('Unsafe DNS accepted'); }
            catch (U\CpException) { self::assertTrue(true); }
        }
    }

    public function testCurlPinsPublicAddressAndDisablesProxyAndRedirects(): void
    {
        $result = IsolatedCpRuntime::run(<<<'PHP'
eval('namespace Opencart\\System\\Library\\Extension\\MtUniCredit;
function curl_setopt_array($handle, $options) { $GLOBALS["options"] = $options; return \\curl_setopt_array($handle, $options); }
function curl_exec($handle) { return "{}"; }
function curl_getinfo($handle, $key) { return 200; }');
$query = static fn(string $host): array => [['type' => 'AAAA', 'ipv6' => '2606:4700:4700::1111']];
$env = new U\ModuleDeploymentEnvironment();
(new U\CurlCpHttpTransport(5, 15, $query))->request('GET', $env->controlPanelApiBaseUrl() . '/shop', [], null);
$o = $GLOBALS['options'];
echo json_encode([$o[CURLOPT_RESOLVE], $o[CURLOPT_PROXY], $o[CURLOPT_NOPROXY], $o[CURLOPT_FOLLOWLOCATION],
    $o[CURLOPT_SSL_VERIFYPEER], $o[CURLOPT_SSL_VERIFYHOST], $o[CURLOPT_PROTOCOLS], $o[CURLOPT_CONNECTTIMEOUT], $o[CURLOPT_TIMEOUT]]);
PHP);
        self::assertSame([['uni.avalonbg.com:443:[2606:4700:4700::1111]'], '', '*', false, true, 2, CURLPROTO_HTTPS, 5, 15], $result);
    }

    public function testAllRuntimeClientConstructionUsesCanonicalFactory(): void
    {
        $root = dirname(__DIR__);
        $hits = [];
        foreach (['system', 'admin', 'catalog'] as $area) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $area));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') continue;
                $source = file_get_contents($file->getPathname());
                if (str_contains($source, 'new ControlPanelClient(')) $hits[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        self::assertSame(['system/library/cp_service_factory.php'], $hits);
        foreach (['admin/model/module/mt_uni_credit.php', 'catalog/model/module/mt_uni_credit_product.php',
            'catalog/model/module/mt_uni_credit_cart.php', 'catalog/model/module/mt_uni_credit_checkout.php',
            'catalog/controller/event/mt_uni_credit_home_controller.php', 'catalog/controller/event/mt_uni_credit_home_view.php'] as $path) {
            self::assertStringContainsString('CpServiceFactory::create(', file_get_contents($root . '/' . $path));
        }
        $policy = file_get_contents($root . '/system/library/cp_destination_policy.php');
        foreach (['uni.avalonbg.com', 'cptest.ucfinonline.bg', 'cp.ucfinonline.bg'] as $host) self::assertStringNotContainsString($host, $policy);
    }
}
