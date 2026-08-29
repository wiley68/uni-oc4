<?php

namespace Opencart\Catalog\Model\Extension\MtUniCredit\Module;

use Opencart\System\Library\Extension\MtUniCredit\AmountDisplayFormatter;
use Opencart\System\Library\Extension\MtUniCredit\Calculator;
use Opencart\System\Library\Extension\MtUniCredit\CartActorBinding;
use Opencart\System\Library\Extension\MtUniCredit\CartCalculatorPresenter;
use Opencart\System\Library\Extension\MtUniCredit\CartContext;
use Opencart\System\Library\Extension\MtUniCredit\CartFinancingSubmissionService;
use Opencart\System\Library\Extension\MtUniCredit\CartOrderDraftFactory;
use Opencart\System\Library\Extension\MtUniCredit\CartSchemeCalculator;
use Opencart\System\Library\Extension\MtUniCredit\CartSchemeResolver;
use Opencart\System\Library\Extension\MtUniCredit\CartSubmissionIssuer;
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
use Opencart\System\Library\Extension\MtUniCredit\OpenCartCartOrderProductsBuilder;
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
use Opencart\System\Library\Extension\MtUniCredit\ProductCustomerValidator;
use Opencart\System\Library\Extension\MtUniCredit\ProductModalPresenter;
use Opencart\System\Library\Extension\MtUniCredit\ProductPopupCustomerPrefill;

/**
 * Catalog cart financing services (Phase 8).
 */
class MtUniCreditCart extends \Opencart\System\Engine\Model
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

    public function createSubmissionIssuer(): CartSubmissionIssuer
    {
        return new CartSubmissionIssuer(
            new FinancingAttemptRepository($this->createDbConnection()),
            new \Opencart\System\Library\Extension\MtUniCredit\PersistenceClock()
        );
    }

    public function createSubmissionService(): CartFinancingSubmissionService
    {
        $db = $this->createDbConnection();
        $attempts = new FinancingAttemptRepository($db);
        $locks = new OperationLockRepository($db);
        $orders = new CartCatalogCheckoutOrderAdapter($this);
        $correlations = new OrderCorrelationRepository($db);
        $statusPolicy = new FinancingOrderStatusPolicy(
            FinancingOrderStatusPolicy::resolveConfiguredAwaitingStatusId(
                (int) $this->config->get(ModuleConstants::AWAITING_FINANCING_ORDER_STATUS_SETTING),
                (int) $this->config->get('payment_mt_uni_credit_order_status_id')
            ),
            (int) $this->config->get('config_void_status_id')
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
            new \Opencart\System\Library\Extension\MtUniCredit\CheckoutExistingOrderGateway($orders, $verifier, $statusPolicy),
            $orders,
            $statusPolicy
        );

        return new CartFinancingSubmissionService(
            $attempts,
            $locks,
            $materialization,
            $this->createSchemeCalculator(),
            new Calculator(),
            new CartSchemeResolver(new Calculator()),
            new ProductCustomerValidator(),
            new ProductAddressValidator(),
            $this->createAddressResolver(),
            new ConsentResolver(),
            new CartOrderDraftFactory(),
            new \Opencart\System\Library\Extension\MtUniCredit\PersistenceClock()
        );
    }

    public function buildOrderMaterials(): array
    {
        $builder = new OpenCartCartOrderProductsBuilder();
        $model = $this;

        return $builder->build(
            $this->cart->getProducts(),
            static function (float $price, int $taxClassId) use ($model): float {
                if (!(bool) $model->config->get('config_tax') || $taxClassId <= 0) {
                    return 0.0;
                }

                return (float) $model->tax->getTax($price, $taxClassId);
            }
        );
    }

    public function actorBindingHash(): string
    {
        $storeId = (int) $this->config->get('config_store_id');
        $customerId = $this->customer->isLogged() ? (int) $this->customer->getId() : 0;
        $sessionFingerprint = CartActorBinding::sessionFingerprint((string) $this->session->getId());

        return CartActorBinding::hash($storeId, $customerId, $sessionFingerprint);
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

    public function countActiveCartProducts(): int
    {
        return count($this->cart->getProducts());
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

/** Thin catalog adapter over native checkout order model for Cart entry point. */
final class CartCatalogCheckoutOrderAdapter implements \Opencart\System\Library\Extension\MtUniCredit\CheckoutOrderModelPort
{
    public function __construct(private MtUniCreditCart $model)
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
