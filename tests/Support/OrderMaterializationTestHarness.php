<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\AvailableScheme;
use Opencart\System\Library\Extension\MtUniCredit\CalculationResult;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAddressData;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptContext;
use Opencart\System\Library\Extension\MtUniCredit\FinancingCustomerData;
use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderStatusPolicy;
use Opencart\System\Library\Extension\MtUniCredit\FirstInstallmentState;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderDataBuilder;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderMaterializer;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderVerifier;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\OrderMaterializationService;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductOrderDraftFactory;
use Opencart\System\Library\Extension\MtUniCredit\CartOrderDraftFactory;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutExistingOrderGateway;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutOrderModelPort;
use Opencart\System\Library\Extension\MtUniCredit\OrderCorrelationRepository;
use Opencart\System\Library\Extension\MtUniCredit\OrderCorrelationStoreInterface;
use Opencart\System\Library\Extension\MtUniCredit\ValidatedFinancingSubmission;

final class OrderMaterializationTestHarness
{
    public const TEST_AWAITING_STATUS_ID = 25;

    public const TEST_VOID_STATUS_ID = 16;

    public static function customer(): FinancingCustomerData
    {
        return new FinancingCustomerData(0, 1, 'Ivan', 'Petrov', 'ivan@example.test', '0888000000');
    }

    public static function address(): FinancingAddressData
    {
        return new FinancingAddressData(
            0,
            'Ivan',
            'Petrov',
            '',
            'ul. Test 1',
            '',
            'Sofia',
            '1000',
            'Bulgaria',
            33,
            'Sofia',
            4239
        );
    }

    public static function calculation(float $price = 1200.0): CalculationResult
    {
        $scheme = new AvailableScheme('standard', 'KOP1', 12, 1, null, ['installmentCount' => 12, 'coefficient' => 0.083333]);

        return new CalculationResult(
            $scheme,
            $price,
            new FirstInstallmentState(0.0, false, false),
            $price,
            round($price * 0.083333, 2),
            round($price * 0.083333 * 12, 2),
            0.0,
            0.0
        );
    }

    public static function productSubmission(
        string $entryPoint = OperationEntryPoint::PRODUCT,
        ?int $existingOrderId = null
    ): ValidatedFinancingSubmission {
        $customer = self::customer();
        $billing = self::address();
        $totals = [
            ['extension' => 'opencart', 'code' => 'sub_total', 'title' => 'Sub-Total', 'value' => 1000.0, 'sort_order' => 1],
            ['extension' => 'opencart', 'code' => 'total', 'title' => 'Total', 'value' => 1200.0, 'sort_order' => 9],
        ];
        $draftFactory = new ProductOrderDraftFactory();
        $draft = $draftFactory->create(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            'Test Store',
            'https://example.test/',
            'INV-',
            $customer,
            $billing,
            null,
            42,
            'Test Product',
            'SKU-42',
            2,
            500.0,
            100.0,
            1,
            0,
            [[
                'product_option_id'       => 10,
                'product_option_value_id' => 20,
                'name'                    => 'Color',
                'value'                   => 'Red',
                'type'                    => 'select',
            ]],
            $totals,
            1200.0,
            1,
            'bg-bg',
            1,
            'BGN',
            1.0
        );

        return new ValidatedFinancingSubmission(
            $entryPoint,
            PersistenceIntegrationHarness::TEST_STORE_ID,
            $entryPoint === OperationEntryPoint::CHECKOUT ? null : str_repeat('a', 64),
            hash('sha256', 'operation-' . $entryPoint),
            $entryPoint === OperationEntryPoint::CART ? 555 : null,
            $existingOrderId,
            $customer,
            $billing,
            null,
            self::calculation(),
            $draft,
            hash('sha256', 'selection'),
            hash('sha256', 'cart-fingerprint'),
            'test-unicid',
            '2026-08-28 12:00:00',
            'phase6_test'
        );
    }

    public static function cartSubmission(): ValidatedFinancingSubmission
    {
        $customer = self::customer();
        $billing = self::address();
        $shipping = self::address();
        $products = [
            [
                'product_id' => 42,
                'master_id'  => 0,
                'name'       => 'Product A',
                'model'      => 'A',
                'quantity'   => 1,
                'subtract'   => 1,
                'price'      => 400.0,
                'total'      => 400.0,
                'tax'        => 80.0,
                'reward'     => 0,
                'option'     => [],
                'subscription' => [],
            ],
            [
                'product_id' => 43,
                'master_id'  => 0,
                'name'       => 'Product B',
                'model'      => 'B',
                'quantity'   => 2,
                'subtract'   => 1,
                'price'      => 300.0,
                'total'      => 600.0,
                'tax'        => 120.0,
                'reward'     => 0,
                'option'     => [],
                'subscription' => [],
            ],
        ];
        $totals = [
            ['extension' => 'opencart', 'code' => 'sub_total', 'title' => 'Sub-Total', 'value' => 1000.0, 'sort_order' => 1],
            ['extension' => 'opencart', 'code' => 'shipping', 'title' => 'Flat Shipping', 'value' => 5.0, 'sort_order' => 3],
            ['extension' => 'opencart', 'code' => 'total', 'title' => 'Total', 'value' => 1200.0, 'sort_order' => 9],
        ];
        $draft = (new CartOrderDraftFactory())->create(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            'Test Store',
            'https://example.test/',
            'INV-',
            $customer,
            $billing,
            $shipping,
            $products,
            $totals,
            1200.0,
            1,
            'bg-bg',
            1,
            'BGN',
            1.0,
            ['name' => 'Flat Rate', 'code' => 'flat.flat']
        );

        return new ValidatedFinancingSubmission(
            OperationEntryPoint::CART,
            PersistenceIntegrationHarness::TEST_STORE_ID,
            str_repeat('b', 64),
            hash('sha256', 'operation-cart'),
            777,
            null,
            $customer,
            $billing,
            $shipping,
            self::calculation(),
            $draft,
            hash('sha256', 'selection-cart'),
            hash('sha256', 'cart-fingerprint-cart'),
            'test-unicid',
            '2026-08-28 12:00:00',
            'phase6_test'
        );
    }

    public static function buildService(
        CheckoutOrderModelPort $orders,
        \Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository $attempts,
        \Opencart\System\Library\Extension\MtUniCredit\OperationLockRepository $locks,
        OrderCorrelationStoreInterface $correlations,
        ?int $productCartStatusId = null
    ): OrderMaterializationService {
        $materializer = self::buildMaterializer($orders, $correlations);
        $statusPolicy = new FinancingOrderStatusPolicy(
            $productCartStatusId ?? self::TEST_AWAITING_STATUS_ID,
            self::TEST_VOID_STATUS_ID
        );
        $checkoutGateway = new CheckoutExistingOrderGateway($orders, new OpenCartOrderVerifier(), $statusPolicy);

        return new OrderMaterializationService(
            $attempts,
            $locks,
            $materializer,
            $checkoutGateway,
            $orders,
            $statusPolicy
        );
    }

    public static function buildMaterializer(
        CheckoutOrderModelPort $orders,
        OrderCorrelationStoreInterface $correlations
    ): OpenCartOrderMaterializer {
        return new OpenCartOrderMaterializer(
            $orders,
            new OpenCartOrderDataBuilder(),
            new OpenCartOrderVerifier(),
            $correlations
        );
    }

    public static function checkoutSubmissionForOrder(int $orderId): ValidatedFinancingSubmission
    {
        $submission = self::productSubmission(OperationEntryPoint::CHECKOUT, $orderId);
        $draft = $submission->orderDraft;
        $draft->paymentMethod = PaymentIdentity::paymentMethod();

        return new ValidatedFinancingSubmission(
            OperationEntryPoint::CHECKOUT,
            $submission->storeId,
            null,
            hash('sha256', 'operation-checkout'),
            999,
            $orderId,
            $submission->customer,
            $submission->billingAddress,
            null,
            $submission->financingCalculation,
            $draft,
            $submission->selectionHash,
            $submission->cartFingerprint,
            $submission->shopUnicid,
            $submission->shopSnapshotFetchedAt,
            'phase6_checkout_test'
        );
    }

    public static function attemptContext(array $row): FinancingAttemptContext
    {
        return new FinancingAttemptContext($row);
    }
}
