<?php

namespace Opencart\Catalog\Model\Extension\MtUniCredit\Module;

use Opencart\System\Library\Extension\MtUniCredit\AmountDisplayFormatter;
use Opencart\System\Library\Extension\MtUniCredit\Calculator;
use Opencart\System\Library\Extension\MtUniCredit\ConsentResolver;
use Opencart\System\Library\Extension\MtUniCredit\CpServiceFactory;
use Opencart\System\Library\Extension\MtUniCredit\CurrencyGate;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingCustomerData;
use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderStatusPolicy;
use Opencart\System\Library\Extension\MtUniCredit\InstallmentLabelFormatter;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartCatalogAddressResolver;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartCatalogProductResolver;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartDbConnection;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartModuleSettingStore;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderDataBuilder;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderMaterializer;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartOrderVerifier;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartProductContextFactory;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartProductOrderDraftBuilder;
use Opencart\System\Library\Extension\MtUniCredit\OperationLockRepository;
use Opencart\System\Library\Extension\MtUniCredit\OrderCorrelationRepository;
use Opencart\System\Library\Extension\MtUniCredit\OrderMaterializationService;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductAddressValidator;
use Opencart\System\Library\Extension\MtUniCredit\ProductCalculatorPresenter;
use Opencart\System\Library\Extension\MtUniCredit\ProductPopupCustomerPrefill;
use Opencart\System\Library\Extension\MtUniCredit\ProductCustomerValidator;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingAvailability;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingSubmissionService;
use Opencart\System\Library\Extension\MtUniCredit\ProductModalPresenter;
use Opencart\System\Library\Extension\MtUniCredit\ProductOrderDraftFactory;
use Opencart\System\Library\Extension\MtUniCredit\ProductSchemeCalculator;
use Opencart\System\Library\Extension\MtUniCredit\ProductSchemeList;
use Opencart\System\Library\Extension\MtUniCredit\ProductSubmissionIssuer;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAddressData;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;

/**
 * Catalog product financing services and OpenCart runtime adapters.
 */
class MtUniCreditProduct extends \Opencart\System\Engine\Model
{
    /** @var array<string, mixed>|null */
    private ?array $shopCacheMeta = null;

    public function isModuleEnabled(): bool
    {
        return (bool) $this->config->get(ModuleConstants::MODULE_SETTING_CODE . '_status');
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
                    'unicid'      => (string) ($shop['unicid'] ?? ''),
                    'fetched_at'  => (string) ($services['presenter']->present()['cache_fetched_at'] ?? gmdate('Y-m-d H:i:s')),
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

    public function createProductContextFactory(bool $requireSelectedOptions = true): OpenCartProductContextFactory
    {
        return new OpenCartProductContextFactory($this->createProductCatalogResolver($requireSelectedOptions));
    }

    /**
     * Product page / AJAX calculate: allow missing required options (base price).
     * Issue/submit keep strict option validation via {@see createProductContextFactory(true)}.
     */
    public function createDisplayProductContextFactory(): OpenCartProductContextFactory
    {
        return $this->createProductContextFactory(false);
    }

    public function createCalculatorPresenter(): ProductCalculatorPresenter
    {
        $calculator = new Calculator();

        return new ProductCalculatorPresenter(
            $calculator,
            new CurrencyGate(),
            new ProductSchemeList($calculator),
            new InstallmentLabelFormatter()
        );
    }

    public function createSchemeCalculator(): ProductSchemeCalculator
    {
        return new ProductSchemeCalculator(new Calculator(), new CurrencyGate(), new AmountDisplayFormatter());
    }

    public function createAvailabilityGate(): ProductFinancingAvailability
    {
        return new ProductFinancingAvailability(new CurrencyGate(), new Calculator());
    }

    public function createModalPresenter(): ProductModalPresenter
    {
        return new ProductModalPresenter(new ConsentResolver());
    }

    public function createSubmissionIssuer(): ProductSubmissionIssuer
    {
        return new ProductSubmissionIssuer($this->createAttemptRepository(), new \Opencart\System\Library\Extension\MtUniCredit\PersistenceClock());
    }

    public function createSubmissionService(): ProductFinancingSubmissionService
    {
        $db = $this->createDbConnection();
        $attempts = new FinancingAttemptRepository($db);
        $locks = new OperationLockRepository($db);
        $orders = new CatalogCheckoutOrderAdapter($this);
        $correlations = new OrderCorrelationRepository($db);
        $statusPolicy = new FinancingOrderStatusPolicy((int) $this->config->get(ModuleConstants::AWAITING_FINANCING_ORDER_STATUS_SETTING));
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
            new \Opencart\System\Library\Extension\MtUniCredit\CheckoutExistingOrderGateway($orders, $verifier, $statusPolicy),
            $orders,
            $statusPolicy
        );

        return new ProductFinancingSubmissionService(
            $attempts,
            $locks,
            $materialization,
            $this->createProductContextFactory(),
            $this->createSchemeCalculator(),
            new ProductCustomerValidator(),
            new ProductAddressValidator(),
            $this->createAddressResolver(),
            new ConsentResolver(),
            new OpenCartProductOrderDraftBuilder(new ProductOrderDraftFactory()),
            new \Opencart\System\Library\Extension\MtUniCredit\PersistenceClock(),
            new Calculator()
        );
    }

    public function actorBindingHash(): string
    {
        $storeId = (int) $this->config->get('config_store_id');
        $customerId = $this->customer->isLogged() ? (int) $this->customer->getId() : 0;
        $sessionFingerprint = \Opencart\System\Library\Extension\MtUniCredit\ProductActorBinding::sessionFingerprint(
            (string) $this->session->getId()
        );

        return \Opencart\System\Library\Extension\MtUniCredit\ProductActorBinding::hash($storeId, $customerId, $sessionFingerprint);
    }

    /** @return array<string, mixed> */
    public function customerPrefill(): array
    {
        if (!$this->customer->isLogged()) {
            return ['is_logged' => false];
        }
        $this->load->model('account/customer');
        $this->load->model('account/address');
        $customerId = (int) $this->customer->getId();
        $customer = $this->model_account_customer->getCustomer($customerId);
        $addresses = array_values($this->model_account_address->getAddresses($customerId));
        $defaultAddressId = $this->resolveDefaultAddressId($addresses);

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

    /**
     * @param list<array<string, mixed>> $addresses
     */
    private function resolveDefaultAddressId(array $addresses): int
    {
        foreach ($addresses as $address) {
            if (!empty($address['default'])) {
                return (int) ($address['address_id'] ?? 0);
            }
        }

        return (int) ($addresses[0]['address_id'] ?? 0);
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

    public function countActiveCartProducts(): int
    {
        return count($this->cart->getProducts());
    }

    private function createAttemptRepository(): FinancingAttemptRepository
    {
        return new FinancingAttemptRepository($this->createDbConnection());
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

    private function createProductCatalogResolver(bool $requireSelectedOptions = true): OpenCartCatalogProductResolver
    {
        $storeId = (int) $this->config->get('config_store_id');
        $languageId = (int) $this->config->get('config_language_id');
        $customerGroupId = (int) $this->config->get('config_customer_group_id');
        $baseCurrency = (string) $this->config->get('config_currency');
        $displayCurrency = (string) ($this->session->data['currency'] ?? $baseCurrency);
        $customerPriceVisible = !$this->config->get('config_customer_price') || $this->customer->isLogged();
        $model = $this;

        return new OpenCartCatalogProductResolver(
            $storeId,
            $customerGroupId,
            $baseCurrency,
            $displayCurrency,
            (bool) $customerPriceVisible,
            function (int $productId) use ($model): ?array {
                $model->load->model('catalog/product');

                return $model->model_catalog_product->getProduct($productId) ?: null;
            },
            function (int $productId) use ($model): array {
                $model->load->model('catalog/product');
                $categories = $model->model_catalog_product->getCategories($productId);
                $ids = [];
                foreach ($categories as $category) {
                    $ids[] = (int) ($category['category_id'] ?? 0);
                }

                return $ids;
            },
            function (int $productId, int $quantity, array $requestedOptions) use ($model, $languageId, $requireSelectedOptions): array {
                return $model->resolveProductOptions(
                    $productId,
                    $quantity,
                    $requestedOptions,
                    $languageId,
                    $requireSelectedOptions
                );
            },
            function (float $price, int $taxClassId) use ($model): float {
                return (float) $model->tax->calculate($price, $taxClassId, (bool) $model->config->get('config_tax'));
            },
            function (float $price, string $from, string $to) use ($model): float {
                return (float) $model->currency->convert($price, $from, $to);
            }
        );
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
                // OpenCart 4.1: getAddress(customer_id, address_id) — both required.
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
            function (FinancingAddressData $shippingAddress, \Opencart\System\Library\Extension\MtUniCredit\OpenCartProductLine $line) use ($model): ?array {
                return $model->resolveDefaultShippingMethod($shippingAddress, $line);
            }
        );
    }

    /**
     * @param array<int, int|string|list<int>> $requestedOptions
     * @return array{option_price: float, order_options: list<array<string, mixed>>, normalized: array<int, int|string|list<int>>}
     */
    public function resolveProductOptions(
        int $productId,
        int $quantity,
        array $requestedOptions,
        int $languageId,
        bool $requireSelectedOptions = true
    ): array {
        $this->load->model('catalog/product');
        $productOptions = $this->model_catalog_product->getOptions($productId);
        $optionPrice = 0.0;
        $orderOptions = [];
        $normalized = [];

        foreach ($productOptions as $option) {
            $productOptionId = (int) ($option['product_option_id'] ?? 0);
            $isRequired = (bool) ($option['required'] ?? false);
            if ($productOptionId <= 0 || !array_key_exists($productOptionId, $requestedOptions)) {
                if ($requireSelectedOptions && $isRequired) {
                    throw new ProductFinancingFlowException(
                        'missing_required_option',
                        'Моля, изберете задължителните опции на продукта.'
                    );
                }
                continue;
            }
            $type = (string) ($option['type'] ?? '');
            $value = $requestedOptions[$productOptionId];
            if ($type === 'checkbox' && is_array($value)) {
                if ($requireSelectedOptions && $isRequired && $value === []) {
                    throw new ProductFinancingFlowException(
                        'missing_required_option',
                        'Моля, изберете задължителните опции на продукта.'
                    );
                }
                foreach ($value as $productOptionValueId) {
                    $resolved = $this->resolveOptionValue($option, (int) $productOptionValueId, $quantity, $languageId);
                    $optionPrice += $resolved['price_delta'];
                    $orderOptions[] = $resolved['order_option'];
                }
                $normalized[$productOptionId] = array_map('intval', $value);
                continue;
            }
            if (in_array($type, ['select', 'radio'], true)) {
                if ($requireSelectedOptions && $isRequired && (int) $value <= 0) {
                    throw new ProductFinancingFlowException(
                        'missing_required_option',
                        'Моля, изберете задължителните опции на продукта.'
                    );
                }
                $resolved = $this->resolveOptionValue($option, (int) $value, $quantity, $languageId);
                $optionPrice += $resolved['price_delta'];
                $orderOptions[] = $resolved['order_option'];
                $normalized[$productOptionId] = (int) $value;
                continue;
            }
            $scalar = is_scalar($value) ? trim((string) $value) : '';
            if ($requireSelectedOptions && $isRequired && $scalar === '') {
                throw new ProductFinancingFlowException(
                    'missing_required_option',
                    'Моля, изберете задължителните опции на продукта.'
                );
            }
            $orderOptions[] = [
                'product_option_id'       => $productOptionId,
                'product_option_value_id' => 0,
                'name'                    => (string) ($option['name'] ?? ''),
                'value'                   => $scalar,
                'type'                    => $type,
            ];
            $normalized[$productOptionId] = $scalar;
        }

        return [
            'option_price'  => $optionPrice,
            'order_options' => $orderOptions,
            'normalized'    => $normalized,
        ];
    }

    /**
     * @param array<string, mixed> $option
     * @return array{price_delta: float, order_option: array<string, mixed>}
     */
    private function resolveOptionValue(array $option, int $productOptionValueId, int $quantity, int $languageId): array
    {
        foreach ($option['product_option_value'] ?? [] as $optionValue) {
            if ((int) ($optionValue['product_option_value_id'] ?? 0) !== $productOptionValueId) {
                continue;
            }
            if ((bool) ($optionValue['subtract'] ?? false) && (int) ($optionValue['quantity'] ?? 0) < $quantity) {
                throw new ProductFinancingFlowException('validation', 'Selected option is out of stock.');
            }
            $delta = (float) ($optionValue['price'] ?? 0.0);
            if (($optionValue['price_prefix'] ?? '+') === '-') {
                $delta *= -1;
            }

            return [
                'price_delta'  => $delta,
                'order_option' => [
                    'product_option_id'       => (int) ($option['product_option_id'] ?? 0),
                    'product_option_value_id' => $productOptionValueId,
                    'name'                    => (string) ($option['name'] ?? ''),
                    'value'                   => (string) ($optionValue['name'] ?? ''),
                    'type'                    => (string) ($option['type'] ?? ''),
                ],
            ];
        }

        throw new ProductFinancingFlowException(
            'missing_required_option',
            'Моля, изберете задължителните опции на продукта.'
        );
    }

    /** @return array{name:string,code:string}|null */
    public function resolveDefaultShippingMethod(
        FinancingAddressData $address,
        \Opencart\System\Library\Extension\MtUniCredit\OpenCartProductLine $line
    ): ?array {
        if (!$line->shippingRequired) {
            return null;
        }
        $this->load->model('checkout/shipping_method');
        $shippingAddress = [
            'firstname'      => $address->firstname,
            'lastname'       => $address->lastname,
            'company'        => $address->company,
            'address_1'      => $address->address1,
            'address_2'      => $address->address2,
            'city'           => $address->city,
            'postcode'       => $address->postcode,
            'country_id'     => $address->countryId,
            'zone_id'        => $address->zoneId,
            'country'        => $address->country,
            'zone'           => $address->zone,
            'address_format' => $address->addressFormat,
            'custom_field'   => $address->customField,
        ];
        $methods = $this->model_checkout_shipping_method->getMethods($shippingAddress);
        foreach ($methods as $method) {
            if (!empty($method['quote'])) {
                foreach ($method['quote'] as $quote) {
                    return [
                        'name' => (string) ($quote['title'] ?? $method['title'] ?? 'Shipping'),
                        'code' => (string) ($quote['code'] ?? $method['code'] ?? 'flat.flat'),
                    ];
                }
            }
        }

        return null;
    }
}

/**
 * Thin catalog adapter over native checkout order model — keeps addOrder/getOrder in one place.
 */
final class CatalogCheckoutOrderAdapter implements \Opencart\System\Library\Extension\MtUniCredit\CheckoutOrderModelPort
{
    public function __construct(private MtUniCreditProduct $model)
    {
    }

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
