<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase5ScopeGuardTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_MARKERS = [
        'SmartUcfSession',
        'hash_hmac',
        'financing_snapshot',
        'createOrder',
        'updateOrderStatus',
        'POST /orders',
        '/orders/status',
        'catalog/controller/payment',
        'PopupSubmission',
        'CheckoutPayment',
    ];

    /** @var list<string> */
    private const ALLOWED_CALCULATOR_FILES = [
        '/system/library/calculator.php',
        '/system/library/product_context.php',
        '/system/library/month_resolver.php',
        '/system/library/coefficient_resolver.php',
        '/system/library/schema_filter_matcher.php',
        '/system/library/first_installment_resolver.php',
        '/system/library/first_installment_state.php',
        '/system/library/financial_calculator.php',
        '/system/library/offer.php',
        '/system/library/offer_factory.php',
        '/system/library/preferred_offer_selector.php',
        '/system/library/available_scheme.php',
        '/system/library/calculation_result.php',
        '/system/library/scheme_presentation_category.php',
        '/system/library/unavailable_scheme_exception.php',
        '/system/library/currency_gate.php',
        '/system/library/currency_display_label.php',
        '/system/library/amount_display_formatter.php',
        '/system/library/cart_line.php',
        '/system/library/cart_context.php',
        '/system/library/cart_resolution.php',
        '/system/library/cart_scheme_resolver.php',
    ];

    public function testCalculatorDomainExistsInSystemLibrary(): void
    {
        $root = dirname(__DIR__);
        foreach (self::ALLOWED_CALCULATOR_FILES as $relative) {
            self::assertFileExists($root . $relative, $relative);
        }
    }

    public function testNoPhase6PlusProductionMarkersOutsideCalculatorDomain(): void
    {
        $root = dirname(__DIR__);
        self::assertDirectoryDoesNotExist($root . '/catalog/controller/payment');
        self::assertDirectoryDoesNotExist($root . '/admin/controller/payment');

        foreach ([$root . '/admin', $root . '/system'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                $relative = substr($path, strlen($root));
                if (in_array($relative, self::ALLOWED_CALCULATOR_FILES, true)) {
                    continue;
                }
                $contents = (string) file_get_contents($path);
                foreach (self::FORBIDDEN_MARKERS as $marker) {
                    self::assertStringNotContainsString(
                        $marker,
                        $contents,
                        $relative . ' must not contain ' . $marker
                    );
                }
            }
        }
    }

    public function testCalculatorClassesHaveNoOpenCartRuntimeDependencies(): void
    {
        $root = dirname(__DIR__);
        foreach (self::ALLOWED_CALCULATOR_FILES as $relative) {
            $contents = (string) file_get_contents($root . $relative);
            self::assertStringNotContainsString('Registry', $contents, $relative);
            self::assertStringNotContainsString('Controller', $contents, $relative);
            self::assertStringNotContainsString('Twig', $contents, $relative);
            self::assertStringNotContainsString('curl_', $contents, $relative);
        }
    }
}
