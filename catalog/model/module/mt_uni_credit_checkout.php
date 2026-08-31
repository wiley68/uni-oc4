<?php

namespace Opencart\Catalog\Model\Extension\MtUniCredit\Module;

use Opencart\System\Library\Extension\MtUniCredit\AmountDisplayFormatter;
use Opencart\System\Library\Extension\MtUniCredit\Calculator;
use Opencart\System\Library\Extension\MtUniCredit\CartActorBinding;
use Opencart\System\Library\Extension\MtUniCredit\CartCalculatorPresenter;
use Opencart\System\Library\Extension\MtUniCredit\CartContext;
use Opencart\System\Library\Extension\MtUniCredit\CartOrderDraftFactory;
use Opencart\System\Library\Extension\MtUniCredit\CartSchemeCalculator;
use Opencart\System\Library\Extension\MtUniCredit\CartSchemeResolver;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutExistingOrderGateway;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutFinancingSubmissionService;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutFinancingEligibility;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutSubmissionIssuer;
use Opencart\System\Library\Extension\MtUniCredit\ConsentResolver;
use Opencart\System\Library\Extension\MtUniCredit\CpServiceFactory;
use Opencart\System\Library\Extension\MtUniCredit\CurrencyGate;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAddressData;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingCustomerData;
use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderStatusPolicy;
use Opencart\System\Library\Extension\MtUniCredit\InstallmentLabelFormatter;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartCartContextFactory;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartCatalogAddressResolver;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartDbConnection;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartModuleSettingStore;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderDataBuilder;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderMaterializer;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderVerifier;
use Opencart\System\Library\Extension\MtUniCredit\OperationLockRepository;
use Opencart\System\Library\Extension\MtUniCredit\OrderCorrelationRepository;
use Opencart\System\Library\Extension\MtUniCredit\OrderMaterializationService;
use Opencart\System\Library\Extension\MtUniCredit\ProductAddressValidator;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutCustomerValidator;
use Opencart\System\Library\Extension\MtUniCredit\ProductModalPresenter;
use Opencart\System\Library\Extension\MtUniCredit\ProductPopupCustomerPrefill;

/**
 * Catalog checkout payment financing services (Phase 9).
 */
class MtUniCreditCheckout extends \Opencart\System\Engine\Model
{
    /** @var array<string, mixed>|null */
    private ?array $shopCacheMeta = null;

    public function isModuleEnabled(): bool
    {
        return (bool) $this->config->get(ModuleConstants::MODULE_SETTING_CODE . '_status');
    }

    public function isPaymentEnabled(): bool
    {
        return (bool) $this->config->get('payment_' . ModuleConstants::PAYMENT_CODE . '_status');
    }

    public function isPaymentMethodEligible(string $currencyCode): bool
    {
        $shop = $this->getShopConfiguration();
        if ($shop === null) {
            return false;
        }

        $cart = $this->createCartContext();

        return $this->createEligibility()->isEligible(
            $shop,
            $cart,
            $currencyCode,
            $this->isModuleEnabled(),
            $this->isPaymentEnabled()
        );
    }

    /** @return array<string, mixed>|null */
    public function getShopConfiguration(): ?array
    {
        if (!defined('DB_PREFIX')) {
            return null;
        }
        try {
            $services = $this->createCpServices();
            $shop = $services['shopConfiguration']->getCachedOnly();
            if ($shop !== null) {
                $this->shopCacheMeta = [
                    'unicid'     => (string) ($shop['unicid'] ?? ''),
                    'fetched_at' => (string) ($services['presenter']->present()['cache_fetched_at'] ?? gmdate('Y-m-d H:i:s')),
                ];
            }

            return $shop;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{unicid:string,fetched_at:string} */
    public function shopCacheMeta(): array
    {
        return $this->shopCacheMeta ?? ['unicid' => '', 'fetched_at' => gmdate('Y-m-d H:i:s')];
    }

    public function createCartContext(): CartContext
    {
        return $this->createCartContextFactory()->create(
            $this->cart->getProducts(),
            (float) $this->cart->getTotal()
        );
    }

    /**
     * Cart lines for scheme resolution with financing amount = order total.
     */
    public function createCartContextForOrderTotal(float $orderTotal): CartContext
    {
        $base = $this->createCartContext();

        return new CartContext($base->lines, $orderTotal, $base->checkoutState);
    }

    public function createCartContextFactory(): OpenCartCartContextFactory
    {
        $model = $this;

        return new OpenCartCartContextFactory(
            function (int $productId) use ($model): array {
                $model->load->model('catalog/product');
                $categories = $model->model_catalog_product->getCategories($productId);
                $ids = [];
                foreach ($categories as $category) {
                    $ids[] = (int) ($category['category_id'] ?? 0);
                }

                return $ids;
            },
            function (float $price, int $taxClassId) use ($model): float {
                return (float) $model->tax->calculate($price, $taxClassId, (bool) $model->config->get('config_tax'));
            }
        );
    }

    public function createEligibility(): CheckoutFinancingEligibility
    {
        $calculator = new Calculator();

        return new CheckoutFinancingEligibility(
            $calculator,
            new CartSchemeResolver($calculator),
            new CurrencyGate()
        );
    }

    public function createCalculatorPresenter(): CartCalculatorPresenter
    {
        $calculator = new Calculator();

        return new CartCalculatorPresenter(
            new CartSchemeResolver($calculator),
            $calculator,
            new CurrencyGate(),
            new InstallmentLabelFormatter()
        );
    }

    public function createSchemeCalculator(): CartSchemeCalculator
    {
        return new CartSchemeCalculator(
            new Calculator(),
            new CartSchemeResolver(new Calculator()),
            new CurrencyGate(),
            new AmountDisplayFormatter()
        );
    }

    public function createModalPresenter(): ProductModalPresenter
    {
        return new ProductModalPresenter(new ConsentResolver());
    }

    public function createSubmissionIssuer(): CheckoutSubmissionIssuer
    {
        return new CheckoutSubmissionIssuer(
            new FinancingAttemptRepository($this->createDbConnection()),
            new \Opencart\System\Library\Extension\MtUniCredit\PersistenceClock()
        );
    }

    public function createSubmissionService(): CheckoutFinancingSubmissionService
    {
        $db = $this->createDbConnection();
        $attempts = new FinancingAttemptRepository($db);
        $locks = new OperationLockRepository($db);
        $orders = new CheckoutCatalogOrderAdapter($this);
        $correlations = new OrderCorrelationRepository($db);
        // Checkout does not apply Product/Cart payment status here (native confirm does).
        // productCartOrderStatusId=0 so payment Processing is not Checkout-reuse-eligible.
        // config_order_status_id: CP-failure visibility fallback + same-cart retry reuse.
        $failureVisibleStatusId = (int) $this->config->get('config_order_status_id');
        $statusPolicy = new FinancingOrderStatusPolicy(
            0,
            (int) $this->config->get('config_void_status_id'),
            $failureVisibleStatusId
        );
        $verifier = new OpenCartOrderVerifier();
        $materializer = new OpenCartOrderMaterializer(
            $orders,
            new OpenCartOrderDataBuilder(),
            $verifier,
            $correlations
        );
        $materialization = new OrderMaterializationService(
            $attempts,
            $locks,
            $materializer,
            new CheckoutExistingOrderGateway($orders, $verifier, $statusPolicy),
            $orders,
            $statusPolicy
        );

        return new CheckoutFinancingSubmissionService(
            $attempts,
            $locks,
            $materialization,
            $this->createSchemeCalculator(),
            new Calculator(),
            new CartSchemeResolver(new Calculator()),
            new CheckoutCustomerValidator(),
            new ProductAddressValidator(),
            $this->createAddressResolver(),
            new ConsentResolver(),
            new CartOrderDraftFactory(),
            new \Opencart\System\Library\Extension\MtUniCredit\PersistenceClock(),
            $this->createControlPanelLifecycle($db, $attempts, $locks),
            $orders,
            $failureVisibleStatusId
        );
    }

    private function createControlPanelLifecycle(
        \Opencart\System\Library\Extension\MtUniCredit\DbConnection $db,
        FinancingAttemptRepository $attempts,
        OperationLockRepository $locks
    ): \Opencart\System\Library\Extension\MtUniCredit\ControlPanelOrderLifecycleService {
        $services = $this->createCpServices();
        $logger = null;
        if ((int) $this->config->get(\Opencart\System\Library\Extension\MtUniCredit\ModuleLocalSettings::DEBUG_ENABLED) === 1) {
            $logger = static function (array $context): void {
                error_log('[mt_uni_credit] ' . json_encode($context, JSON_UNESCAPED_UNICODE));
            };
        }

        return new \Opencart\System\Library\Extension\MtUniCredit\ControlPanelOrderLifecycleService(
            $attempts,
            $locks,
            $services['client'],
            new \Opencart\System\Library\Extension\MtUniCredit\ControlPanelOrderPayloadBuilder(),
            $logger
        );
    }

    public function actorBindingHash(): string
    {
        $storeId = (int) $this->config->get('config_store_id');
        $customerId = $this->customer->isLogged() ? (int) $this->customer->getId() : 0;
        $sessionFingerprint = CartActorBinding::sessionFingerprint((string) $this->session->getId());

        return CartActorBinding::hash($storeId, $customerId, $sessionFingerprint);
    }

    /** @return array<string, mixed>|null */
    public function resolveSessionOrder(): ?array
    {
        $orderId = (int) ($this->session->data['order_id'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }

        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($orderId);
        if (!$order) {
            unset($this->session->data['order_id']);

            return null;
        }

        $storeId = (int) $this->config->get('config_store_id');
        if ((int) ($order['store_id'] ?? -1) !== $storeId) {
            unset($this->session->data['order_id']);

            return null;
        }

        // Session ownership: logged-in customers may only finance their own order.
        if ($this->customer->isLogged()) {
            $customerId = (int) $this->customer->getId();
            if ($customerId > 0 && (int) ($order['customer_id'] ?? 0) !== $customerId) {
                unset($this->session->data['order_id']);

                return null;
            }
        }

        // Defense in depth: refuse Voided/stale order when live cart has moved on.
        // Grand total must match confirm (includes shipping) — not cart->getTotal().
        $orderProducts = $this->model_checkout_order->getProducts($orderId) ?: [];
        $cartProducts = $this->cart->getProducts();
        $checkoutGrandTotal = $this->liveCheckoutGrandTotal();
        $currency = (string) ($this->session->data['currency'] ?? $this->config->get('config_currency'));
        $getOptions = function (int $oid, int $orderProductId): array {
            return $this->model_checkout_order->getOptions($oid, $orderProductId) ?: [];
        };
        if (\Opencart\System\Library\Extension\MtUniCredit\CheckoutSessionOrderGuard::reconcileSessionOrder(
            $this->session->data,
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            $checkoutGrandTotal,
            $currency
        )) {
            return null;
        }

        return $order;
    }

    /**
     * Same grand-total base native confirm writes to order.total (shipping included).
     */
    public function liveCheckoutGrandTotal(): float
    {
        $this->load->model('checkout/cart');
        $taxes = $this->cart->getTaxes();

        return \Opencart\System\Library\Extension\MtUniCredit\CheckoutLiveGrandTotal::compute(
            $this->model_checkout_cart->getTotals,
            $taxes
        );
    }

    /** @param array<string, mixed> $order */
    public function financingAmountForOrder(array $order): float
    {
        return round((float) ($order['total'] ?? 0.0), 2);
    }

    /**
     * Materials from the existing native order for draft/verifier parity.
     *
     * @return array{products:list<array<string,mixed>>,totals:list<array<string,mixed>>,order_total:float,shipping_required:bool}
     */
    public function buildOrderMaterialsFromOrder(int $orderId): array
    {
        $adapter = new CheckoutCatalogOrderAdapter($this);
        $products = $adapter->getProducts($orderId);
        $totals = $adapter->getTotals($orderId);
        $order = $adapter->getOrder($orderId);
        $shippingCode = '';
        $shippingMethod = $order['shipping_method'] ?? [];
        if (is_string($shippingMethod)) {
            $decoded = json_decode($shippingMethod, true);
            $shippingMethod = is_array($decoded) ? $decoded : [];
        }
        if (is_array($shippingMethod)) {
            $shippingCode = (string) ($shippingMethod['code'] ?? '');
        }

        return [
            'products'           => $products,
            'totals'             => $totals,
            'order_total'        => (float) ($order['total'] ?? 0.0),
            'shipping_required'  => $shippingCode !== '',
        ];
    }

    /**
     * Current native Checkout session slices used by CheckoutOrderCustomerAdapter.
     *
     * @return array{
     *     customer: array<string, mixed>,
     *     payment_address: array<string, mixed>,
     *     shipping_address: array<string, mixed>
     * }
     */
    public function sessionCheckoutData(): array
    {
        $customer = $this->session->data['customer'] ?? [];
        $paymentAddress = $this->session->data['payment_address'] ?? [];
        $shippingAddress = $this->session->data['shipping_address'] ?? [];

        $customer = is_array($customer) ? $customer : [];
        $paymentAddress = is_array($paymentAddress) ? $paymentAddress : [];
        $shippingAddress = is_array($shippingAddress) ? $shippingAddress : [];

        // Logged-in: fill blanks from owned customer account (session may lag after address edits).
        if ($this->customer->isLogged()) {
            $customerId = (int) $this->customer->getId();
            $sessionCustomerId = (int) ($customer['customer_id'] ?? 0);
            if ($customerId > 0 && ($sessionCustomerId === 0 || $sessionCustomerId === $customerId)) {
                $this->load->model('account/customer');
                $account = $this->model_account_customer->getCustomer($customerId);
                if (is_array($account) && $account !== []) {
                    foreach (['firstname', 'lastname', 'email', 'telephone'] as $field) {
                        if (trim((string) ($customer[$field] ?? '')) === '' && trim((string) ($account[$field] ?? '')) !== '') {
                            $customer[$field] = $account[$field];
                        }
                    }
                    $customer['customer_id'] = $customerId;
                }
            }
        }

        return [
            'customer'         => $customer,
            'payment_address'  => $paymentAddress,
            'shipping_address' => $shippingAddress,
        ];
    }

    /**
     * Logged-in only: Address::getAddress when order/session address_id belongs to the customer.
     * Guest (customer_id=0) never loads address model rows.
     *
     * When native Checkout omits shipping/payment address steps (cart hasShipping=false and
     * config_checkout_payment_address off), order address_id columns stay 0. Fall back to the
     * customer's native default address book entry — same source as customerPrefillFromSession().
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>|null
     */
    public function verifiedOwnedAddressForOrder(array $order): ?array
    {
        $customerId = (int) ($order['customer_id'] ?? 0);
        if ($customerId <= 0 || !$this->customer->isLogged() || (int) $this->customer->getId() !== $customerId) {
            return null;
        }

        $session = $this->sessionCheckoutData();
        $addressId = (int) ($order['payment_address_id'] ?? 0);
        if ($addressId <= 0) {
            $addressId = (int) ($session['payment_address']['address_id'] ?? 0);
        }
        if ($addressId <= 0) {
            $addressId = (int) ($order['shipping_address_id'] ?? 0);
        }
        if ($addressId <= 0) {
            $addressId = (int) ($session['shipping_address']['address_id'] ?? 0);
        }
        if ($addressId <= 0) {
            $addressId = $this->defaultOwnedAddressId($customerId);
        }
        if ($addressId <= 0) {
            return null;
        }

        $this->load->model('account/address');
        $row = $this->model_account_address->getAddress($customerId, $addressId);
        if (!is_array($row) || $row === []) {
            return null;
        }

        return $row;
    }

    /** Native default address book id (default flag, else first row). */
    private function defaultOwnedAddressId(int $customerId): int
    {
        if ($customerId <= 0) {
            return 0;
        }

        $this->load->model('account/address');
        $addresses = array_values($this->model_account_address->getAddresses($customerId));
        foreach ($addresses as $address) {
            if (!empty($address['default'])) {
                return (int) ($address['address_id'] ?? 0);
            }
        }

        return (int) ($addresses[0]['address_id'] ?? 0);
    }

    /**
     * Display prefill from current native Checkout context (guest-safe). Not used as confirm authority.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function customerPrefillFromOrder(array $order): array
    {
        $adapter = new \Opencart\System\Library\Extension\MtUniCredit\CheckoutOrderCustomerAdapter();
        $resolved = $adapter->fromCheckoutContext(
            $order,
            $this->sessionCheckoutData(),
            $this->verifiedOwnedAddressForOrder($order)
        );
        $input = $resolved['input'];

        return [
            'firstname'  => (string) ($input['firstname'] ?? ''),
            'lastname'   => (string) ($input['lastname'] ?? ''),
            'address'    => (string) ($input['address'] ?? ''),
            'telephone'  => (string) ($input['telephone'] ?? ''),
            'email'      => (string) ($input['email'] ?? ''),
            'address_id' => 0,
            'is_logged'  => (int) ($order['customer_id'] ?? 0) > 0,
            'company'    => (string) ($input['company'] ?? ''),
            'city'       => (string) ($input['city'] ?? ''),
            'postcode'   => (string) ($input['postcode'] ?? ''),
            'country'    => (string) ($input['country'] ?? ''),
            'country_id' => (int) ($input['country_id'] ?? 0),
            'zone'       => (string) ($input['zone'] ?? ''),
            'zone_id'    => (int) ($input['zone_id'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    public function customerPrefillFromSession(): array
    {
        if (!$this->customer->isLogged()) {
            $customer = $this->session->data['customer'] ?? [];
            if (!is_array($customer) || $customer === []) {
                return ['is_logged' => false];
            }

            return [
                'firstname'  => trim((string) ($customer['firstname'] ?? '')),
                'lastname'   => trim((string) ($customer['lastname'] ?? '')),
                'address'    => '',
                'telephone'  => trim((string) ($customer['telephone'] ?? '')),
                'email'      => trim((string) ($customer['email'] ?? '')),
                'address_id' => 0,
                'is_logged'  => false,
            ];
        }

        $this->load->model('account/customer');
        $this->load->model('account/address');
        $customerId = (int) $this->customer->getId();
        $customer = $this->model_account_customer->getCustomer($customerId);
        $addresses = array_values($this->model_account_address->getAddresses($customerId));
        $defaultAddressId = 0;
        foreach ($addresses as $address) {
            if (!empty($address['default'])) {
                $defaultAddressId = (int) ($address['address_id'] ?? 0);
                break;
            }
        }
        if ($defaultAddressId <= 0) {
            $defaultAddressId = (int) ($addresses[0]['address_id'] ?? 0);
        }

        return (new ProductPopupCustomerPrefill())->present(
            true,
            [
                'firstname' => (string) ($customer['firstname'] ?? ''),
                'lastname'  => (string) ($customer['lastname'] ?? ''),
                'email'     => (string) $this->customer->getEmail(),
                'telephone' => (string) ($customer['telephone'] ?? ''),
            ],
            $addresses,
            $defaultAddressId
        );
    }

    /** @return array<string, mixed> */
    public function storeAddressDefaults(): array
    {
        $countryId = (int) $this->config->get('config_country_id');
        $zoneId = (int) $this->config->get('config_zone_id');
        $this->load->model('localisation/country');
        $this->load->model('localisation/zone');
        $country = $this->model_localisation_country->getCountry($countryId);
        $zone = $this->model_localisation_zone->getZone($zoneId);

        return [
            'country_id' => $countryId > 0 ? $countryId : 33,
            'zone_id'    => $zoneId > 0 ? $zoneId : 4239,
            'country'    => (string) ($country['name'] ?? 'Bulgaria'),
            'zone'       => (string) ($zone['name'] ?? ''),
            'city'       => (string) ($zone['name'] ?? 'Sofia'),
            'postcode'   => '1000',
        ];
    }

    private function createAddressResolver(): OpenCartCatalogAddressResolver
    {
        $model = $this;

        return new OpenCartCatalogAddressResolver(
            function (int $addressId, int $customerId) use ($model): bool {
                if ($customerId <= 0 || $addressId <= 0) {
                    return false;
                }
                $model->load->model('account/address');
                $address = $model->model_account_address->getAddress($customerId, $addressId);

                return $address !== [];
            },
            function (int $addressId, int $customerId) use ($model): ?array {
                if ($customerId <= 0 || $addressId <= 0) {
                    return null;
                }
                $model->load->model('account/address');
                $address = $model->model_account_address->getAddress($customerId, $addressId);

                return $address !== [] ? $address : null;
            },
            function (array $postedAddress, FinancingCustomerData $customer): FinancingAddressData {
                return new FinancingAddressData(
                    0,
                    $customer->firstname,
                    $customer->lastname,
                    (string) ($postedAddress['company'] ?? ''),
                    (string) ($postedAddress['address_1'] ?? ''),
                    (string) ($postedAddress['address_2'] ?? ''),
                    (string) ($postedAddress['city'] ?? ''),
                    (string) ($postedAddress['postcode'] ?? ''),
                    (string) ($postedAddress['country'] ?? ''),
                    (int) ($postedAddress['country_id'] ?? 0),
                    (string) ($postedAddress['zone'] ?? ''),
                    (int) ($postedAddress['zone_id'] ?? 0)
                );
            },
            function (): ?array {
                return null;
            }
        );
    }

    private function createDbConnection(): OpenCartDbConnection
    {
        return new OpenCartDbConnection($this->db, DB_PREFIX);
    }

    /** @return array<string, mixed> */
    private function createCpServices(): array
    {
        $storeId = (int) ($this->config->get('config_store_id') ?? 0);
        $connection = $this->createDbConnection();
        $settings = new OpenCartModuleSettingStore($connection);

        return CpServiceFactory::create(
            $connection,
            $settings,
            $storeId,
            (string) ($this->config->get('config_ssl') ?? ''),
            (string) ($this->config->get('config_url') ?? '')
        );
    }
}

/** Thin catalog adapter over native checkout order model for Checkout entry point. */
final class CheckoutCatalogOrderAdapter implements \Opencart\System\Library\Extension\MtUniCredit\CheckoutOrderModelPort
{
    public function __construct(private MtUniCreditCheckout $model) {}

    public function addOrder(array $orderData): int
    {
        $this->model->load->model('checkout/order');

        return (int) $this->model->model_checkout_order->addOrder($orderData);
    }

    /** @return array<string, mixed> */
    public function getOrder(int $orderId): array
    {
        $this->model->load->model('checkout/order');

        return $this->model->model_checkout_order->getOrder($orderId) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public function getProducts(int $orderId): array
    {
        $this->model->load->model('checkout/order');

        return $this->model->model_checkout_order->getProducts($orderId);
    }

    /** @return list<array<string, mixed>> */
    public function getTotals(int $orderId): array
    {
        $this->model->load->model('checkout/order');

        return $this->model->model_checkout_order->getTotals($orderId);
    }

    /** @return list<array<string, mixed>> */
    public function getProductOptions(int $orderId, int $orderProductId): array
    {
        $this->model->load->model('checkout/order');

        return $this->model->model_checkout_order->getOptions($orderId, $orderProductId);
    }

    public function addHistory(int $orderId, int $orderStatusId, string $comment = '', bool $notify = false): void
    {
        $this->model->load->model('checkout/order');
        $this->model->model_checkout_order->addHistory($orderId, $orderStatusId, $comment, $notify);
    }
}
