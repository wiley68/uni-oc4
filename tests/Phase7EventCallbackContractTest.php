<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\EventRegistry;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartEventCallbackContract;
use MtUniCredit\Tests\Support\SourceRoot;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes OpenCart 4.1.0.3 event argument contracts and Phase 7 callback arity.
 */
final class Phase7EventCallbackContractTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/Support/OpenCartEngineStub.php';
    }

    public function testOpenCart4103LoaderSuppliesDocumentedArgumentLists(): void
    {
        $root = SourceRoot::openCart();
        if ($root === null) {
            self::markTestSkipped('OPENCART_ROOT is not available');
        }

        $loader = (string) file_get_contents($root . '/system/engine/loader.php');
        self::assertStringContainsString(
            "\$this->event->trigger('controller/' . \$trigger . '/before', [&\$route, &\$args]);",
            $loader
        );
        self::assertStringContainsString(
            "\$this->event->trigger('controller/' . \$trigger . '/after', [&\$route, &\$args, &\$output]);",
            $loader
        );
        self::assertStringContainsString(
            "\$this->event->trigger('view/' . \$trigger . '/before', [&\$route, &\$data, &\$code, &\$output]);",
            $loader
        );
        self::assertStringContainsString(
            "\$this->event->trigger('view/' . \$trigger . '/after', [&\$route, &\$data, &\$output]);",
            $loader
        );
    }

    public function testContractArityMatchesOpenCartFamilies(): void
    {
        self::assertSame(2, OpenCartEventCallbackContract::expectedArity('catalog/controller/product/product/before'));
        self::assertSame(3, OpenCartEventCallbackContract::expectedArity('catalog/controller/product/product/after'));
        self::assertSame(4, OpenCartEventCallbackContract::expectedArity('catalog/view/product/product/before'));
        self::assertSame(3, OpenCartEventCallbackContract::expectedArity('catalog/view/product/product/after'));
        self::assertSame(2, OpenCartEventCallbackContract::expectedArity('controller/product/product/before'));
    }

    public function testEveryPhase7EventCallbackArityMatchesTriggerContract(): void
    {
        $root = dirname(__DIR__);
        foreach (EventRegistry::definitions() as $definition) {
            $expected = OpenCartEventCallbackContract::expectedArity($definition['trigger']);
            $file = $root . '/' . $this->controllerBaseDir($definition['trigger']) . '/'
                . $this->relativeControllerPath($definition['controller']) . '.php';
            self::assertFileExists($file, $definition['code']);
            require_once $file;
            $class = $this->controllerClassFromRoute($definition['controller'], $definition['trigger']);
            self::assertTrue(class_exists($class), $class);
            $method = new \ReflectionMethod($class, $definition['method']);
            self::assertLessThanOrEqual(
                $expected,
                $method->getNumberOfRequiredParameters(),
                $definition['code'] . ' requires more arguments than OpenCart supplies for ' . $definition['trigger']
            );
            self::assertSame(
                $expected,
                $method->getNumberOfParameters(),
                $definition['code'] . ' parameter count must match OpenCart event arity for ' . $definition['trigger']
            );
        }
    }

    public function testProductControllerBeforeAcceptsExactlyTwoArguments(): void
    {
        $method = new \ReflectionMethod(
            \Opencart\Catalog\Controller\Extension\MtUniCredit\Event\MtUniCreditProductController::class,
            'init'
        );
        self::assertSame(2, $method->getNumberOfRequiredParameters());
        self::assertSame(2, $method->getNumberOfParameters());
        $params = $method->getParameters();
        self::assertSame('route', $params[0]->getName());
        self::assertSame('args', $params[1]->getName());
        self::assertTrue($params[0]->isPassedByReference());
        self::assertTrue($params[1]->isPassedByReference());
    }

    public function testProductViewAfterAcceptsExactlyThreeArguments(): void
    {
        $method = new \ReflectionMethod(
            \Opencart\Catalog\Controller\Extension\MtUniCredit\Event\MtUniCreditProductView::class,
            'init'
        );
        self::assertSame(3, $method->getNumberOfRequiredParameters());
        self::assertSame(3, $method->getNumberOfParameters());
        $params = $method->getParameters();
        self::assertSame('route', $params[0]->getName());
        self::assertSame('data', $params[1]->getName());
        self::assertSame('output', $params[2]->getName());
    }

    public function testCallbacksAreInvokableWithOpenCartArgumentShape(): void
    {
        foreach (EventRegistry::definitions() as $definition) {
            $family = OpenCartEventCallbackContract::familyFromTrigger($definition['trigger']);
            self::assertNotNull($family, $definition['trigger']);
            $sample = OpenCartEventCallbackContract::sampleArgsForFamily($family);
            $file = dirname(__DIR__) . '/' . $this->controllerBaseDir($definition['trigger']) . '/'
                . $this->relativeControllerPath($definition['controller']) . '.php';
            require_once $file;
            $class = $this->controllerClassFromRoute($definition['controller'], $definition['trigger']);
            $method = new \ReflectionMethod($class, $definition['method']);
            self::assertSame(count($sample), $method->getNumberOfParameters(), $definition['code']);

            $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $route = $sample[0];
            $second = $sample[1];
            $args = [&$route, &$second];
            if (count($sample) >= 3) {
                $third = $sample[2];
                $args[] = &$third;
            }
            if (count($sample) >= 4) {
                $fourth = $sample[3];
                $args[] = &$fourth;
            }

            try {
                $method->invokeArgs($instance, $args);
            } catch (\ArgumentCountError $exception) {
                self::fail($definition['code'] . ' rejected OpenCart argument list: ' . $exception->getMessage());
            } catch (\Throwable) {
                // Missing registry services are expected without full OpenCart bootstrap.
            }
        }
    }

    public function testJetProductControllerBeforeAlsoUsesTwoArguments(): void
    {
        $jet = SourceRoot::jet();
        if ($jet === null) {
            self::markTestSkipped('JET_OC4_ROOT is not available');
        }
        $source = (string) file_get_contents($jet . '/catalog/controller/event/mt_jet_credit_product_controller.php');
        self::assertMatchesRegularExpression('/function init\s*\(\s*&?\$route\s*,\s*&?\$\w+\s*\)/', $source);
        self::assertDoesNotMatchRegularExpression('/function init\s*\([^)]*,[^)]*,/', $source);
    }

    private function controllerClassFromRoute(string $route, string $trigger = ''): string
    {
        // extension/mt_uni_credit/event/mt_uni_credit_product_controller
        // → Opencart\Catalog\Controller\Extension\MtUniCredit\Event\MtUniCreditProductController
        // admin triggers → Opencart\Admin\Controller\...
        $parts = explode('/', $route);
        self::assertSame('extension', $parts[0]);
        $extension = str_replace('_', '', ucwords($parts[1], '_'));
        $type = ucfirst($parts[2]);
        $name = str_replace('_', '', ucwords($parts[3], '_'));
        $area = str_starts_with($trigger, 'admin/') ? 'Admin' : 'Catalog';

        return 'Opencart\\' . $area . '\\Controller\\Extension\\' . $extension . '\\' . $type . '\\' . $name;
    }

    private function controllerBaseDir(string $trigger): string
    {
        return str_starts_with($trigger, 'admin/') ? 'admin/controller' : 'catalog/controller';
    }

    private function relativeControllerPath(string $route): string
    {
        // extension/mt_uni_credit/event/foo → event/foo (under catalog|admin/controller)
        $parts = explode('/', $route);
        array_shift($parts); // extension
        array_shift($parts); // mt_uni_credit

        return implode('/', $parts);
    }
}
