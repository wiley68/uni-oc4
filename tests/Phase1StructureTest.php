<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use PHPUnit\Framework\TestCase;

final class Phase1StructureTest extends TestCase
{
    public function testRequiredPhase1FilesExist(): void
    {
        $root = dirname(__DIR__);
        $required = [
            'admin/controller/module/mt_uni_credit.php',
            'admin/model/module/mt_uni_credit.php',
            'admin/view/template/module/mt_uni_credit.twig',
            'admin/language/en-gb/module/mt_uni_credit.php',
            'admin/language/bg-bg/module/mt_uni_credit.php',
            'system/library/module_constants.php',
            'system/library/open_cart_compatibility.php',
            'system/library/event_registry.php',
            'install.json',
        ];

        foreach ($required as $relative) {
            self::assertFileExists($root . '/' . $relative, $relative);
        }
    }

    public function testAdminNamespacesMatchOpenCartLoaderConvention(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/admin/controller/module/mt_uni_credit.php');
        $model = (string) file_get_contents(dirname(__DIR__) . '/admin/model/module/mt_uni_credit.php');

        self::assertStringContainsString('namespace Opencart\\Admin\\Controller\\Extension\\MtUniCredit\\Module;', $controller);
        self::assertStringContainsString('namespace Opencart\\Admin\\Model\\Extension\\MtUniCredit\\Module;', $model);
        self::assertStringContainsString(ModuleConstants::ADMIN_ROUTE, $controller);
    }

    public function testFuturePaymentNamingIsCentralized(): void
    {
        self::assertSame('mt_uni_credit', ModuleConstants::PAYMENT_CODE);
        self::assertSame('mt_uni_credit.mt_uni_credit', ModuleConstants::PAYMENT_OPTION_CODE);
        self::assertSame('extension/mt_uni_credit/payment/mt_uni_credit', 'extension/' . ModuleConstants::EXTENSION_CODE . '/payment/' . ModuleConstants::PAYMENT_CODE);
    }

    public function testCatalogProductTreeExistsForPhase7(): void
    {
        $root = dirname(__DIR__);
        self::assertDirectoryExists($root . '/catalog/controller/module');
        self::assertFileExists($root . '/catalog/controller/module/mt_uni_credit_product.php');
    }

    public function testCatalogPaymentTreeExistsForPhase9(): void
    {
        $root = dirname(__DIR__);
        self::assertFileExists($root . '/catalog/controller/payment/mt_uni_credit.php');
        self::assertFileExists($root . '/catalog/model/payment/mt_uni_credit.php');
        self::assertFileExists($root . '/admin/controller/payment/mt_uni_credit.php');
    }
}
