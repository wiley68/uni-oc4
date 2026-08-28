<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FixtureLoader;
use MtUniCredit\Tests\Support\OpenCart4103ActionParser;
use MtUniCredit\Tests\Support\SourceRoot;
use PHPUnit\Framework\TestCase;

final class OpencartRouteCompatibilityTest extends TestCase
{
    public function testActionParserSplitsOnDotOnly(): void
    {
        $examples = FixtureLoader::load('opencart_routing.json')['examples'];

        $dot = OpenCart4103ActionParser::parse($examples['dot_splits_method']['route']);
        self::assertSame($examples['dot_splits_method']['controller'], $dot['controller']);
        self::assertSame($examples['dot_splits_method']['method'], $dot['method']);

        $pipe = OpenCart4103ActionParser::parse($examples['pipe_does_not_split_in_Action']['route']);
        self::assertSame($examples['pipe_does_not_split_in_Action']['controller'], $pipe['controller']);
        self::assertSame($examples['pipe_does_not_split_in_Action']['method'], $pipe['method']);
    }

    public function testFourOneOhThreeUsesDotForEvents(): void
    {
        $fixture = FixtureLoader::load('opencart_routing.json');
        self::assertSame('.', $fixture['event_method_separator_on_4103']);
        self::assertTrue($fixture['http_route_pipe_normalized_to_dot']);
        self::assertTrue($fixture['event_action_pipe_not_normalized']);
        self::assertSame("VERSION >= '4.0.2' ? '.' : '|'", $fixture['jet_separator_rule']);
        self::assertTrue(version_compare('4.1.0.3', '4.0.2', '>='));
    }

    public function testOpenCartCoreSourceMatchesFrozenRoutingAssumptions(): void
    {
        $root = SourceRoot::openCart();
        if ($root === null) {
            self::markTestSkipped('OPENCART_ROOT is not available');
        }

        $index = (string) file_get_contents($root . '/index.php');
        self::assertStringContainsString("define('VERSION', '4.1.0.3')", $index);

        $action = (string) file_get_contents($root . '/system/engine/action.php');
        self::assertStringContainsString("\$pos = strrpos(\$route, '.');", $action);
        self::assertStringNotContainsString("strrpos(\$route, '|')", $action);

        $loader = (string) file_get_contents($root . '/system/engine/loader.php');
        self::assertStringContainsString("str_replace('|', '.', \$route)", $loader);

        $framework = (string) file_get_contents($root . '/system/framework.php');
        self::assertStringContainsString("str_replace('|', '.', \$request->get['route'])", $framework);

        $events = (string) file_get_contents($root . '/catalog/controller/startup/event.php');
        self::assertStringContainsString('new \\Opencart\\System\\Engine\\Action($result[\'action\'])', $events);
        self::assertStringNotContainsString("str_replace('|', '.', \$result['action'])", $events);

        $factory = (string) file_get_contents($root . '/system/engine/factory.php');
        self::assertStringContainsString("preg_replace('/[^a-zA-Z0-9_\\/]/', '', \$route)", $factory);
    }

    public function testJetRegistersDotSeparatorOnThisVersion(): void
    {
        $jet = SourceRoot::jet();
        if ($jet === null) {
            self::markTestSkipped('JET_OC4_ROOT is not available');
        }

        $controller = (string) file_get_contents($jet . '/admin/controller/module/mt_jet_credit.php');
        self::assertStringContainsString("\$jet_separator = (VERSION >= '4.0.2') ? '.' : '|';", $controller);
        self::assertStringContainsString("\$this->event_product_controller . \$jet_separator . 'init'", $controller);

        $install = json_decode((string) file_get_contents($jet . '/install.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('mt_jet_credit', $install['code']);
        self::assertSame('module', $install['type']);
    }
}
