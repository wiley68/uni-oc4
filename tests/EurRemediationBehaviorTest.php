<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness as H;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness as DB;
use MtUniCredit\Tests\Support\ProductFinancingTestHarness as P;
use Opencart\System\Library\Extension\MtUniCredit as U;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/OpenCartEngineStub.php';
require_once (getenv('OPENCART_ROOT') ?: dirname(__DIR__, 3)) . '/system/engine/model.php';
require_once (getenv('OPENCART_ROOT') ?: dirname(__DIR__, 3)) . '/system/library/cart/currency.php';

final class EurRemediationBehaviorTest extends TestCase
{
    private ?string $requestMethod;

    protected function setUp(): void
    {
        $this->requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        DB::resetTables();
    }

    protected function tearDown(): void
    {
        if ($this->requestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->requestMethod;
        }
    }

    public static function checkoutCases(): array
    {
        return ['unchanged EUR 600' => [false], 'changed EUR 601' => [true]];
    }

    #[DataProvider('checkoutCases')]
    public function testCheckoutControllerIssuanceToSubmission(bool $changed): void
    {
        $attempts = new U\FinancingAttemptRepository(DB::connection());
        $orders = new InMemoryCheckoutOrderAdapter();
        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate();
        $service = P::checkoutSubmissionService($attempts, $orders, $transport);
        $submission = H::productSubmission('checkout', 123);
        $submission->orderDraft->storeId = P::STORE_ID;
        $submission->orderDraft->currencyValue = 0.5;
        $order = (new U\OpenCartOrderDataBuilder())->build($submission->orderDraft) + ['order_id' => 123, 'order_status_id' => 0];
        $orders->seedExistingOrder(123, $order, $order['products'], $order['totals']);
        $ports = $this->ports();
        $ports['session']->data['order_id'] = 123;
        $ports['session']->data['payment_method'] = U\PaymentIdentity::paymentMethod();
        $ports['request']->post += P::defaultSchemeSelection() + ['consent' => [1], 'egn' => '1990011599', 'phone2' => '0888123456'];
        $nativeCart = (object) ['products' => [['product_id' => 42, 'price' => 500.0, 'quantity' => 2, 'tax_class_id' => 1]], 'total' => 1200.0];
        $ports['cart'] = new class($nativeCart) {
            public function __construct(public object $data) {}
            public function getProducts(): array { return $this->data->products; }
            public function getTotal(): float { return $this->data->total; }
        };
        $model = new class($ports, $order, $attempts, $service) extends \Opencart\Catalog\Model\Extension\MtUniCredit\Module\MtUniCreditCheckout {
            public function __construct(public array $ports, public array $order, public object $attempts, public object $service) {}
            public function __get(string $key): object { return $this->ports[$key]; }
            public function __isset(string $key): bool { return isset($this->ports[$key]); }
            public function resolveSessionOrder(): ?array { return $this->order; }
            public function getShopConfiguration(): ?array { return mt_uni_credit_valid_shop_snapshot(['uni_proces' => 1]); }
            public function getShopConfigurationForSubmission(): ?array { return $this->getShopConfiguration(); }
            public function createCartContextFactory(): U\OpenCartCartContextFactory {
                return new U\OpenCartCartContextFactory(fn(int $id): array => [10], fn(float $price, int $tax): float => $tax === 1 ? $price * 1.2 : $price);
            }
            public function createSubmissionIssuer(): U\CheckoutSubmissionIssuer { return new U\CheckoutSubmissionIssuer($this->attempts, new U\PersistenceClock()); }
            public function createSubmissionService(): U\CheckoutFinancingSubmissionService { return $this->service; }
            public function actorBindingHash(): string { return hash('sha256', 'eur-remediation-actor'); }
            public function buildOrderMaterialsFromOrder(int $id): array { return ['products' => $this->order['products'], 'totals' => $this->order['totals'], 'order_total' => $this->order['total'], 'shipping_required' => false]; }
            public function shopCacheMeta(): array { return ['unicid' => DB::TEST_UNICID, 'fetched_at' => gmdate('Y-m-d H:i:s')]; }
            public function storeAddressDefaults(): array { return []; }
            public function sessionCheckoutData(): array { return []; }
            public function verifiedOwnedAddressForOrder(array $order): ?array { return null; }
        };
        $ports['model_extension_mt_uni_credit_module_mt_uni_credit_checkout'] = $model;
        $controller = $this->controller('checkout', $ports);
        $controller->issueSubmission();
        $issued = json_decode($ports['response']->output, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($issued['success'], json_encode($issued));
        self::assertEquals(600.0, $issued['calculation']['price']);
        $token = $issued['submission_token'];
        $row = $attempts->findByToken(P::STORE_ID, $token);
        $this->suppressMail((int) $row['attempt_id']);
        $scheme = P::defaultSchemeSelection();
        $cart = $model->createCartContextForOrderTotal(600.0, 0.5);
        self::assertSame(U\CheckoutSelectionHash::hash(P::STORE_ID, 123, U\CartFingerprint::hash($cart, 'EUR'), 'EUR', 600.0, $scheme['scheme_key'], $scheme['scheme_type'], $scheme['kop_code'], $scheme['months'], $scheme['filter_id'], 0.0, $model->actorBindingHash()), $row['selection_hash']);
        $ports['request']->post['submission_token'] = $token;
        if ($changed) {
            // Hold the issued cart identity constant to exercise the monetary hash guard itself.
            try {
                $service->submit(
                    $model->getShopConfiguration(), P::STORE_ID, $token, $model->actorBindingHash(),
                    'session', 0, 1, $cart, U\CartFingerprint::hash($cart, 'EUR'), 'EUR',
                    'standard', $scheme['scheme_type'], $scheme['kop_code'], $scheme['months'],
                    $scheme['filter_id'], $scheme['scheme_key'], 0.0, $ports['request']->post,
                    $order['products'], $order['totals'], 1202.0, false, DB::TEST_UNICID,
                    gmdate('Y-m-d H:i:s'), 1, 'bg-bg', 1, 0.5, 'Test', 'https://example.test/',
                    'INV-', U\LockOwnerTokenGenerator::generate(), 123, $order
                );
                self::fail('Changed EUR 601 passed the issued EUR 600 hash.');
            } catch (U\ProductFinancingFlowException $exception) {
                self::assertSame('stale_selection', $exception->errorCode());
            }
            $model->order['total'] = 1202.0;
        }
        $controller->confirm();
        $result = json_decode($ports['response']->output, true, 512, JSON_THROW_ON_ERROR);
        if ($changed) {
            self::assertFalse($result['success']);
            self::assertSame('checkout_order_changed', $result['error_code']);
            self::assertSame(0, $transport->countOrderCreates());
        } else {
            self::assertTrue($result['success'], json_encode($result));
            self::assertSame('process2_prepared', $result['step']);
            $saved = $attempts->findByToken(P::STORE_ID, $token);
            $payload = json_decode($saved['cp_payload'], true, 512, JSON_THROW_ON_ERROR);
            self::assertEquals(600.0, $payload['price']);
            self::assertSame('EUR', $payload['currency']);
            self::assertSame(1, $transport->countOrderCreates());
        }
        self::assertSame(0, $orders->addOrderCallCount());
        self::assertSame(1200.0, $orders->getOrder(123)['total']);
        self::assertSame(0.5, $orders->getOrder(123)['currency_value']);
    }

    public static function precisionCases(): array
    {
        return [
            'half rate' => [100.009, 0.5, 'BASE', 50.00],
            'rate above one' => [100.0049, 10.0, 'BASE', 1000.05],
            'EUR base fractional golden' => [100.009, 1.0, 'EUR', 100.01],
            'EUR base established golden' => [1200.0, 1.0, 'EUR', 1200.00],
        ];
    }

    #[DataProvider('precisionCases')]
    public function testRealModelAndProductCalculatorPrecisionParity(float $native, float $factor, string $base, float $expected): void
    {
        $ports = $this->ports($factor, $base);
        $raw = [['product_id' => 42, 'name' => 'Native item', 'status' => 1, 'price' => $native, 'special' => 0, 'discount' => 0, 'quantity' => 1, 'tax_class_id' => 0]];
        $ports['cart'] = new class($raw, $native) {
            public function __construct(public array $products, public float $total) {}
            public function getProducts(): array { return $this->products; }
            public function getTotal(): float { return $this->total; }
        };
        $factory = new U\OpenCartCartContextFactory(fn(int $id): array => [10], fn(float $price, int $tax): float => $price);
        $cartModel = new class($ports, $factory) extends \Opencart\Catalog\Model\Extension\MtUniCredit\Module\MtUniCreditCart {
            public function __construct(public array $ports, public U\OpenCartCartContextFactory $factory) {}
            public function __get(string $key): object { return $this->ports[$key]; }
            public function __isset(string $key): bool { return isset($this->ports[$key]); }
            public function createCartContextFactory(): U\OpenCartCartContextFactory { return $this->factory; }
        };
        $checkoutModel = new class($ports, $factory) extends \Opencart\Catalog\Model\Extension\MtUniCredit\Module\MtUniCreditCheckout {
            public function __construct(public array $ports, public U\OpenCartCartContextFactory $factory) {}
            public function __get(string $key): object { return $this->ports[$key]; }
            public function __isset(string $key): bool { return isset($this->ports[$key]); }
            public function createCartContextFactory(): U\OpenCartCartContextFactory { return $this->factory; }
        };
        $resolver = new U\OpenCartCatalogProductResolver(P::STORE_ID, 1, $base, 'EUR', true,
            fn(int $id): array => $raw[0], fn(int $id): array => [10],
            fn(int $id, int $qty, array $options): array => ['option_price' => 0.0, 'order_options' => [], 'normalized' => []],
            fn(float $price, int $tax): float => $price,
            fn(float $amount, string $from, string $to): float => $ports['currency']->convert($amount, $from, $to)
        );
        $line = $resolver->resolveLine(P::STORE_ID, 42, 1, []);
        $calculator = new U\ProductSchemeCalculator(new U\Calculator(), new U\CurrencyGate(), new U\AmountDisplayFormatter());
        $shop = P::shop();
        $shop['uni_minstojnost'] = 1;
        $product = $calculator->calculate($shop, new U\ProductContext(42, [10], $line->financingPrice), 'EUR', 'standard', 'standard', 'KOPSTD', 12, 0, 'standard|KOPSTD|12|0', 0.0);
        self::assertSame($expected, $product['price']);
        self::assertSame($native, $line->unitPriceExTax);
        $cart = $cartModel->createCartContext();
        self::assertSame($expected, $cart->total);
        self::assertSame($expected, $cart->lines[0]->lineTotal);
        self::assertSame($expected, $checkoutModel->createCartContext()->total);
        $materials = (new U\OpenCartCartOrderProductsBuilder())->build($raw, fn(float $price, int $tax): float => 0.0);
        self::assertSame($native, $materials['order_total']);
        self::assertSame($native, $materials['products'][0]['total']);
        $order = ['total' => $materials['order_total'], 'currency_code' => 'EUR', 'currency_id' => 1, 'currency_value' => $factor];
        $checkoutAmount = $checkoutModel->financingAmountForOrder($order);
        self::assertSame($expected, $checkoutAmount);
        $historicalCart = $checkoutModel->createCartContextForOrderTotal($checkoutAmount, $factor);
        self::assertSame($expected, $historicalCart->total);
        self::assertSame($expected, $historicalCart->lines[0]->lineTotal);
    }

    public static function recoveryCases(): array
    {
        $cases = [];
        foreach (['product', 'cart'] as $entry) {
            foreach (['cp_created', 'cp_failed_retryable', 'order_created', 'cp_outcome_unknown', 'smart_created'] as $state) {
                $cases[$entry . ' ' . $state] = [$entry, $state];
            }
        }
        return $cases;
    }

    #[DataProvider('recoveryCases')]
    public function testControllerRecoversHistoricalOrderBeforeCurrentRate(string $entry, string $state): void
    {
        [$service, $row, $orders, $transport, $payload] = $this->boundAttempt($entry, $state);
        $ports = $this->ports(); // Current native EUR factor is 0.4; saved order factor is 0.5.
        $model = new class($service, $state !== 'smart_created') {
            public function __construct(public object $service, public bool $process2) {}
            public function countActiveCartProducts(): int { return 1; }
            public function getShopConfigurationForSubmission(): array { return mt_uni_credit_valid_shop_snapshot(['uni_proces' => $this->process2 ? 1 : 0]); }
            public function createSubmissionService(): object { return $this->service; }
            public function actorBindingHash(): string { return hash('sha256', 'eur-remediation-actor'); }
            public function __call(string $name, array $args): never { throw new \LogicException('Historical recovery read live storefront data: ' . $name); }
        };
        $ports['model_extension_mt_uni_credit_module_mt_uni_credit_' . $entry] = $model;
        $ports['request']->post['submission_token'] = $row['submission_token'];
        $ports['request']->post['cart_fingerprint'] = hash('sha256', 'old-rate-fingerprint');
        $controller = $this->controller($entry, $ports);
        $controller->submit();
        $result = json_decode($ports['response']->output, true, 512, JSON_THROW_ON_ERROR);
        if ($state === 'cp_outcome_unknown') {
            self::assertFalse($result['success']);
            self::assertSame(U\ControlPanelErrorClass::RECOVERY_FAILED, $result['error_code']);
            self::assertSame(0, $transport->countOrderCreates());
        } else {
            self::assertTrue($result['success'], json_encode($result));
            self::assertSame($state === 'smart_created' ? 'bank_redirect' : 'process2_prepared', $result['step']);
            self::assertSame(in_array($state, ['cp_created', 'smart_created'], true) ? 0 : 1, $transport->countOrderCreates());
            $controller->submit();
            self::assertTrue(json_decode($ports['response']->output, true)['success']);
            self::assertSame(in_array($state, ['cp_created', 'smart_created'], true) ? 0 : 1, $transport->countOrderCreates());
        }
        self::assertSame(0, $orders->addOrderCallCount());
        self::assertSame(1200.0, $orders->getOrder(123)['total']);
        self::assertSame(0.5, $orders->getOrder(123)['currency_value']);
        $saved = (new U\FinancingAttemptRepository(DB::connection()))->findById((int) $row['attempt_id']);
        $actualPayload = json_decode($saved['cp_payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals(600.0, $actualPayload['price']);
        if ($state !== 'order_created') {
            self::assertSame(json_encode($payload, JSON_THROW_ON_ERROR), $saved['cp_payload']);
        }
        self::assertSame($state === 'smart_created' ? 'created' : 'not_started', $saved['smartucf_state']);
    }

    public static function newSubmissionCases(): array
    {
        return ['product current' => ['product', false], 'product stale' => ['product', true], 'cart current' => ['cart', false], 'cart changed contents' => ['cart', true]];
    }

    #[DataProvider('newSubmissionCases')]
    public function testNewSubmissionsKeepCurrentPricingAndStaleGuards(string $entry, bool $stale): void
    {
        [$service, $attempts, $orders, $transport] = $this->services($entry);
        $ports = $this->ports();
        $raw = [['product_id' => 42, 'name' => 'Current item', 'status' => 1, 'price' => 1200.0, 'special' => 0, 'discount' => 0, 'quantity' => 1, 'tax_class_id' => 0, 'shipping' => 0]];
        $actor = hash('sha256', 'new-actor');
        $factory = new U\OpenCartCartContextFactory(fn(int $id): array => [10], fn(float $price, int $tax): float => $price);
        $convert = fn(float $amount): float => $ports['currency']->convert($amount, 'BASE', 'EUR');
        $cart = $factory->create($raw, 1200.0, $convert);
        self::assertSame(480.0, $cart->total);
        $fingerprint = U\CartFingerprint::hash($cart, 'EUR');
        if ($entry === 'product') {
            $resolver = new U\OpenCartCatalogProductResolver(P::STORE_ID, 1, 'BASE', 'EUR', true,
                fn(int $id): array => $raw[0], fn(int $id): array => [10],
                fn(int $id, int $qty, array $options): array => ['option_price' => 0.0, 'order_options' => [], 'normalized' => []],
                fn(float $price, int $tax): float => $price,
                fn(float $amount, string $from, string $to): float => $ports['currency']->convert($amount, $from, $to));
            (new \ReflectionProperty($service, 'productFactory'))->setValue($service, new U\OpenCartProductContextFactory($resolver));
            $hash = U\ProductSelectionHash::hash(P::STORE_ID, 42, [], 1, 'EUR', $stale ? 600.0 : 480.0, 'standard|KOPSTD|12|0', 'standard', 'KOPSTD', 12, 0, 0.0, $actor);
            $row = (new U\ProductSubmissionIssuer($attempts, new U\PersistenceClock()))->issueOrReuse(P::STORE_ID, hash('sha256', 'new-product'), $actor, $hash, null, DB::TEST_UNICID);
        } else {
            $hash = U\CartSelectionHash::hash(P::STORE_ID, $fingerprint, 'EUR', 480.0, 'standard|KOPSTD|12|0', 'standard', 'KOPSTD', 12, 0, 0.0, $actor);
            $row = (new U\CartSubmissionIssuer($attempts, new U\PersistenceClock()))->issueOrReuse(P::STORE_ID, hash('sha256', 'new-cart'), $actor, $hash, null, null, $fingerprint, DB::TEST_UNICID);
            if ($stale) {
                $raw[0]['product_id'] = 43;
                $cart = $factory->create($raw, 1200.0, $convert);
            }
        }
        $this->suppressMail((int) $row['attempt_id']);
        $shop = mt_uni_credit_valid_shop_snapshot(['uni_proces' => 1]);
        self::assertNull($service->resume($shop, P::STORE_ID, $row['submission_token'], $actor, U\LockOwnerTokenGenerator::generate()));
        $args = [
            'shop' => $shop, 'storeId' => P::STORE_ID, 'submissionToken' => $row['submission_token'], 'actorBindingHash' => $actor,
            'sessionFingerprint' => 'session', 'customerId' => 0, 'customerGroupId' => 1,
            'currencyCode' => 'EUR', 'popupType' => 'standard', 'schemeType' => 'standard', 'kopCode' => 'KOPSTD', 'months' => 12, 'filterId' => 0, 'schemeKey' => 'standard|KOPSTD|12|0', 'firstInstallment' => 0.0,
            'posted' => P::validPostedCustomer() + ['egn' => '1990011599', 'phone2' => '0888123456'],
            'shopUnicid' => DB::TEST_UNICID, 'shopSnapshotFetchedAt' => gmdate('Y-m-d H:i:s'), 'languageId' => 1, 'languageCode' => 'bg-bg', 'currencyId' => 1, 'currencyValue' => 0.4,
            'storeName' => 'Test', 'storeUrl' => 'https://example.test/', 'invoicePrefix' => 'INV-', 'lockOwnerToken' => U\LockOwnerTokenGenerator::generate(),
        ];
        if ($entry === 'product') {
            $args += ['productId' => 42, 'quantity' => 1, 'requestedOptions' => []];
        } else {
            $materials = (new U\OpenCartCartOrderProductsBuilder())->build($raw, fn(float $price, int $tax): float => 0.0);
            $args += ['cart' => $cart, 'expectedFingerprint' => $fingerprint, 'orderProducts' => $materials['products'], 'orderTotals' => $materials['totals'], 'orderTotal' => $materials['order_total'], 'shippingRequired' => false];
        }
        try {
            $result = $service->submit(...$args);
            self::assertFalse($stale, 'Stale new submission was accepted.');
            self::assertTrue($result->success);
            self::assertSame('process2_prepared', $result->step);
            $saved = $attempts->findById((int) $row['attempt_id']);
            self::assertEquals(480.0, json_decode($saved['cp_payload'], true)['price']);
            self::assertSame(1200.0, $orders->getOrder($result->orderId)['total']);
            self::assertSame(0.4, $orders->getOrder($result->orderId)['currency_value']);
            self::assertSame('not_started', $saved['smartucf_state']);
        } catch (U\ProductFinancingFlowException $exception) {
            self::assertTrue($stale, $exception->getMessage());
            self::assertSame($entry === 'product' ? 'stale_selection' : 'cart_changed', $exception->errorCode());
        }
        self::assertSame($stale ? 0 : 1, $orders->addOrderCallCount());
        self::assertSame($stale ? 0 : 1, $transport->countOrderCreates());
    }

    public static function authorizationCases(): array
    {
        return ['product actor' => ['product', 'actor'], 'cart actor' => ['cart', 'actor'], 'product token' => ['product', 'token'], 'cart token' => ['cart', 'token'], 'product expired' => ['product', 'expired'], 'cart expired' => ['cart', 'expired'], 'product store' => ['product', 'store'], 'cart store' => ['cart', 'store']];
    }

    #[DataProvider('authorizationCases')]
    public function testHistoricalRecoveryRetainsAuthorization(string $entry, string $invalid): void
    {
        [$service, $row, $orders, $transport] = $this->boundAttempt($entry, 'cp_created');
        if ($invalid === 'expired') {
            $table = DB::connection()->getPrefix() . U\PersistenceTableNames::FINANCING_ATTEMPT;
            DB::connection()->query("UPDATE `{$table}` SET `expires_at` = '2000-01-01 00:00:00' WHERE `attempt_id` = " . (int) $row['attempt_id']);
        }
        try {
            $service->resume(mt_uni_credit_valid_shop_snapshot(['uni_proces' => 1]), $invalid === 'store' ? 99 : P::STORE_ID, $invalid === 'token' ? str_repeat('f', 64) : $row['submission_token'], hash('sha256', $invalid === 'actor' ? 'wrong' : 'eur-remediation-actor'), U\LockOwnerTokenGenerator::generate());
            self::fail('Unauthorized or expired attempt resumed.');
        } catch (U\ProductFinancingFlowException $exception) {
            self::assertSame(match ($invalid) { 'actor' => 'attempt_conflict', 'expired' => 'expired_attempt', default => 'validation' }, $exception->errorCode());
        }
        self::assertSame(0, $orders->addOrderCallCount());
        self::assertSame(0, $transport->countOrderCreates());
    }

    private function services(string $entry): array
    {
        $db = DB::connection();
        $attempts = new U\FinancingAttemptRepository($db);
        $orders = new InMemoryCheckoutOrderAdapter();
        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate(901);
        $product = P::submissionService($attempts, $orders, $transport);
        $service = $product;
        if ($entry === 'cart') {
            $prop = fn(string $name) => (new \ReflectionProperty($product, $name))->getValue($product);
            $calculator = new U\Calculator();
            $resolver = new U\CartSchemeResolver($calculator);
            $service = new U\CartFinancingSubmissionService($attempts, $prop('locks'), $prop('materialization'), new U\CartSchemeCalculator($calculator, $resolver, new U\CurrencyGate(), new U\AmountDisplayFormatter()), $calculator, $resolver, new U\ProductCustomerValidator(), new U\ProductAddressValidator(), $prop('addressCatalog'), new U\ConsentResolver(), new U\CartOrderDraftFactory(), new U\PersistenceClock(), $prop('cpLifecycle'));
        }
        return [$service, $attempts, $orders, $transport];
    }

    private function boundAttempt(string $entry, string $state): array
    {
        $process1Replay = $state === 'smart_created';
        $state = $process1Replay ? 'cp_created' : $state;
        $db = DB::connection();
        [$service, $attempts, $orders, $transport] = $this->services($entry);
        $submission = H::productSubmission($entry);
        $submission->storeId = P::STORE_ID;
        $submission->orderDraft->storeId = P::STORE_ID;
        $submission->orderDraft->currencyValue = 0.5;
        $submission->financingCalculation = H::calculation(600.0);
        $row = $attempts->issueWithSubmissionToken(P::STORE_ID, $entry, hash('sha256', 'historical-' . $entry), hash('sha256', 'eur-remediation-actor'), hash('sha256', 'historical-selection'), DB::TEST_UNICID);
        $id = (int) $row['attempt_id'];
        $attempts->attachOrder($id, 123);
        DB::seedSuccessfulEurAttempt($id, 123, $submission, 901, !$process1Replay);
        $native = (new U\OpenCartOrderDataBuilder())->build($submission->orderDraft);
        $orders->seedExistingOrder(123, $native, $native['products'], $native['totals']);
        $submission->eurOrderProof = new U\DurableEurOrderProof(123, P::STORE_ID, 1, 0.5, 1200, 600);
        $payload = (new U\ControlPanelOrderPayloadBuilder())->build($submission, 123, P::shop());
        $attempts->persistCpPayload($id, $payload);
        U\ProcessTwoSubmissionSupport::validateAndPersist(['uni_proces' => 1], ['egn' => '1990011599', 'phone2' => '0888123456'], $submission, $id, $db);
        $table = $db->getPrefix() . U\PersistenceTableNames::FINANCING_ATTEMPT;
        $cpId = $state === 'cp_created' ? '901' : 'NULL';
        $process = $state === 'cp_created' ? 'process2_prepared' : 'not_started';
        $db->query("UPDATE `{$table}` SET `state` = '{$state}', `control_panel_order_id` = {$cpId}, `process2_state` = '{$process}', `process2_mail_sent` = 1, `process2_mail_state` = 'sent' WHERE `attempt_id` = {$id}");
        if ($process1Replay) {
            $db->query("UPDATE `{$table}` SET `process2_state` = 'not_started', `smartucf_state` = 'created', `smartucf_session_id` = 'saved-session', `smartucf_redirect_url` = 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/saved-session' WHERE `attempt_id` = {$id}");
        }
        if ($state === 'order_created') {
            $db->query("UPDATE `{$table}` SET `cp_payload` = NULL WHERE `attempt_id` = {$id}");
        }
        return [$service, $attempts->findById($id), $orders, $transport, $payload];
    }

    private function suppressMail(int $attemptId): void
    {
        // Mail delivery is outside these currency tests; prevent a system mail call.
        $db = DB::connection();
        $table = $db->getPrefix() . U\PersistenceTableNames::FINANCING_ATTEMPT;
        $db->query("UPDATE `{$table}` SET `process2_mail_sent` = 1, `process2_mail_state` = 'sent' WHERE `attempt_id` = {$attemptId}");
    }

    private function ports(float $factor = 0.4, string $base = 'BASE'): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $currency = (new \ReflectionClass(\Opencart\System\Library\Cart\Currency::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($currency, 'currencies'))->setValue($currency, ['BASE' => ['currency_id' => 2, 'value' => 1.0], 'EUR' => ['currency_id' => 1, 'value' => $factor, 'decimal_place' => 2, 'symbol_left' => '', 'symbol_right' => '']]);
        return [
            'config' => new class($base) { public function __construct(public string $base) {} public function get(string $key): mixed { return ['config_store_id' => P::STORE_ID, 'config_currency' => $this->base, 'config_language' => 'bg-bg', 'config_language_id' => 1, 'config_customer_group_id' => 1][$key] ?? null; } },
            'session' => new class { public array $data = ['currency' => 'EUR', U\ProductStorefrontCsrf::SESSION_KEY => 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc']; public function getId(): string { return 'eur-remediation'; } },
            'request' => (object) ['post' => ['csrf_token' => str_repeat('c', 64)], 'server' => []],
            'response' => new class { public string $output = ''; public function addHeader(string $header): void {} public function setOutput(string $output): void { $this->output = $output; } },
            'load' => new class { public function model(string $route): void {} public function language(string $route): void {} },
            'language' => new class { public function get(string $key): string { return $key; } },
            'customer' => new class { public function isLogged(): bool { return false; } },
            'url' => new class { public function link(string $route, string $args, bool $secure): string { return 'https://example.test/' . $route; } },
            'log' => new class { public function write(string $message): void { throw new \RuntimeException($message); } },
            'currency' => $currency,
        ];
    }

    private function controller(string $entry, array $ports): object
    {
        return match ($entry) {
            'checkout' => new class($ports) extends \Opencart\Catalog\Controller\Extension\MtUniCredit\Payment\MtUniCredit {
                public function __construct(public array $ports) {} public function __get(string $key): object { return $this->ports[$key]; }
            public function __isset(string $key): bool { return isset($this->ports[$key]); }
            },
            'product' => new class($ports) extends \Opencart\Catalog\Controller\Extension\MtUniCredit\Module\MtUniCreditProduct {
                public function __construct(public array $ports) {} public function __get(string $key): object { return $this->ports[$key]; }
            public function __isset(string $key): bool { return isset($this->ports[$key]); }
            },
            'cart' => new class($ports) extends \Opencart\Catalog\Controller\Extension\MtUniCredit\Module\MtUniCreditCart {
                public function __construct(public array $ports) {} public function __get(string $key): object { return $this->ports[$key]; }
            public function __isset(string $key): bool { return isset($this->ports[$key]); }
            },
        };
    }
}
