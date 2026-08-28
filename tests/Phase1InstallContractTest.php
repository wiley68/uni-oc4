<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\EventRegistry;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use PHPUnit\Framework\TestCase;

final class Phase1InstallContractTest extends TestCase
{
    public function testInstallJsonMetadataRemainsPackageDescriptiveOnly(): void
    {
        $install = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/install.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('mt_uni_credit', $install['code']);
        self::assertSame('2.0.2', $install['version']);
        self::assertSame('module', $install['type']);
        self::assertSame(['admin/controller/module/mt_uni_credit::install'], $install['install']);
        self::assertSame(['admin/controller/module/mt_uni_credit::uninstall'], $install['uninstall']);
    }

    public function testRuntimeInstallRouteDocumentedInController(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/admin/controller/module/mt_uni_credit.php');
        self::assertStringContainsString('public function install(): void', $controller);
        self::assertStringContainsString('public function uninstall(): void', $controller);
    }

    public function testDefaultSettingsAreStable(): void
    {
        $modelSource = (string) file_get_contents(dirname(__DIR__) . '/admin/model/module/mt_uni_credit.php');
        self::assertStringContainsString("ModuleConstants::MODULE_SETTING_CODE . '_status'", $modelSource);
        self::assertSame('module_mt_uni_credit', ModuleConstants::MODULE_SETTING_CODE);
        self::assertStringContainsString('=> 0', $modelSource);
    }

    public function testEventSyncIsDeleteThenAddPerCode(): void
    {
        $modelSource = (string) file_get_contents(dirname(__DIR__) . '/admin/model/module/mt_uni_credit.php');
        self::assertStringContainsString('deleteEventByCode', $modelSource);
        self::assertStringContainsString('addEvent', $modelSource);
        self::assertContains('module_mt_uni_credit_before_product_controller', EventRegistry::eventCodes());
    }

    public function testUninstallRemovesEventsOnlyForThisExtension(): void
    {
        $modelSource = (string) file_get_contents(dirname(__DIR__) . '/admin/model/module/mt_uni_credit.php');
        self::assertStringContainsString('removeEvents', $modelSource);
        self::assertStringNotContainsString('DROP TABLE', $modelSource);
    }
}
