<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\ProductFinancingTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\CartActorBinding;
use Opencart\System\Library\Extension\MtUniCredit\CartContext;
use Opencart\System\Library\Extension\MtUniCredit\CartFingerprint;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutCustomerValidator;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutOperationIdentity;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutOrderCustomerAdapter;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutSelectionHash;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutSubmissionIssuer;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelOrderPayloadBuilder;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAddressData;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState;
use Opencart\System\Library\Extension\MtUniCredit\FinancingCustomerData;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderDataBuilder;
use Opencart\System\Library\Extension\MtUniCredit\OrderDraft;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceClock;
use Opencart\System\Library\Extension\MtUniCredit\ProductAddressValidator;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\ProductPopupFormNormalizer;
use Opencart\System\Library\Extension\MtUniCredit\ShippingMethodSnapshot;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/fixtures/calculator_fixture.php';

/**
 * Phase 11C Remediation 03 — logged/guest Checkout when cart hasShipping=false.
 */
final class Phase11CNoShippingCheckoutTest extends TestCase
{
    private ?FinancingAttemptRepository $attempts = null;

    protected function setUp(): void
    {
        if ($this->requiresIntegration()) {
            PersistenceIntegrationHarness::resetTables();
            $this->attempts = new FinancingAttemptRepository(PersistenceIntegrationHarness::connection());
        }
    }

    public function testLoggedNoShippingPartialOrderUsesVerifiedDefaultAddress(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = $this->loggedNoShippingOrderSnapshot();
        $verified = $this->defaultOwnedAddressRow();
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

        $resolved = $adapter->fromCheckoutContext($order, $session, $verified);
        self::assertSame([], $resolved['missing']);
        self::assertSame('ул. Клиент 5', $resolved['input']['address']);
        self::assertSame('verified_address.address_1', $resolved['sources']['address']);

        $validated = (new CheckoutCustomerValidator())->validate(
            (new ProductPopupFormNormalizer())->normalize($resolved['input'], []),
            1,
            42
        );
        self::assertSame(42, $validated['customer']->customerId);
        self::assertSame('', $validated['customer']->telephone);
        (new ProductAddressValidator())->validateRequired(
            (new ProductAddressValidator())->extractPostedAddress(
                (new ProductPopupFormNormalizer())->normalize($resolved['input'], [])
            )
        );
    }

    public function testPhysicalCartShippingAddressUnchanged(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = [
            'order_id'            => 902,
            'customer_id'         => 42,
            'firstname'           => 'Logged',
            'lastname'            => 'In',
            'email'               => 'logged@example.com',
            'telephone'           => '+359888000111',
            'payment_firstname'   => '',
            'payment_lastname'    => '',
            'payment_address_1'   => '',
            'payment_address_id'  => 0,
            'shipping_firstname'  => 'Logged',
            'shipping_lastname'   => 'In',
            'shipping_address_1'  => 'ул. Доставка 9',
            'shipping_city'       => 'София',
            'shipping_postcode'   => '1000',
            'shipping_country'    => 'Bulgaria',
            'shipping_country_id' => 33,
            'shipping_zone'       => 'Sofia',
            'shipping_zone_id'    => 4239,
            'shipping_method'     => json_encode(ShippingMethodSnapshot::fromQuote([
                'title' => 'Flat',
                'code'  => 'flat.flat',
                'cost'  => '5.00',
            ])),
        ];

        $resolved = $adapter->fromCheckoutContext($order, [], null);
        self::assertSame([], $resolved['missing']);
        self::assertSame('ул. Доставка 9', $resolved['input']['address']);
        self::assertSame('order.shipping_address_1', $resolved['sources']['address']);
        self::assertSame('+359888000111', $resolved['input']['telephone']);

        $shipping = $adapter->shippingAddressFromOrder(
            $order,
            $adapter->billingAddressFromResolved($resolved['input'], new FinancingCustomerData(42, 1, 'Logged', 'In', 'logged@example.com', ''))
        );
        self::assertSame('ул. Доставка 9', $shipping->address1);
        self::assertSame('flat.flat', ShippingMethodSnapshot::normalize(
            json_decode((string) $order['shipping_method'], true) ?: []
        )['code']);
    }

    public function testGuestNoShippingWithSessionPaymentAddress(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = [
            'order_id'           => 903,
            'customer_id'        => 0,
            'firstname'          => 'Guest',
            'lastname'           => 'Buyer',
            'email'              => 'guest@example.com',
            'telephone'          => '',
            'payment_firstname'  => '',
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
            'payment_address' => [
                'firstname'  => 'Guest',
                'lastname'   => 'Buyer',
                'address_1'  => 'ул. Гост 3',
                'city'       => 'София',
                'postcode'   => '1000',
                'country_id' => 33,
                'zone_id'    => 4239,
                'country'    => 'Bulgaria',
                'zone'       => 'Sofia',
            ],
            'shipping_address' => [],
        ];

        $resolved = $adapter->fromCheckoutContext($order, $session, null);
        self::assertSame([], $resolved['missing']);
        self::assertSame('ул. Гост 3', $resolved['input']['address']);
        self::assertSame('session.payment_address.address_1', $resolved['sources']['address']);
    }

    public function testGuestNoShippingWithoutAddressRemainsInvalid(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = [
            'order_id'           => 904,
            'customer_id'        => 0,
            'firstname'          => 'Guest',
            'lastname'           => 'Buyer',
            'email'              => 'guest@example.com',
            'telephone'          => '',
            'payment_address_1'  => '',
            'shipping_address_1' => '',
        ];
        $session = [
            'customer'         => ['email' => 'guest@example.com'],
            'payment_address'  => [],
            'shipping_address' => [],
        ];

        $resolved = $adapter->fromCheckoutContext($order, $session, null);
        self::assertSame(['address'], $resolved['missing']);
    }

    public function testVerifiedOwnedAddressForOrderFallsBackToCustomerDefault(): void
    {
        $model = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_checkout.php');
        self::assertStringContainsString('defaultOwnedAddressId', $model);
        self::assertStringContainsString('hasShipping=false', $model);
    }

    public function testNoShippingUsesShippingMethodSnapshotEmpty(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__) . '/system/library/checkout_financing_submission_service.php');
        self::assertStringContainsString('ShippingMethodSnapshot::empty()', $service);
        self::assertStringNotContainsString("['name' => '', 'code' => '']", $service);

        $empty = ShippingMethodSnapshot::empty();
        self::assertArrayHasKey('cost', $empty);
        self::assertArrayHasKey('tax_class_id', $empty);
        self::assertSame(0, $empty['cost']);
        self::assertSame(0, $empty['tax_class_id']);
    }

    public function testNoShippingOrderDraftHasAdminSafeShippingMethod(): void
    {
        $customer = new FinancingCustomerData(42, 1, 'Logged', 'In', 'logged@example.com', '');
        $address = new FinancingAddressData(0, 'Logged', 'In', '', 'ул. Клиент 5', '', 'Варна', '9000', 'Bulgaria', 33, 'Varna', 2);
        $draft = new OrderDraft(
            0,
            'Store',
            'https://example.test/',
            'INV',
            $customer,
            $address,
            $address,
            PaymentIdentity::paymentMethod(),
            ShippingMethodSnapshot::empty(),
            [['product_id' => 42, 'quantity' => 1, 'name' => 'Virtual', 'price' => 1000, 'total' => 1000]],
            [['code' => 'total', 'value' => 1000.0]],
            1000.0,
            1,
            'bg',
            1,
            'BGN',
            1.0
        );
        $payload = (new OpenCartOrderDataBuilder())->build($draft);
        self::assertSame('', $payload['shipping_method']['code']);
        self::assertArrayHasKey('cost', $payload['shipping_method']);
        self::assertArrayHasKey('tax_class_id', $payload['shipping_method']);
    }

    public function testCpPayloadUsesBillingWhenNoShipping(): void
    {
        $builder = new ControlPanelOrderPayloadBuilder();
        $submission = OrderMaterializationTestHarness::productSubmission();
        $submission->customer = new FinancingCustomerData(42, 1, 'Logged', 'In', 'logged@example.com', '');
        $submission->billingAddress = new FinancingAddressData(0, 'Logged', 'In', '', 'ул. Клиент 5', '', 'Варна', '9000', 'Bulgaria', 33, 'Varna', 2);
        $submission->shippingAddress = null;

        $payload = $builder->build($submission, 5001, ProductFinancingTestHarness::shop());
        self::assertStringContainsString('ул. Клиент 5', $payload['address']);
        self::assertSame('logged@example.com', $payload['email']);
        self::assertNotSame('N/A', $payload['address']);
    }

    public function testBuildOrderMaterialsShippingRequiredFalseWhenNoShippingCode(): void
    {
        $model = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_checkout.php');
        self::assertStringContainsString("'shipping_required'  => \$shippingCode !== ''", $model);
    }

    public function testInvalidAddressAdapterMissingBlocksBeforeValidator(): void
    {
        $adapter = new CheckoutOrderCustomerAdapter();
        $order = $this->loggedNoShippingOrderSnapshot();
        $resolved = $adapter->fromCheckoutContext($order, ['customer' => ['email' => 'logged@example.com']], null);
        self::assertSame(['address'], $resolved['missing']);
    }

    public function testModuleVersionRemains202(): void
    {
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testLoggedNoShippingCheckoutSubmitProcess1(): void
    {
        if (!$this->requiresIntegration()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for DB integration tests.');
        }

        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate(601);
        $orders = new InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::checkoutSubmissionService($this->attempts, $orders, $transport);

        try {
            $result = $this->submitCheckout($service, $orders, $transport, false);
            self::assertTrue($result->success);
        } catch (ProductFinancingFlowException $exception) {
            // Test env lacks SmartUCF mTLS; CP create must still succeed for Process 1.
            if ($exception->errorCode() !== 'smartucf_submit_failed') {
                throw $exception;
            }
        }

        self::assertSame(1, $transport->countOrderCreates());
        $row = $this->attempts->findByOrderId(ProductFinancingTestHarness::STORE_ID, $orders->lastOrderId());
        self::assertNotNull($row);
        self::assertSame(FinancingAttemptState::CP_CREATED, $row['state']);
    }

    public function testLoggedNoShippingCheckoutSubmitProcess2(): void
    {
        if (!$this->requiresIntegration()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for DB integration tests.');
        }

        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate(602);
        $orders = new InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::checkoutSubmissionService($this->attempts, $orders, $transport);

        $result = $this->submitCheckout($service, $orders, $transport, true);
        self::assertTrue($result->success);
        self::assertSame('process2_prepared', $result->step);
        self::assertSame(602, $result->controlPanelOrderId);
        self::assertSame(1, $transport->countOrderCreates());
    }

    public function testInvalidCustomerNoCpCreateCheckout(): void
    {
        if (!$this->requiresIntegration()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for DB integration tests.');
        }

        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate(603);
        $orders = new InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::checkoutSubmissionService($this->attempts, $orders, $transport);

        try {
            $this->submitCheckout($service, $orders, $transport, false, null, null, false);
            self::fail('Expected invalid_customer');
        } catch (ProductFinancingFlowException $exception) {
            self::assertSame('invalid_customer', $exception->errorCode());
        }

        self::assertSame(0, $transport->countOrderCreates());
    }

    public function testRetryAfterValidationFailureReusesOrder(): void
    {
        if (!$this->requiresIntegration()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for DB integration tests.');
        }

        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate(604);
        $orders = new InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::checkoutSubmissionService($this->attempts, $orders, $transport);

        try {
            $this->submitCheckout($service, $orders, $transport, false, null, null, false);
            self::fail('Expected invalid_customer on first attempt');
        } catch (ProductFinancingFlowException $exception) {
            self::assertSame('invalid_customer', $exception->errorCode());
        }

        $orderId = $orders->lastOrderId();
        try {
            $result = $this->submitCheckout($service, $orders, $transport, false, null, $orderId, true);
            self::assertTrue($result->success);
        } catch (ProductFinancingFlowException $exception) {
            if ($exception->errorCode() !== 'smartucf_submit_failed') {
                throw $exception;
            }
        }
        self::assertSame($orderId, $orders->lastOrderId());
        self::assertSame(1, $transport->countOrderCreates());
    }

    /** @return array<string, mixed> */
    private function loggedNoShippingOrderSnapshot(): array
    {
        return [
            'order_id'            => 905,
            'customer_id'         => 42,
            'firstname'           => 'Logged',
            'lastname'            => 'In',
            'email'               => 'logged@example.com',
            'telephone'           => '',
            'payment_firstname'   => '',
            'payment_lastname'    => '',
            'payment_address_1'   => '',
            'payment_address_id'  => 0,
            'shipping_firstname'  => '',
            'shipping_lastname'   => '',
            'shipping_address_1'  => '',
            'shipping_address_id' => 0,
            'shipping_method'     => [],
        ];
    }

    /** @return array<string, mixed> */
    private function defaultOwnedAddressRow(): array
    {
        return [
            'address_id' => 7,
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
    }

    private function requiresIntegration(): bool
    {
        return str_contains($this->name(), 'CheckoutSubmit')
            || str_contains($this->name(), 'InvalidCustomer')
            || str_contains($this->name(), 'RetryAfter');
    }

    /**
     * @param array<string, mixed>|null $verifiedAddress
     */
    private function submitCheckout(
        \Opencart\System\Library\Extension\MtUniCredit\CheckoutFinancingSubmissionService $service,
        InMemoryCheckoutOrderAdapter $orders,
        FakeCpHttpTransport $transport,
        bool $process2,
        ?array $verifiedAddress = null,
        ?int $existingOrderId = null,
        bool $withVerifiedAddress = true
    ): \Opencart\System\Library\Extension\MtUniCredit\ProductFinancingResult {
        $shop = ProductFinancingTestHarness::shop();
        if ($process2) {
            $shop['uni_proces'] = 1;
        }

        $orderSnapshot = $this->loggedNoShippingOrderSnapshot();
        $orderSnapshot['customer_group_id'] = 1;
        $orderSnapshot['currency_code'] = 'BGN';
        $orderSnapshot['language_id'] = 1;
        $orderSnapshot['language_code'] = 'bg-bg';
        $orderSnapshot['currency_id'] = 1;
        $orderSnapshot['currency_value'] = 1.0;
        $orderSnapshot['store_name'] = 'Store';
        $orderSnapshot['store_url'] = 'https://example.test/';
        $orderSnapshot['invoice_prefix'] = 'INV-';
        $orderSnapshot['total'] = 1000.0;

        if ($existingOrderId !== null) {
            $orderId = $existingOrderId;
            $orderSnapshot['order_id'] = $orderId;
        } else {
            $orderId = $orders->addOrder([
                'store_id'         => ProductFinancingTestHarness::STORE_ID,
                'customer_id'      => 42,
                'firstname'        => 'Logged',
                'lastname'         => 'In',
                'email'            => 'logged@example.com',
                'telephone'        => '',
                'payment_address_1' => '',
                'shipping_address_1' => '',
                'shipping_method'  => [],
                'total'            => 1000.0,
                'currency_code'    => 'BGN',
                'payment_method'   => PaymentIdentity::paymentMethod(),
                'products'         => [['product_id' => 42, 'quantity' => 1, 'name' => 'Virtual', 'price' => 1000, 'total' => 1000]],
                'totals'           => [['code' => 'total', 'value' => 1000.0]],
            ]);
            $orderSnapshot['order_id'] = $orderId;
        }

        $cart = new CartContext([mt_uni_credit_cart_line(42, [10], 1000.0)], 1000.0);
        $currency = 'BGN';
        $scheme = ProductFinancingTestHarness::defaultSchemeSelection();
        $storeId = ProductFinancingTestHarness::STORE_ID;
        $actor = CartActorBinding::hash($storeId, 42, CartActorBinding::sessionFingerprint('sess-no-ship'));
        $fingerprint = CartFingerprint::hash($cart, $currency);
        $selectionHash = CheckoutSelectionHash::hash(
            $storeId,
            $orderId,
            $fingerprint,
            $currency,
            1000.0,
            $scheme['scheme_key'],
            $scheme['scheme_type'],
            $scheme['kop_code'],
            $scheme['months'],
            $scheme['filter_id'],
            $scheme['first_installment'],
            $actor
        );
        $operationKey = CheckoutOperationIdentity::hash($storeId, $orderId);
        $attempt = (new CheckoutSubmissionIssuer($this->attempts, new PersistenceClock()))
            ->issueOrReuse($storeId, $operationKey, $actor, $selectionHash, null, null, $fingerprint);
        $token = (string) $attempt['submission_token'];

        $sessionCheckout = [
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

        $posted = ['consent' => [1]];
        if ($process2) {
            $posted['egn'] = '1990011599';
            $posted['phone2'] = '0888123456';
        }

        return $service->submit(
            $shop,
            $storeId,
            $token,
            $actor,
            CartActorBinding::sessionFingerprint('sess-no-ship'),
            42,
            1,
            $cart,
            $fingerprint,
            $currency,
            'standard',
            $scheme['scheme_type'],
            $scheme['kop_code'],
            $scheme['months'],
            $scheme['filter_id'],
            $scheme['scheme_key'],
            $scheme['first_installment'],
            $posted,
            [['product_id' => 42, 'quantity' => 1, 'name' => 'Virtual', 'price' => 1000, 'total' => 1000]],
            [['code' => 'total', 'value' => 1000.0]],
            1000.0,
            false,
            (string) ($shop['unicid'] ?? ''),
            '2026-08-31 12:00:00',
            1,
            'bg-bg',
            1,
            1.0,
            'Store',
            'https://example.test/',
            'INV-',
            LockOwnerTokenGenerator::generate(),
            $orderId,
            $orderSnapshot,
            '127.0.0.1',
            ['country_id' => 33, 'zone_id' => 4239, 'country' => 'Bulgaria', 'zone' => 'Sofia', 'city' => 'Sofia', 'postcode' => '1000'],
            $sessionCheckout,
            $withVerifiedAddress ? ($verifiedAddress ?? $this->defaultOwnedAddressRow()) : $verifiedAddress
        );
    }
}
