<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\CertificateLocalStore;
use Opencart\System\Library\Extension\MtUniCredit\CertificateSyncException;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderDataBuilder;
use Opencart\System\Library\Extension\MtUniCredit\OrderDraft;
use Opencart\System\Library\Extension\MtUniCredit\FinancingCustomerData;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAddressData;
use Opencart\System\Library\Extension\MtUniCredit\ShippingMethodSnapshot;
use PHPUnit\Framework\TestCase;

final class Phase11ARuntimeRemediationTest extends TestCase
{
    public function testShippingMethodSnapshotIncludesAdminRequiredFields(): void
    {
        $normalized = ShippingMethodSnapshot::fromQuote([
            'title' => 'Flat Shipping Rate',
            'code' => 'flat.flat',
            'cost' => '5.00',
            'tax_class_id' => '0',
            'text' => '5.00€',
        ]);
        self::assertSame('Flat Shipping Rate', $normalized['name']);
        self::assertSame('flat.flat', $normalized['code']);
        self::assertArrayHasKey('cost', $normalized);
        self::assertArrayHasKey('tax_class_id', $normalized);
        self::assertSame('5.00', $normalized['cost']);
        self::assertSame('0', $normalized['tax_class_id']);
    }

    public function testOrderDataBuilderPersistsCostAndTaxClassId(): void
    {
        $customer = new FinancingCustomerData(0, 1, 'A', 'B', 'a@b.c', '0888', []);
        $address = new FinancingAddressData(0, 'A', 'B', '', 'addr', '', 'City', '1000', 'BG', 33, 'Sofia', 1);
        $draft = new OrderDraft(
            0,
            'Store',
            'https://example.test/',
            'INV',
            $customer,
            $address,
            $address,
            [
                'name' => 'COD',
                'code' => 'cod',
            ],
            [
                'name' => 'Flat Shipping Rate',
                'code' => 'flat.flat',
                'cost' => '5.00',
                'tax_class_id' => 0,
                'text' => '5.00',
            ],
            [],
            [],
            10.0,
            1,
            'bg',
            1,
            'EUR',
            1.0
        );
        $payload = (new OpenCartOrderDataBuilder())->build($draft);
        self::assertArrayHasKey('cost', $payload['shipping_method']);
        self::assertArrayHasKey('tax_class_id', $payload['shipping_method']);
        self::assertSame('5.00', $payload['shipping_method']['cost']);
        self::assertSame(0, $payload['shipping_method']['tax_class_id']);
    }

    public function testNonWritableKeysDirectoryFailsPreflightWithoutWarningLeak(): void
    {
        $root = sys_get_temp_dir() . '/mtuc_keys_' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($root, 0755, true));
        $keys = $root . '/keys';
        self::assertTrue(mkdir($keys, 0700, true));
        // Remove all access for the process via a nested unwritable path owned by current user:
        // use a file occupying the keys path name... instead open a read-only mount simulation by
        // pointing store at a non-directory file.
        $fileAsDir = $root . '/not_a_dir';
        self::assertTrue(touch($fileAsDir));

        $store = new CertificateLocalStore($fileAsDir);
        try {
            $store->assertWritableStore();
            self::fail('Expected CertificateSyncException');
        } catch (CertificateSyncException $exception) {
            self::assertSame(CertificateSyncException::REASON_LOCAL_FS, $exception->reason());
        } finally {
            @unlink($fileAsDir);
            @chmod($keys, 0700);
            @rmdir($keys);
            @rmdir($root);
        }

        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }
}
