<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\EventRegistrationGap;
use Opencart\System\Library\Extension\MtUniCredit\EventRegistry;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartEventCallbackContract;
use PHPUnit\Framework\TestCase;

/**
 * Regression for Phase 8 Cart calculator absence: EventRegistry cart hooks must be
 * synchronized into oc_event (same triggers/actions as working Jet OC4 cart events).
 */
final class Phase8CartEventRegistrationRuntimeContractTest extends TestCase
{
    public function testEventRegistryDefinesJetParityCartTriggersAndActions(): void
    {
        $byCode = [];
        foreach (EventRegistry::openCartEventRows('4.1.0.3') as $row) {
            $byCode[$row['code']] = $row;
        }

        $before = ModuleConstants::MODULE_SETTING_CODE . '_before_cart_controller';
        $after = ModuleConstants::MODULE_SETTING_CODE . '_after_cart_view';

        self::assertArrayHasKey($before, $byCode);
        self::assertArrayHasKey($after, $byCode);

        // Exact Jet OC4 cart platform triggers (mt_jet_credit admin install).
        self::assertSame('catalog/controller/checkout/cart/before', $byCode[$before]['trigger']);
        self::assertSame('catalog/view/checkout/cart/after', $byCode[$after]['trigger']);
        self::assertSame(
            'extension/mt_uni_credit/event/mt_uni_credit_cart_controller.init',
            $byCode[$before]['action']
        );
        self::assertSame(
            'extension/mt_uni_credit/event/mt_uni_credit_cart_view.init',
            $byCode[$after]['action']
        );
        self::assertTrue($byCode[$before]['status']);
        self::assertTrue($byCode[$after]['status']);
    }

    public function testCartCallbackArityMatchesOpenCart4103DispatchContract(): void
    {
        self::assertSame(2, OpenCartEventCallbackContract::expectedArity('catalog/controller/checkout/cart/before'));
        self::assertSame(3, OpenCartEventCallbackContract::expectedArity('catalog/view/checkout/cart/after'));

        require_once __DIR__ . '/Support/OpenCartEngineStub.php';
        $controller = new \ReflectionMethod(
            \Opencart\Catalog\Controller\Extension\MtUniCredit\Event\MtUniCreditCartController::class,
            'init'
        );
        $view = new \ReflectionMethod(
            \Opencart\Catalog\Controller\Extension\MtUniCredit\Event\MtUniCreditCartView::class,
            'init'
        );
        self::assertSame(2, $controller->getNumberOfParameters());
        self::assertSame(3, $view->getNumberOfParameters());
        self::assertSame('route', $controller->getParameters()[0]->getName());
        self::assertSame('args', $controller->getParameters()[1]->getName());
        self::assertSame('output', $view->getParameters()[2]->getName());
    }

    public function testProductOnlyInstalledCodesAreDetectedAsCartGap(): void
    {
        $productOnly = [
            ModuleConstants::MODULE_SETTING_CODE . '_before_product_controller',
            ModuleConstants::MODULE_SETTING_CODE . '_after_product_view',
        ];
        $missing = EventRegistrationGap::missingCodes($productOnly);

        foreach (EventRegistrationGap::requiredCartEventCodes() as $code) {
            self::assertContains($code, $missing);
        }
        self::assertContains(
            ModuleConstants::MODULE_SETTING_CODE . '_after_checkout_success',
            $missing
        );
        self::assertSame([], EventRegistrationGap::missingCodes(EventRegistry::eventCodes()));
    }

    public function testAdminEnsureEventsSynchronizedExistsAndIndexCallsIt(): void
    {
        $model = (string) file_get_contents(dirname(__DIR__) . '/admin/model/module/mt_uni_credit.php');
        $controller = (string) file_get_contents(dirname(__DIR__) . '/admin/controller/module/mt_uni_credit.php');

        self::assertStringContainsString('function ensureEventsSynchronized', $model);
        self::assertStringContainsString('EventRegistrationGap::missingCodes', $model);
        self::assertStringContainsString('ensureEventsSynchronized()', $controller);
        // syncEvents still rewrites every EventRegistry code (including cart).
        self::assertStringContainsString('EventRegistry::eventCodes()', $model);
        self::assertStringContainsString('EventRegistry::openCartEventRows', $model);
    }

    public function testLiveOcEventContainsCartCodesWhenOpenCartDbAvailable(): void
    {
        $config = dirname(__DIR__, 2) . '/config.php';
        if (!is_file($config)) {
            self::markTestSkipped('OpenCart config.php not available');
        }

        // Isolate constants if already defined by another test.
        $hostname = $username = $password = $database = $prefix = null;
        $port = 3306;
        $lines = file($config) ?: [];
        foreach ($lines as $line) {
            if (preg_match("/define\\('DB_HOSTNAME',\\s*'([^']*)'\\)/", $line, $m)) {
                $hostname = $m[1];
            }
            if (preg_match("/define\\('DB_USERNAME',\\s*'([^']*)'\\)/", $line, $m)) {
                $username = $m[1];
            }
            if (preg_match("/define\\('DB_PASSWORD',\\s*'([^']*)'\\)/", $line, $m)) {
                $password = $m[1];
            }
            if (preg_match("/define\\('DB_DATABASE',\\s*'([^']*)'\\)/", $line, $m)) {
                $database = $m[1];
            }
            if (preg_match("/define\\('DB_PREFIX',\\s*'([^']*)'\\)/", $line, $m)) {
                $prefix = $m[1];
            }
            if (preg_match("/define\\('DB_PORT',\\s*'([^']*)'\\)/", $line, $m)) {
                $port = (int) $m[1];
            }
        }
        if ($hostname === null || $username === null || $password === null || $database === null || $prefix === null) {
            self::markTestSkipped('DB constants not parseable from config.php');
        }

        $host = $hostname === 'localhost' ? '127.0.0.1' : $hostname;
        try {
            $mysqli = @new \mysqli($host, $username, $password, $database, $port);
        } catch (\Throwable) {
            self::markTestSkipped('OpenCart DB unavailable');
        }
        if ($mysqli->connect_errno) {
            self::markTestSkipped('OpenCart DB unavailable');
        }

        $installed = [];
        $result = $mysqli->query('SELECT code FROM `' . $mysqli->real_escape_string($prefix) . "event` WHERE code LIKE 'module_mt_uni_credit%'");
        self::assertNotFalse($result);
        while ($row = $result->fetch_assoc()) {
            $installed[] = (string) $row['code'];
        }
        $mysqli->close();

        $missing = EventRegistrationGap::missingCodes($installed);
        self::assertSame(
            [],
            $missing,
            'oc_event must contain all EventRegistry codes including cart (run Admin module index/Save). Missing: '
            . implode(', ', $missing)
        );
    }
}
