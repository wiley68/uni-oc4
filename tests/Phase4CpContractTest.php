<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase4CpContractTest extends TestCase
{
    public function testAuthContractFixtureMatchesFrozenEndpoints(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/tests/fixtures/cp_auth_contract.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('/api/v1', $fixture['api_base_path']);
        self::assertSame(['unicid', 'name', 'secret'], $fixture['login']['payload_fields']);
        self::assertSame('POST', $fixture['login']['method']);
        self::assertSame('/auth/login', $fixture['login']['path']);
        self::assertSame('POST', $fixture['refresh']['method']);
        self::assertSame('/auth/refresh', $fixture['refresh']['path']);
        self::assertSame('POST', $fixture['logout']['method']);
        self::assertSame('GET', $fixture['shop']['method']);
        self::assertSame('/shop', $fixture['shop']['path']);
        self::assertSame(86400, $fixture['token']['expires_in_seconds']);
        self::assertSame(86400, $fixture['cache_ttl_seconds']);
    }

    public function testControlPanelClientHasNoOrderMethodsInPhase4(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/system/library/control_panel_client.php');
        self::assertStringNotContainsString('/orders', $source);
        self::assertStringNotContainsString('createOrder', $source);
        self::assertStringNotContainsString('updateOrderStatus', $source);
    }

    public function testLoginPayloadShapeInClient(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/system/library/control_panel_client.php');
        self::assertStringContainsString("'unicid' => \$unicid", $source);
        self::assertStringContainsString("'name' => \$this->shopName", $source);
        self::assertStringContainsString("'secret' => \$secret", $source);
    }
}
