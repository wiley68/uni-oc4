<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase6ScopeGuardTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_MARKERS = [
        'SmartUcfSession',
        'hash_hmac',
        'financing_snapshot',
                'SmartUCF',
        'catalog/controller/module/mt_uni_credit_cart',
        'Process1',
        'Process2',
    ];

    /** @var list<string> */
    private const ALLOWED_PHASE6_FILES = [
        '/system/library/financing_customer_data.php',
        '/system/library/financing_address_data.php',
        '/system/library/order_draft.php',
        '/system/library/validated_financing_submission.php',
        '/system/library/financing_attempt_context.php',
        '/system/library/created_open_cart_order.php',
        '/system/library/order_materialization_exception.php',
        '/system/library/payment_identity.php',
        '/system/library/order_correlation_repository.php',
        '/system/library/order_correlation_store_interface.php',
        '/system/library/checkout_order_model_port.php',
        '/system/library/open_cart_order_gateway_interface.php',
        '/system/library/open_cart_order_data_builder.php',
        '/system/library/open_cart_order_verifier.php',
        '/system/library/open_cart_order_materializer.php',
        '/system/library/product_order_gateway.php',
        '/system/library/cart_order_gateway.php',
        '/system/library/checkout_existing_order_gateway.php',
        '/system/library/financing_order_status_policy.php',
        '/system/library/order_materialization_service.php',
        '/system/library/product_order_draft_factory.php',
        '/system/library/cart_order_draft_factory.php',
    ];

    /** @var list<string> */
    private const ALLOWED_CALCULATOR_FILES = [
        '/system/library/calculator.php',
        '/system/library/product_context.php',
        '/system/library/cart_context.php',
        '/system/library/cart_line.php',
        '/system/library/calculation_result.php',
        '/system/library/available_scheme.php',
        '/system/library/first_installment_state.php',
    ];

    public function testPhase6FoundationFilesExist(): void
    {
        $root = dirname(__DIR__);
        foreach (self::ALLOWED_PHASE6_FILES as $relative) {
            self::assertFileExists($root . $relative, $relative);
        }
    }

    public function testNoPhase7PlusMarkersOutsideAllowedDomains(): void
    {
        $root = dirname(__DIR__);

        $allowed = array_merge(self::ALLOWED_PHASE6_FILES, self::ALLOWED_CALCULATOR_FILES);
        foreach ([$root . '/admin', $root . '/system'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root));
                if (self::isPhase11Allowed($relative)) {
                    continue;
                }
                if (in_array($relative, $allowed, true)) {
                    continue;
                }
                if (str_starts_with($relative, '/system/library/') && self::isEarlierPhaseLibrary($relative)) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
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

    public function testSchemaInstallerStillHasNoSnapshotTable(): void
    {
        $sql = implode("\n", \Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller::createTableStatements('oc_'));
        self::assertStringNotContainsString('financing_snapshot', $sql);
        self::assertStringContainsString('mt_uni_credit_order_correlation', $sql);
    }

    public function testMaterializerDoesNotWriteInternalRecoveryToTracking(): void
    {
        $builder = (string) file_get_contents(dirname(__DIR__) . '/system/library/open_cart_order_data_builder.php');
        $materializer = (string) file_get_contents(dirname(__DIR__) . '/system/library/open_cart_order_materializer.php');
        self::assertStringContainsString("'tracking'                => ''", $builder);
        self::assertStringNotContainsString('mtuc:', $builder);
        self::assertStringNotContainsString('tracking', $materializer);
    }

    private static function isEarlierPhaseLibrary(string $relative): bool
    {
        return str_starts_with($relative, '/system/library/')
            && !in_array($relative, self::ALLOWED_PHASE6_FILES, true);
    }

    private static function isPhase11Allowed(string $relative): bool
    {
        return str_starts_with($relative, '/system/library/smart_ucf_')
            || $relative === '/system/library/bank_status.php'
            || $relative === '/system/library/post_control_panel_lifecycle_service.php';
    }
}
