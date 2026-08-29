<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\CheckoutOrderCustomerAdapter;
use Opencart\System\Library\Extension\MtUniCredit\ProductAddressValidator;
use Opencart\System\Library\Extension\MtUniCredit\ProductCustomerValidator;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\ProductPopupFormNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Checkout confirm must adapt native order snapshot — not Product popup POST phone naming.
 */
final class Phase9CheckoutOrderCustomerAdapterTest extends TestCase
{
    public function testGuestOrderSnapshotMapsToSharedValidatorKeys(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = $this->guestOrder();
        $input = $adapter->toValidationInput($order);

        self::assertSame('Ada', $input['firstname']);
        self::assertSame('Lovelace', $input['lastname']);
        self::assertSame('ada@example.com', $input['email']);
        self::assertSame('+359888000111', $input['telephone']);
        self::assertSame('ул. Пример 1', $input['address_1']);
        self::assertArrayNotHasKey('phone', $input);

        $normalized = (new ProductPopupFormNormalizer())->normalize($input, [
            'city'       => 'Sofia',
            'postcode'   => '1000',
            'country_id' => 33,
            'zone_id'    => 4239,
            'country'    => 'Bulgaria',
            'zone'       => 'Sofia',
        ]);
        $validated = (new ProductCustomerValidator())->validate($normalized, 1, 0);
        self::assertSame(0, $validated['customer']->customerId);
        self::assertSame('+359888000111', $validated['customer']->telephone);

        $address = (new ProductAddressValidator())->extractPostedAddress($normalized);
        (new ProductAddressValidator())->validateRequired($address);
        $billing = $adapter->billingAddressFromOrder($order, $validated['customer']);
        self::assertSame('ул. Пример 1', $billing->address1);
    }

    public function testPhoneOnlyPostedWithoutTelephoneStillFailsBeforeAdapter(): void
    {
        // Documents the operator 422: ProductCustomerValidator reads telephone, not phone.
        $this->expectException(ProductFinancingFlowException::class);
        (new ProductCustomerValidator())->validate([
            'firstname' => 'Ada',
            'lastname'  => 'Lovelace',
            'email'     => 'ada@example.com',
            'phone'     => '+359888000111',
        ], 1, 0);
    }

    public function testLoggedInOwnershipUsesOrderCustomerIdInSnapshot(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = $this->guestOrder();
        $order['customer_id'] = 42;
        $input = $adapter->toValidationInput($order);
        $validated = (new ProductCustomerValidator())->validate($input, 1, 42);
        self::assertSame(42, $validated['customer']->customerId);
    }

    public function testConsentExtractionFromIndexedFormKeys(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        self::assertSame([3, 7], array_map('intval', $adapter->extractPostedConsents([
            'consent[0]' => '3',
            'consent[1]' => '7',
            'firstname'  => 'x',
        ])));
        self::assertSame([3], array_map('intval', $adapter->extractPostedConsents([
            'consent' => ['3'],
        ])));
        self::assertSame([], $adapter->extractPostedConsents(['firstname' => 'x']));
    }

    public function testButtonConfirmLanguageIsOrderWording(): void
    {
        $bg = (string) file_get_contents(dirname(__DIR__) . '/catalog/language/bg-bg/payment/mt_uni_credit.php');
        $en = (string) file_get_contents(dirname(__DIR__) . '/catalog/language/en-gb/payment/mt_uni_credit.php');
        self::assertStringContainsString("'Потвърди поръчката'", $bg);
        self::assertStringNotContainsString('Потвърди финансирането', $bg);
        self::assertStringContainsString("'Confirm order'", $en);
        self::assertStringNotContainsString('Confirm financing', $en);
    }

    public function testConfirmUsesOrderSnapshotAdapter(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__) . '/system/library/checkout_financing_submission_service.php');
        self::assertStringContainsString('CheckoutOrderCustomerAdapter', $service);
        self::assertStringContainsString('toValidationInput', $service);
        self::assertStringContainsString('billingAddressFromOrder', $service);
        self::assertStringContainsString('extractPostedConsents', $service);

        $controller = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php');
        self::assertMatchesRegularExpression(
            '/LockOwnerTokenGenerator::generate\(\),\s*\(int\) \$order\[\'order_id\'\],\s*\$order,/',
            $controller
        );
    }

    /** @return array<string, mixed> */
    private function guestOrder(): array
    {
        return [
            'order_id'            => 1001,
            'customer_id'         => 0,
            'customer_group_id'   => 1,
            'firstname'           => 'Ada',
            'lastname'            => 'Lovelace',
            'email'               => 'ada@example.com',
            'telephone'           => '+359888000111',
            'payment_firstname'   => 'Ada',
            'payment_lastname'    => 'Lovelace',
            'payment_address_1'   => 'ул. Пример 1',
            'payment_address_2'   => '',
            'payment_city'        => 'София',
            'payment_postcode'    => '1000',
            'payment_country'     => 'Bulgaria',
            'payment_country_id'  => 33,
            'payment_zone'        => 'Sofia',
            'payment_zone_id'     => 4239,
            'payment_company'     => '',
            'store_id'            => 0,
            'total'               => 1200.0,
            'currency_code'       => 'EUR',
        ];
    }
}
