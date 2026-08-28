<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase7ScopeGuardTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_MARKERS = [
        'SmartUcfSession',
        'SmartUCF',
        'createOrder(',
        'Process1',
        'Process2',
        'financing_snapshot',
        'catalog/controller/payment',
        'catalog/controller/module/mt_uni_credit_cart',
        'ControlPanelClient',
        'cp_submitting',
    ];

    /** @var list<string> */
    private const ALLOWED_CATALOG_FILES = [
        '/catalog/controller/event/mt_uni_credit_product_controller.php',
        '/catalog/controller/event/mt_uni_credit_product_view.php',
        '/catalog/controller/module/mt_uni_credit_product.php',
        '/catalog/model/module/mt_uni_credit_product.php',
    ];

    public function testPhase7CatalogFilesExist(): void
    {
        $root = dirname(__DIR__);
        foreach (self::ALLOWED_CATALOG_FILES as $relative) {
            self::assertFileExists($root . $relative, $relative);
        }
    }

    public function testNoCartCheckoutOrCpLeaksInCatalog(): void
    {
        $root = dirname(__DIR__) . '/catalog';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
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

    public function testEventsRegisteredForProductOnly(): void
    {
        $events = \Opencart\System\Library\Extension\MtUniCredit\EventRegistry::definitions();
        self::assertCount(2, $events);
        self::assertStringContainsString('product/product', $events[0]['trigger'] . $events[1]['trigger']);
        self::assertStringNotContainsString('cart', json_encode($events, JSON_THROW_ON_ERROR));
    }
}
