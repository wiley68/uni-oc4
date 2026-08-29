<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\AmountDisplayFormatter;
use Opencart\System\Library\Extension\MtUniCredit\Calculator;
use Opencart\System\Library\Extension\MtUniCredit\ConsentResolver;
use Opencart\System\Library\Extension\MtUniCredit\CurrencyGate;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAddressData;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingCustomerData;
use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderStatusPolicy;
use Opencart\System\Library\Extension\MtUniCredit\InstallmentLabelFormatter;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartCatalogAddressResolver;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderDataBuilder;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderMaterializer;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderVerifier;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartProductContextFactory;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartProductOrderDraftBuilder;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartProductLine;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\OperationLockRepository;
use Opencart\System\Library\Extension\MtUniCredit\OrderCorrelationRepository;
use Opencart\System\Library\Extension\MtUniCredit\OrderMaterializationService;
use Opencart\System\Library\Extension\MtUniCredit\ProductActorBinding;
use Opencart\System\Library\Extension\MtUniCredit\ProductAddressValidator;
use Opencart\System\Library\Extension\MtUniCredit\ProductCalculatorPresenter;
use Opencart\System\Library\Extension\MtUniCredit\ProductCustomerValidator;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingSubmissionService;
use Opencart\System\Library\Extension\MtUniCredit\ProductOperationIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductOrderDraftFactory;
use Opencart\System\Library\Extension\MtUniCredit\ProductSchemeCalculator;
use Opencart\System\Library\Extension\MtUniCredit\ProductSchemeList;
use Opencart\System\Library\Extension\MtUniCredit\ProductSelectionHash;
use Opencart\System\Library\Extension\MtUniCredit\ProductSubmissionIssuer;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutExistingOrderGateway;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceClock;

require_once dirname(__DIR__, 2) . '/tests/fixtures/cp_shop_snapshot.php';

final class ProductFinancingTestHarness
{
    public const STORE_ID = 7;

    /** OpenCart default store — must be valid for Product identity and materialization. */
    public const DEFAULT_STORE_ID = 0;

    public static function shop(): array
    {
        return mt_uni_credit_valid_shop_snapshot();
    }

    public static function catalog(): InMemoryProductCatalogPort
    {
        return new InMemoryProductCatalogPort(
            [
                42 => ['name' => 'Simple', 'model' => 'SKU42', 'price' => 1000.0, 'shipping' => false, 'category_id' => 10],
                43 => ['name' => 'Special', 'model' => 'SKU43', 'special' => 800.0, 'price' => 1000.0, 'shipping' => false, 'category_id' => 11],
                44 => ['name' => 'Optioned', 'model' => 'SKU44', 'price' => 900.0, 'shipping' => true, 'category_id' => 12],
            ],
            [],
            [44 => [501 => 50.0]]
        );
    }

    public static function factory(?InMemoryProductCatalogPort $catalog = null): OpenCartProductContextFactory
    {
        return new OpenCartProductContextFactory($catalog ?? self::catalog());
    }

    public static function presenter(): ProductCalculatorPresenter
    {
        $calculator = new Calculator();

        return new ProductCalculatorPresenter(
            $calculator,
            new CurrencyGate(),
            new ProductSchemeList($calculator),
            new InstallmentLabelFormatter()
        );
    }

    public static function actorBinding(int $customerId = 0, string $session = 'sess-a', int $storeId = self::STORE_ID): string
    {
        return ProductActorBinding::hash(
            $storeId,
            $customerId,
            ProductActorBinding::sessionFingerprint($session)
        );
    }

    public static function selectionHash(
        OpenCartProductLine $line,
        string $schemeKey,
        string $schemeType,
        string $kopCode,
        int $months,
        int $filterId,
        float $firstInstallment,
        string $actorBinding,
        string $currency = 'BGN',
        int $storeId = self::STORE_ID
    ): string {
        return ProductSelectionHash::hash(
            $storeId,
            $line->productId,
            $line->normalizedOptions,
            $line->quantity,
            $currency,
            $line->financingPrice,
            $schemeKey,
            $schemeType,
            $kopCode,
            $months,
            $filterId,
            $firstInstallment,
            $actorBinding
        );
    }

    public static function submissionService(
        ?FinancingAttemptRepository $attempts = null,
        ?InMemoryCheckoutOrderAdapter $orders = null
    ): ProductFinancingSubmissionService {
        $db = PersistenceIntegrationHarness::connection();
        $attempts ??= new FinancingAttemptRepository($db);
        $locks = new OperationLockRepository($db);
        $orders ??= new InMemoryCheckoutOrderAdapter();
        $correlations = new OrderCorrelationRepository($db);
        $materializer = new OpenCartOrderMaterializer(
            $orders,
            new OpenCartOrderDataBuilder(),
            new OpenCartOrderVerifier(),
            $correlations
        );
        $materialization = new OrderMaterializationService(
            $attempts,
            $locks,
            $materializer,
            new CheckoutExistingOrderGateway($orders, new OpenCartOrderVerifier(), new FinancingOrderStatusPolicy(OrderMaterializationTestHarness::TEST_AWAITING_STATUS_ID)),
            $orders,
            new FinancingOrderStatusPolicy(OrderMaterializationTestHarness::TEST_AWAITING_STATUS_ID)
        );

        $addressResolver = new OpenCartCatalogAddressResolver(
            static fn(int $addressId, int $customerId): bool => $addressId === 900 && $customerId === 5,
            static fn(int $addressId, int $customerId): ?array => ($addressId === 900 && $customerId === 5) ? [
                'address_id' => 900,
                'firstname' => 'Ivan',
                'lastname' => 'Petrov',
                'company' => '',
                'address_1' => 'ul. Test 1',
                'address_2' => '',
                'city' => 'Sofia',
                'postcode' => '1000',
                'country' => 'Bulgaria',
                'country_id' => 33,
                'zone' => 'Sofia',
                'zone_id' => 4239,
            ] : null,
            static fn(array $posted, FinancingCustomerData $customer): FinancingAddressData => new FinancingAddressData(
                0,
                $customer->firstname,
                $customer->lastname,
                '',
                $posted['address_1'],
                $posted['address_2'] ?? '',
                $posted['city'],
                $posted['postcode'],
                'Bulgaria',
                (int) ($posted['country_id'] ?? 33),
                'Sofia',
                (int) ($posted['zone_id'] ?? 4239)
            ),
            static fn(FinancingAddressData $address, OpenCartProductLine $line): ?array => $line->shippingRequired
                ? ['name' => 'Flat', 'code' => 'flat.flat']
                : null
        );

        return new ProductFinancingSubmissionService(
            $attempts,
            $locks,
            $materialization,
            self::factory(),
            new ProductSchemeCalculator(new Calculator(), new CurrencyGate(), new AmountDisplayFormatter()),
            new ProductCustomerValidator(),
            new ProductAddressValidator(),
            $addressResolver,
            new ConsentResolver(),
            new OpenCartProductOrderDraftBuilder(new ProductOrderDraftFactory()),
            new PersistenceClock(),
            new Calculator()
        );
    }

    /** @return array<string, mixed> */
    public static function validPostedCustomer(): array
    {
        return [
            'firstname'  => 'Ivan',
            'lastname'   => 'Petrov',
            'email'      => 'ivan@example.test',
            'telephone'  => '0888000000',
            'address_1'  => 'ul. Test 1',
            'city'       => 'Sofia',
            'postcode'   => '1000',
            'country_id' => '33',
            'zone_id'    => '4239',
            'consent'    => [1],
        ];
    }

    /**
     * @return array{scheme_key:string,scheme_type:string,kop_code:string,months:int,filter_id:int,first_installment:float}
     */
    public static function defaultSchemeSelection(): array
    {
        return [
            'scheme_key'        => 'standard|KOPSTD|12|0',
            'scheme_type'       => 'standard',
            'kop_code'          => 'KOPSTD',
            'months'            => 12,
            'filter_id'         => 0,
            'first_installment' => 0.0,
        ];
    }
}
