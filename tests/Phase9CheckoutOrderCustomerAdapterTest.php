<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\CheckoutCustomerValidator;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutOrderCustomerAdapter;
use Opencart\System\Library\Extension\MtUniCredit\ProductAddressValidator;
use Opencart\System\Library\Extension\MtUniCredit\ProductCustomerValidator;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\ProductPopupFormNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Checkout confirm adapts native order + session Checkout state (OC 4.1 timing).
 *
 * Primary telephone is optional for Checkout only (legacy Process 1 / uni-oc4-old).
 */
final class Phase9CheckoutOrderCustomerAdapterTest extends TestCase
{
    public function testGuestOrderSnapshotMapsToSharedValidatorKeys(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = $this->fullPaymentOrder();
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
        $validated = (new CheckoutCustomerValidator())->validate($normalized, 1, 0);
        self::assertSame(0, $validated['customer']->customerId);
        self::assertSame('+359888000111', $validated['customer']->telephone);

        $address = (new ProductAddressValidator())->extractPostedAddress($normalized);
        (new ProductAddressValidator())->validateRequired($address);
        $billing = $adapter->billingAddressFromOrder($order, $validated['customer']);
        self::assertSame('ул. Пример 1', $billing->address1);
    }

    public function testGuestCheckoutEmptyTelephonePassesValidation(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = [
            'order_id'            => 475,
            'customer_id'         => 0,
            'firstname'           => 'Ada',
            'lastname'            => 'Lovelace',
            'email'               => 'ada@example.com',
            'telephone'           => '',
            'payment_firstname'   => '',
            'payment_lastname'    => '',
            'payment_address_1'   => '',
            'shipping_firstname'  => 'Ada',
            'shipping_lastname'   => 'Lovelace',
            'shipping_address_1'  => 'ул. Доставка 9',
            'shipping_city'       => 'София',
            'shipping_postcode'   => '1000',
            'shipping_country'    => 'Bulgaria',
            'shipping_country_id' => 33,
            'shipping_zone'       => 'Sofia',
            'shipping_zone_id'    => 4239,
        ];

        $resolved = $adapter->fromCheckoutContext($order, [], null);
        self::assertSame([], $resolved['missing']);
        self::assertSame('', $resolved['input']['telephone']);
        self::assertNotContains('telephone', $resolved['missing']);

        $normalized = (new ProductPopupFormNormalizer())->normalize($resolved['input'], []);
        $validated = (new CheckoutCustomerValidator())->validate($normalized, 1, 0);
        self::assertSame('', $validated['customer']->telephone);
        self::assertSame(0, $validated['customer']->customerId);
    }

    public function testNativeTelephonePreservedWhenPresent(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = $this->fullPaymentOrder();
        $resolved = $adapter->fromCheckoutContext($order, [
            'customer' => ['telephone' => '+359999999999'],
        ], null);
        self::assertSame('+359888000111', $resolved['input']['telephone']);
        self::assertSame('order.telephone', $resolved['sources']['telephone']);
    }

    public function testProductCustomerValidatorStillRequiresTelephone(): void
    {
        $this->expectException(ProductFinancingFlowException::class);
        try {
            (new ProductCustomerValidator())->validate([
                'firstname' => 'Ada',
                'lastname'  => 'Lovelace',
                'email'     => 'ada@example.com',
                'telephone' => '',
            ], 1, 0);
        } catch (ProductFinancingFlowException $exception) {
            self::assertArrayHasKey('telephone', $exception->fieldErrors());
            throw $exception;
        }
    }

    public function testCartAndProductSubmissionStillWireProductCustomerValidator(): void
    {
        $product = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_product.php');
        $cart = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_cart.php');
        $checkout = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_checkout.php');
        self::assertStringContainsString('new ProductCustomerValidator()', $product);
        self::assertStringContainsString('new ProductCustomerValidator()', $cart);
        self::assertStringContainsString('new CheckoutCustomerValidator()', $checkout);
        self::assertStringNotContainsString('new ProductCustomerValidator()', $checkout);
    }

    public function testPartialOrderSnapshotCompletedFromSessionPaymentAddress(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = [
            'order_id'           => 475,
            'customer_id'        => 0,
            'firstname'          => 'Ada',
            'lastname'           => 'Lovelace',
            'email'              => 'ada@example.com',
            'telephone'          => '',
            'payment_firstname'  => '',
            'payment_lastname'   => '',
            'payment_address_1'  => '',
            'payment_address_id' => 0,
            'shipping_firstname' => '',
            'shipping_address_1' => '',
        ];
        $session = [
            'customer' => [
                'customer_id' => 0,
                'firstname'   => 'Ada',
                'lastname'    => 'Lovelace',
                'email'       => 'ada@example.com',
                'telephone'   => '+359888000111',
            ],
            'payment_address' => [
                'firstname'  => 'Ada',
                'lastname'   => 'Lovelace',
                'address_1'  => 'ул. Пример 1',
                'city'       => 'София',
                'postcode'   => '1000',
                'country'    => 'Bulgaria',
                'country_id' => 33,
                'zone'       => 'Sofia',
                'zone_id'    => 4239,
            ],
            'shipping_address' => [],
        ];

        $resolved = $adapter->fromCheckoutContext($order, $session, null);
        self::assertSame([], $resolved['missing']);
        self::assertSame('ул. Пример 1', $resolved['input']['address']);
        self::assertSame('+359888000111', $resolved['input']['telephone']);

        $normalized = (new ProductPopupFormNormalizer())->normalize($resolved['input'], []);
        (new CheckoutCustomerValidator())->validate($normalized, 1, 0);
        (new ProductAddressValidator())->validateRequired(
            (new ProductAddressValidator())->extractPostedAddress($normalized)
        );
    }

    public function testOc41PaymentAddressDisabledUsesShippingOnOrder(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = [
            'order_id'            => 475,
            'customer_id'         => 0,
            'firstname'           => 'Ada',
            'lastname'            => 'Lovelace',
            'email'               => 'ada@example.com',
            'telephone'           => '',
            'payment_firstname'   => '',
            'payment_lastname'    => '',
            'payment_address_1'   => '',
            'payment_address_id'  => 0,
            'shipping_firstname'  => 'Ada',
            'shipping_lastname'   => 'Lovelace',
            'shipping_address_1'  => 'ул. Доставка 9',
            'shipping_city'       => 'София',
            'shipping_postcode'   => '1000',
            'shipping_country'    => 'Bulgaria',
            'shipping_country_id' => 33,
            'shipping_zone'       => 'Sofia',
            'shipping_zone_id'    => 4239,
        ];

        $resolved = $adapter->fromCheckoutContext($order, [], null);
        self::assertSame([], $resolved['missing']);
        self::assertSame('ул. Доставка 9', $resolved['input']['address']);
        self::assertSame('order.shipping_address_1', $resolved['sources']['address']);
        self::assertSame('', $resolved['input']['telephone']);
    }

    public function testTrulyMissingAddressRemainsInvalid(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = [
            'order_id'           => 1,
            'customer_id'        => 0,
            'firstname'          => 'Ada',
            'lastname'           => 'Lovelace',
            'email'              => 'ada@example.com',
            'telephone'          => '',
            'payment_firstname'  => '',
            'payment_lastname'   => '',
            'payment_address_1'  => '',
            'shipping_address_1' => '',
        ];
        $session = [
            'customer'         => ['email' => 'ada@example.com'],
            'payment_address'  => [],
            'shipping_address' => [],
        ];

        $resolved = $adapter->fromCheckoutContext($order, $session, null);
        self::assertSame(['address'], $resolved['missing']);
        self::assertSame('missing', $resolved['sources']['address']);
    }

    public function testGuestPartialOrderCompletedFromSessionWithoutAddressModel(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = [
            'order_id'           => 900,
            'customer_id'        => 0,
            'firstname'          => '',
            'lastname'           => '',
            'email'              => '',
            'telephone'          => '',
            'payment_firstname'  => '',
            'payment_lastname'   => '',
            'payment_address_1'  => '',
            'shipping_address_1' => '',
        ];
        $session = [
            'customer' => [
                'customer_id' => 0,
                'firstname'   => 'Guest',
                'lastname'    => 'Buyer',
                'email'       => 'guest@example.com',
                'telephone'   => '',
            ],
            'payment_address' => [],
            'shipping_address' => [
                'firstname'  => 'Guest',
                'lastname'   => 'Buyer',
                'address_1'  => 'ул. Гост 2',
                'city'       => 'Пловдив',
                'postcode'   => '4000',
                'country_id' => 33,
                'zone_id'    => 1,
                'country'    => 'Bulgaria',
                'zone'       => 'Plovdiv',
            ],
        ];

        $resolved = $adapter->fromCheckoutContext($order, $session, null);
        self::assertSame([], $resolved['missing']);
        $validated = (new CheckoutCustomerValidator())->validate(
            (new ProductPopupFormNormalizer())->normalize($resolved['input'], []),
            1,
            0
        );
        self::assertSame('', $validated['customer']->telephone);
    }

    public function testLoggedInVerifiedAddressCompletesPartialOrder(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = [
            'order_id'           => 901,
            'customer_id'        => 42,
            'firstname'          => 'Logged',
            'lastname'           => 'In',
            'email'              => 'logged@example.com',
            'telephone'          => '',
            'payment_firstname'  => '',
            'payment_lastname'   => '',
            'payment_address_1'  => '',
            'payment_address_id' => 7,
            'shipping_address_1' => '',
        ];
        $session = [
            'customer' => [
                'customer_id' => 42,
                'firstname'   => 'Logged',
                'lastname'    => 'In',
                'email'       => 'logged@example.com',
                'telephone'   => '',
            ],
            'payment_address'  => [],
            'shipping_address' => [],
        ];
        $verified = [
            'firstname'  => 'Logged',
            'lastname'   => 'In',
            'address_1'  => 'ул. Клиент 5',
            'city'       => 'Варна',
            'postcode'   => '9000',
            'country_id' => 33,
            'zone_id'    => 2,
            'country'    => 'Bulgaria',
            'zone'       => 'Varna',
        ];

        $resolved = $adapter->fromCheckoutContext($order, $session, $verified);
        self::assertSame([], $resolved['missing']);
        $validated = (new CheckoutCustomerValidator())->validate(
            (new ProductPopupFormNormalizer())->normalize($resolved['input'], []),
            1,
            42
        );
        self::assertSame(42, $validated['customer']->customerId);
        self::assertSame('', $validated['customer']->telephone);
    }

    public function testPostedCustomerFieldsDoNotOverrideNativeCheckout(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = $this->fullPaymentOrder();
        $resolved = $adapter->fromCheckoutContext($order, [], null);
        $posted = [
            'firstname' => 'Hacker',
            'lastname'  => 'Override',
            'email'     => 'evil@example.com',
            'telephone' => '+359000000000',
            'address'   => 'evil street',
            'consent'   => ['1'],
        ];
        self::assertSame(['1'], $adapter->extractPostedConsents($posted));
        self::assertSame('Ada', $resolved['input']['firstname']);
        self::assertNotSame($posted['firstname'], $resolved['input']['firstname']);

        $service = (string) file_get_contents(dirname(__DIR__) . '/system/library/checkout_financing_submission_service.php');
        self::assertStringContainsString('CheckoutCustomerValidator', $service);
        self::assertStringContainsString('fromCheckoutContext', $service);

        $twig = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/payment/mt_uni_credit.twig');
        self::assertStringNotContainsString('name="firstname"', $twig);
        self::assertStringNotContainsString('name="telephone"', $twig);
        self::assertStringNotContainsString('name="address"', $twig);
        // Process 2 may render type="tel" for phone2 only; Process 1 identity fields stay absent.
        self::assertStringContainsString('{% if process2 %}', $twig);
        self::assertMatchesRegularExpression(
            '/\{\%\s*if\s+process2\s*\%\}[\s\S]*name="egn"[\s\S]*name="phone2"/',
            $twig
        );
        self::assertStringNotContainsString('data-mtuc-offers', $twig);
        self::assertStringNotContainsString('data-mtuc-customer-summary', $twig);
    }

    public function testPhoneOnlyPostedWithoutTelephoneStillFailsOnProductValidator(): void
    {
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
        $order = $this->fullPaymentOrder();
        $order['customer_id'] = 42;
        $input = $adapter->toValidationInput($order);
        $validated = (new CheckoutCustomerValidator())->validate($input, 1, 42);
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

    public function testConfirmUsesCheckoutContextAdapter(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__) . '/system/library/checkout_financing_submission_service.php');
        self::assertStringContainsString('CheckoutCustomerValidator', $service);
        self::assertStringContainsString('fromCheckoutContext', $service);
        self::assertStringContainsString('billingAddressFromResolved', $service);
        self::assertStringContainsString('checkout_customer_missing_fields', $service);

        $controller = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php');
        self::assertStringContainsString('sessionCheckoutData()', $controller);
        self::assertStringContainsString('verifiedOwnedAddressForOrder', $controller);

        $model = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_checkout.php');
        self::assertStringContainsString('CheckoutCustomerValidator', $model);
        self::assertStringContainsString('getAddress($customerId, $addressId)', $model);

        $docs = (string) file_get_contents(dirname(__DIR__) . '/docs/PHASE9.md');
        self::assertStringContainsString('clientPhone', $docs);
        self::assertStringContainsString('customer.phone', $docs);
        self::assertStringContainsString('phone2', $docs);
    }

    /** @return array<string, mixed> */
    private function fullPaymentOrder(): array
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
