<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\EventRegistry;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartCompatibility;
use PHPUnit\Framework\TestCase;

final class Phase1CompatibilityTest extends TestCase
{
    public function testEventSeparatorUsesDotOnOpenCart4103(): void
    {
        self::assertSame('.', OpenCartCompatibility::eventMethodSeparator('4.1.0.3'));
        self::assertSame('.', OpenCartCompatibility::eventMethodSeparator('4.0.2.0'));
        self::assertSame('|', OpenCartCompatibility::eventMethodSeparator('4.0.1.0'));
    }

    public function testEventActionUsesDotSeparatorFor4103(): void
    {
        $action = OpenCartCompatibility::eventAction(
            'extension/mt_uni_credit/event/example',
            'init',
            '4.1.0.3'
        );
        self::assertSame('extension/mt_uni_credit/event/example.init', $action);
        self::assertStringNotContainsString('|', $action);
    }

    public function testAdminRouteUsesDotMethodSuffix(): void
    {
        self::assertSame(
            ModuleConstants::ADMIN_ROUTE . '.save',
            OpenCartCompatibility::adminRoute(ModuleConstants::ADMIN_ROUTE, 'save')
        );
    }

    public function testEventRegistryIsScopedForProductAndCart(): void
    {
        $codes = EventRegistry::eventCodes();
        self::assertContains('module_mt_uni_credit_before_product_controller', $codes);
        self::assertContains('module_mt_uni_credit_after_product_view', $codes);
        self::assertContains('module_mt_uni_credit_before_cart_controller', $codes);
        self::assertContains('module_mt_uni_credit_after_cart_view', $codes);
        self::assertTrue(EventRegistry::isScopedEventCode('module_mt_uni_credit_example'));
        self::assertFalse(EventRegistry::isScopedEventCode('other_module_event'));
        self::assertStringContainsString('.init', EventRegistry::openCartEventRows('4.1.0.3')[0]['action']);
    }

    public function testControllerDoesNotEmbedVersionChecks(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/admin/controller/module/mt_uni_credit.php');
        self::assertStringNotContainsString("VERSION >= '4.0.2'", $controller);
        self::assertStringContainsString('OpenCartCompatibility::adminRoute', $controller);
    }
}
