<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\IsolatedCpRuntime;
use MtUniCredit\Tests\Support\SourceRoot;
use PHPUnit\Framework\TestCase;

final class CentralizedAdvertisingControlTest extends TestCase
{
    private function fixture(string $code): mixed
    {
        $prefix = '';
        foreach (['Support/CpOriginFixtureDb.php', 'Support/FakeCpHttpTransport.php',
            'Support/Phase4TestHarness.php', 'fixtures/cp_shop_snapshot.php'] as $file) {
            $prefix .= 'require_once ' . var_export(__DIR__ . '/' . $file, true) . ';';
        }

        return IsolatedCpRuntime::run($prefix . $code);
    }

    public function testRealHomepageHooksIgnoreHistoricalSettingAndKeepEligibility(): void
    {
        $openCart = SourceRoot::openCart();
        if ($openCart === null) {
            self::markTestSkipped('OpenCart engine source required (no application bootstrap or DB).');
        }
        $prefix = '';
        foreach ([$openCart . '/system/engine/registry.php', $openCart . '/system/engine/controller.php',
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_home_controller.php',
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_home_view.php'] as $file) {
            $prefix .= 'require_once ' . var_export($file, true) . ';';
        }
        $result = $this->fixture($prefix . <<<'PHP'
define('DB_PREFIX', 'fixture_');
define('DB_PASSWORD', 'synthetic-advertising-test-secret');
$rows = [];
foreach ([0, 1, null] as $oldSetting) {
    foreach ([0, 1] as $cpStatus) {
        foreach (['common/home', '', 'product/product', 'product/category', 'checkout/cart', 'checkout/checkout'] as $page) {
            foreach (['enabled', 'disabled', 'missing-unicid', 'inactive-shop'] as $restriction) {
                U\HomepageAdvertisingContextResolver::resetRequestCache();
                $unicid = $restriction === 'missing-unicid' ? '' : 'synthetic-shop';
                $db = new class($unicid, $cpStatus, $restriction) {
                    private array $snapshot;
                    public function __construct(private string $unicid, int $cp, string $restriction) {
                        $this->snapshot = ['shop_data' => json_encode(['cp_origin' => U\CpOriginGuard::current(),
                            'data' => ['uni_status' => $restriction === 'inactive-shop' ? 0 : 1,
                                'uni_container_status' => $cp]]),
                            'fetched_at' => gmdate('Y-m-d H:i:s'), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)];
                    }
                    public function query(string $sql): object {
                        if (!str_starts_with(ltrim($sql), 'SELECT')) throw new \RuntimeException('Unexpected write');
                        $row = str_contains($sql, 'shop_cache') ? $this->snapshot
                            : (str_contains($sql, "'module_mt_uni_credit_unicid'") ? ['value' => $this->unicid] : null);
                        return (object) ['num_rows' => $row === null ? 0 : 1, 'row' => $row ?? [], 'rows' => $row === null ? [] : [$row]];
                    }
                    public function escape(string $value): string { return addslashes($value); }
                };
                $values = ['module_mt_uni_credit_status' => $restriction === 'disabled' ? 0 : 1, 'config_store_id' => 0];
                if ($oldSetting !== null) $values['module_mt_uni_credit_advertising_enabled'] = $oldSetting;
                $config = new class($values) {
                    public array $reads = [];
                    public function __construct(private array $values) {}
                    public function get(string $key): mixed { $this->reads[] = $key; return $this->values[$key] ?? null; }
                };
                $document = new class {
                    public array $styles = [], $scripts = [];
                    public function addStyle(string $url): void { $this->styles[] = $url; }
                    public function addScript(string $url, string $position): void { $this->scripts[] = [$url, $position]; }
                };
                $loader = new class {
                    public array $views = [];
                    public function language(string $route): void {}
                    public function view(string $route, array $data): string { $this->views[] = [$route, $data]; return '<synthetic-advertisement>'; }
                };
                $registry = new \Opencart\System\Engine\Registry();
                foreach (['config' => $config, 'db' => $db, 'document' => $document, 'load' => $loader,
                    'request' => (object) ['get' => ['route' => $page], 'server' => []],
                    'language' => new class { public function get(string $key): string { return $key; } }] as $key => $value) {
                    $registry->set($key, $value);
                }
                $args = [];
                $route = $page === '' ? 'common/home' : $page;
                (new \Opencart\Catalog\Controller\Extension\MtUniCredit\Event\MtUniCreditHomeController($registry))->beforeHome($route, $args);
                $route = 'common/footer';
                $output = '<footer>';
                (new \Opencart\Catalog\Controller\Extension\MtUniCredit\Event\MtUniCreditHomeView($registry))->afterFooter($route, $args, $output);
                $rows[] = [$oldSetting, $cpStatus, $page, $restriction,
                    count($document->styles), count($document->scripts), $output,
                    in_array('module_mt_uni_credit_advertising_enabled', $config->reads, true),
                    $document->scripts[0][1] ?? null, count($loader->views)];
            }
        }
    }
}
echo json_encode($rows);
PHP);
        self::assertCount(144, $result);
        foreach ($result as [$old, $cp, $page, $restriction, $styles, $scripts, $output, $readsOld, $position, $views]) {
            $visible = $cp === 1 && in_array($page, ['', 'common/home'], true) && $restriction === 'enabled';
            $case = json_encode([$old, $cp, $page, $restriction]);
            self::assertSame($visible ? 2 : 0, $styles, $case);
            self::assertSame($visible ? 1 : 0, $scripts, $case);
            self::assertSame($visible ? '<footer><synthetic-advertisement>' : '<footer>', $output, $case);
            self::assertFalse($readsOld, $case);
            self::assertSame($visible ? 'footer' : null, $position, $case);
            self::assertSame($visible ? 1 : 0, $views, $case);
        }
    }

    public function testPresentationCacheRefreshPushAndSafeFallbackRemainAuthoritative(): void
    {
        $result = $this->fixture(<<<'PHP'
$now = 1700000000;
$db = new \MtUniCredit\Tests\Support\CpOriginFixtureDb();
$settings = new U\InMemoryModuleSettingStore();
$cipher = new U\ModuleSettingCipher(str_repeat('s', 32));
$credentials = new U\ModuleCredentialsRepository($settings, $cipher);
$unicid = '123e4567-e89b-12d3-a456-426614174000';
$credentials->setUnicid(0, $unicid);
$tokens = new U\CpTokenRepository($settings, $cipher, 0);
$tokens->save('synthetic-token', 'Bearer', $now + 200000);
$transport = new \MtUniCredit\Tests\Support\FakeCpHttpTransport();
$client = new U\ControlPanelClient($credentials, $tokens, $transport, 'https://shop.example.test', 0, static fn() => $now);
$cache = new U\ShopCacheRepository($db, new U\PersistenceClock(static fn() => $now));
$smart = new U\SmartUcfCredentialRepository($settings, $cipher);
$service = new U\ShopConfigurationService($credentials, $cache, $client, $tokens, 0, $smart,
    new U\SmartUcfCredentialPersistence($smart, $cipher, $cache, $db), null, $db,
    new U\PersistenceClock(static fn() => $now));
$resolver = new U\HomepageAdvertisingContextResolver(new U\HomepageAdvertisingGate(),
    new U\HomepageAdvertisingPresenter(new U\HomepageAdvertisingGate()), $service, $credentials, 0);
$resolve = static function() use ($resolver) {
    U\HomepageAdvertisingContextResolver::resetRequestCache();
    return $resolver->resolve(true, true, false, 'synthetic-logo.svg') !== null;
};
$snapshot = static function(int $flag): array {
    $shop = mt_uni_credit_valid_shop_snapshot(['uni_proces' => 1, 'uni_container_status' => $flag]);
    unset($shop['uni_user'], $shop['uni_password']);
    return $shop;
};
$results = [];
$cache->replaceValidated(0, $unicid, $snapshot(1));
$results['fresh'] = [$resolve(), count($transport->requests)];
$db->cacheRow['expires_at'] = gmdate('Y-m-d H:i:s', $now - 1);
$transport->enqueueJson(200, \MtUniCredit\Tests\Support\Phase4TestHarness::shopSuccessPayload($snapshot(0)));
$results['expired-refresh-off'] = [$resolve(), count($transport->requests)];
$results['ttl'] = $service->getMetadata()['expires_at'];
$service->replaceSnapshot($unicid, $snapshot(1));
$results['push-on'] = [$resolve(), count($transport->requests)];
$db->cacheRow['expires_at'] = gmdate('Y-m-d H:i:s', $now - 1);
$bad = $snapshot(0);
unset($bad['kop']);
try { $service->replaceSnapshot($unicid, $bad); $results['invalid-push'] = false; }
catch (U\ShopSnapshotValidationException) { $results['invalid-push'] = true; }
$service->replaceSnapshot($unicid, $snapshot(0));
$results['push-off'] = [$resolve(), count($transport->requests), $service->getMetadata()['expires_at']];
$transport->enqueueJson(200, \MtUniCredit\Tests\Support\Phase4TestHarness::shopSuccessPayload($snapshot(1)));
$service->refreshRemote();
$results['manual-on'] = [$resolve(), count($transport->requests)];
foreach ([1, 0] as $flag) {
    $cache->replaceValidated(0, $unicid, $snapshot($flag));
    $db->cacheRow['expires_at'] = gmdate('Y-m-d H:i:s', $now - U\SecurityConstants::SHOP_CACHE_LKG_SECONDS);
    $transport->enqueueTimeout();
    $results['lkg-' . $flag] = $resolve();
}
$cache->replaceValidated(0, $unicid, $snapshot(1));
$db->cacheRow['expires_at'] = gmdate('Y-m-d H:i:s', $now - U\SecurityConstants::SHOP_CACHE_LKG_SECONDS - 1);
$transport->enqueueTimeout();
$results['too-old'] = $resolve();
$db->cacheRow = null;
$transport->enqueueTimeout();
$results['missing'] = $resolve();
$cache->replaceValidated(0, $unicid, $snapshot(1));
$db->cacheRow['expires_at'] = gmdate('Y-m-d H:i:s', $now - 1);
$transport->enqueue(200, '{invalid-json');
$results['invalid-response'] = $resolve();
echo json_encode($results);
PHP);
        self::assertSame([
            'fresh' => [true, 0],
            'expired-refresh-off' => [false, 1],
            'ttl' => gmdate('Y-m-d H:i:s', 1_700_000_000 + 86400),
            'push-on' => [true, 1],
            'invalid-push' => true,
            'push-off' => [false, 1, gmdate('Y-m-d H:i:s', 1_700_000_000 + 86400)],
            'manual-on' => [true, 2],
            'lkg-1' => true,
            'lkg-0' => false,
            'too-old' => false,
            'missing' => false,
            'invalid-response' => false,
        ], $result);
    }

    public function testAdminDefaultAndSaveAllowlistRetiresOnlyAdvertising(): void
    {
        $root = SourceRoot::openCart();
        if ($root === null) {
            self::markTestSkipped('OpenCart model source required (no install/uninstall hooks).');
        }
        $prefix = '';
        foreach ([$root . '/system/engine/registry.php', $root . '/system/engine/model.php', $root . '/system/engine/controller.php',
            dirname(__DIR__) . '/admin/controller/module/mt_uni_credit.php',
            dirname(__DIR__) . '/admin/model/module/mt_uni_credit.php'] as $file) {
            $prefix .= 'require_once ' . var_export($file, true) . ';';
        }
        $result = $this->fixture($prefix . <<<'PHP'
$registry = new \Opencart\System\Engine\Registry();
$settings = new U\InMemoryModuleSettingStore();
$credentials = new U\ModuleCredentialsRepository($settings, new U\ModuleSettingCipher(str_repeat('s', 32)));
$credentials->setUnicid(0, 'synthetic-shop');
$registry->set('cp_services', (object) ['services' => ['credentials' => $credentials]]);
$model = new class($registry) extends \Opencart\Admin\Model\Extension\MtUniCredit\Module\MtUniCredit {
    public function createCpServices(): array { return $this->registry->get('cp_services')->services; }
    public function syncEvents(): void {}
};
$stored = new class {
    public array $values = [];
    public function getSetting(string $code, int $store): array { return $this->values; }
    public function editSetting(string $code, array $payload, int $store): void { $this->values = $payload; }
};
$registry->set('model_setting_setting', $stored);
$registry->set('model_extension_mt_uni_credit_module_mt_uni_credit', $model);
$registry->set('config', new class { public function get(string $key): int { return 0; } });
$registry->set('load', new class {
    public function model(string $route): void {}
    public function language(string $route): void {}
});
$registry->set('language', new class { public function get(string $key): string { return $key; } });
$registry->set('user', new class { public function hasPermission(string $type, string $route): bool { return true; } });
$response = new class {
    public string $output = '';
    public function addHeader(string $header): void {}
    public function setOutput(string $output): void { $this->output = $output; }
};
$registry->set('response', $response);
$saved = [];
foreach ([0, 1, null] as $old) {
    $stored->values = ['module_mt_uni_credit_unicid' => 'synthetic-shop', 'module_mt_uni_credit_secret' => 'synthetic-preserved-value'];
    if ($old !== null) $stored->values['module_mt_uni_credit_advertising_enabled'] = $old;
    $registry->set('request', (object) ['post' => [
        'module_mt_uni_credit_unicid' => 'synthetic-shop', 'module_mt_uni_credit_status' => '1',
        'module_mt_uni_credit_advertising_enabled' => $old === 1 ? '0' : '1',
        'module_mt_uni_credit_debug_enabled' => '1', 'module_mt_uni_credit_product_button_action' => 'buy',
        'module_mt_uni_credit_button_top_spacing' => '25',
    ]]);
    (new \Opencart\Admin\Controller\Extension\MtUniCredit\Module\MtUniCredit($registry))->save();
    $saved[] = [$stored->values, json_decode($response->output, true)];
}
echo json_encode([$model->getDefaultSettings(), $saved]);
PHP);
        self::assertSame([
            'module_mt_uni_credit_status' => 0,
            'module_mt_uni_credit_unicid' => '',
            'module_mt_uni_credit_debug_enabled' => 0,
            'module_mt_uni_credit_product_button_action' => 'add_to_cart',
            'module_mt_uni_credit_button_top_spacing' => 0,
        ], $result[0]);
        foreach ($result[1] as $index => [$values, $response]) {
            $old = [0, 1, null][$index];
            if ($old === null) {
                self::assertArrayNotHasKey('module_mt_uni_credit_advertising_enabled', $values);
            } else {
                self::assertSame($old, $values['module_mt_uni_credit_advertising_enabled']);
            }
            self::assertSame('synthetic-preserved-value', $values['module_mt_uni_credit_secret']);
            self::assertSame(1, $values['module_mt_uni_credit_status']);
            self::assertSame(1, $values['module_mt_uni_credit_debug_enabled']);
            self::assertSame('buy', $values['module_mt_uni_credit_product_button_action']);
            self::assertSame(25, $values['module_mt_uni_credit_button_top_spacing']);
            self::assertSame(['success' => 'text_success'], $response);
        }
        $controller = (string) file_get_contents(dirname(__DIR__) . '/admin/controller/module/mt_uni_credit.php');
        self::assertStringContainsString('$allowed = $this->model_extension_mt_uni_credit_module_mt_uni_credit->getDefaultSettings()', $controller);
        foreach (['admin/view/template/module/mt_uni_credit.twig', 'admin/controller/module/mt_uni_credit.php',
            'admin/language/bg-bg/module/mt_uni_credit.php', 'admin/language/en-gb/module/mt_uni_credit.php'] as $file) {
            self::assertStringNotContainsString('advertising_enabled', (string) file_get_contents(dirname(__DIR__) . '/' . $file));
        }
    }
}
