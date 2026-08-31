<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase8ScopeGuardTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_MARKERS = [
        'SmartUcfSession',
        'SmartUCF',
        'Process1',
        'Process2',
        'financing_snapshot',
        'bank_redirect',
    ];

    /** @var list<string> */
    private const REQUIRED_FILES = [
        '/catalog/controller/module/mt_uni_credit_cart.php',
        '/catalog/model/module/mt_uni_credit_cart.php',
        '/catalog/controller/event/mt_uni_credit_cart_controller.php',
        '/catalog/controller/event/mt_uni_credit_cart_view.php',
        '/catalog/view/javascript/mt_uni_credit_cart.js',
        '/catalog/view/stylesheet/mt_uni_credit_cart.css',
        '/catalog/view/template/module/mt_uni_credit_cart_calculator.twig',
        '/catalog/view/template/module/mt_uni_credit_cart_modal.twig',
        '/system/library/cart_financing_submission_service.php',
        '/system/library/cart_fingerprint.php',
        '/system/library/cart_operation_identity.php',
        '/system/library/standard_theme_cart_placement.php',
        '/docs/PHASE8.md',
    ];

    public function testPhase8FilesExist(): void
    {
        $root = dirname(__DIR__);
        foreach (self::REQUIRED_FILES as $relative) {
            self::assertFileExists($root . $relative, $relative);
        }
    }

    public function testCartEventsRegistered(): void
    {
        $events = \Opencart\System\Library\Extension\MtUniCredit\EventRegistry::definitions();
        self::assertGreaterThanOrEqual(4, count($events));
        $triggers = array_column($events, 'trigger');
        self::assertContains('catalog/controller/checkout/cart/before', $triggers);
        self::assertContains('catalog/view/checkout/cart/after', $triggers);
    }

    public function testNoCpLifecycleMarkersInCatalog(): void
    {
        $root = dirname(__DIR__);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/catalog', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (self::isPhase11Allowed($file->getPathname())
                || \MtUniCredit\Tests\Support\ScopeGuardAllowlist::isPhase11PlusProductionFile($file->getPathname())) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            foreach (self::FORBIDDEN_MARKERS as $marker) {
                self::assertStringNotContainsString(
                    $marker,
                    $contents,
                    $file->getPathname() . ' must not contain ' . $marker
                );
            }
        }
    }

    public function testCartDoesNotRouteThroughProductEndpoints(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_cart.php');
        self::assertStringContainsString('mt_uni_credit_cart', $controller);
        self::assertStringNotContainsString('mt_uni_credit_product.calculate', $controller);
        self::assertStringNotContainsString('ProductOperationIdentity', $controller);
    }

    private static function isPhase11Allowed(string $path): bool
    {
        return str_ends_with($path, '/catalog/controller/module/mt_uni_credit_cart.php');
    }
}
