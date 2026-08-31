<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

/** Phase 9 must not introduce CP / Process / SmartUCF lifecycle. */
final class Phase9ScopeGuardTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_MARKERS = [
        'SmartUcfSession',
        'Process1',
        'Process2',
        'financing_snapshot',
        'bank_redirect',
        'SmartUCF',
    ];

    public function testNoPhase10MarkersInPaymentSurface(): void
    {
        $root = dirname(__DIR__);
        $paths = [
            $root . '/catalog/controller/payment',
            $root . '/catalog/model/payment',
            $root . '/catalog/model/module/mt_uni_credit_checkout.php',
            $root . '/system/library/checkout_financing_submission_service.php',
            $root . '/catalog/view/javascript/mt_uni_credit_checkout.js',
        ];
        foreach ($paths as $path) {
            if (is_file($path)) {
                if (self::isPhase11Allowed($path)
                    || \MtUniCredit\Tests\Support\ScopeGuardAllowlist::isPhase11PlusProductionFile($path)) {
                    continue;
                }
                $this->assertNoMarkers($path, (string) file_get_contents($path));
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                if (self::isPhase11Allowed($file->getPathname())
                    || \MtUniCredit\Tests\Support\ScopeGuardAllowlist::isPhase11PlusProductionFile($file->getPathname())) {
                    continue;
                }
                $this->assertNoMarkers($file->getPathname(), (string) file_get_contents($file->getPathname()));
            }
        }
    }

    public function testPaymentConfirmDoesNotCallAddOrder(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php');
        self::assertStringNotContainsString('->addOrder(', $controller);
        $service = (string) file_get_contents(dirname(__DIR__) . '/system/library/checkout_financing_submission_service.php');
        self::assertStringNotContainsString('->addOrder(', $service);
    }

    private function assertNoMarkers(string $path, string $contents): void
    {
        foreach (self::FORBIDDEN_MARKERS as $marker) {
            self::assertStringNotContainsString($marker, $contents, $path . ' must not contain ' . $marker);
        }
    }

    private static function isPhase11Allowed(string $path): bool
    {
        return str_ends_with($path, '/catalog/controller/payment/mt_uni_credit.php')
            || str_ends_with($path, '/catalog/view/javascript/mt_uni_credit_checkout.js')
            || str_ends_with($path, '/system/library/checkout_financing_submission_service.php');
    }
}
