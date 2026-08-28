<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Authoritative normalized order draft for Product/Cart materialization.
 *
 * @phpstan-type OrderDraftProductOption array{
 *     product_option_id: int,
 *     product_option_value_id: int,
 *     name: string,
 *     value: string,
 *     type: string
 * }
 * @phpstan-type OrderDraftProduct array{
 *     product_id: int,
 *     master_id: int,
 *     name: string,
 *     model: string,
 *     quantity: int,
 *     subtract: int,
 *     price: float,
 *     total: float,
 *     tax: float,
 *     reward: int,
 *     option: list<OrderDraftProductOption>,
 *     subscription: array<string, mixed>
 * }
 * @phpstan-type OrderDraftTotal array{
 *     extension: string,
 *     code: string,
 *     title: string,
 *     value: float,
 *     sort_order: int
 * }
 * @phpstan-type OrderDraftPaymentMethod array{name: string, code: string}
 * @phpstan-type OrderDraftShippingMethod array{name: string, code: string}|null
 */
final class OrderDraft
{
    public int $storeId;

    public string $storeName;

    public string $storeUrl;

    public string $invoicePrefix;

    public FinancingCustomerData $customer;

    public FinancingAddressData $billingAddress;

    public ?FinancingAddressData $shippingAddress;

    /** @var OrderDraftPaymentMethod */
    public array $paymentMethod;

    /** @var OrderDraftShippingMethod */
    public ?array $shippingMethod;

    /** @var list<OrderDraftProduct> */
    public array $products;

    /** @var list<OrderDraftTotal> */
    public array $totals;

    public float $orderTotal;

    public int $languageId;

    public string $languageCode;

    public int $currencyId;

    public string $currencyCode;

    public float $currencyValue;

    public string $comment;

    public string $ip;

    public string $forwardedIp;

    public string $userAgent;

    public string $acceptLanguage;

    /**
     * @param list<OrderDraftProduct> $products
     * @param list<OrderDraftTotal> $totals
     * @param OrderDraftPaymentMethod $paymentMethod
     * @param OrderDraftShippingMethod $shippingMethod
     */
    public function __construct(
        int $storeId,
        string $storeName,
        string $storeUrl,
        string $invoicePrefix,
        FinancingCustomerData $customer,
        FinancingAddressData $billingAddress,
        ?FinancingAddressData $shippingAddress,
        array $paymentMethod,
        ?array $shippingMethod,
        array $products,
        array $totals,
        float $orderTotal,
        int $languageId,
        string $languageCode,
        int $currencyId,
        string $currencyCode,
        float $currencyValue,
        string $comment = '',
        string $ip = '',
        string $forwardedIp = '',
        string $userAgent = '',
        string $acceptLanguage = ''
    ) {
        $this->storeId = $storeId;
        $this->storeName = $storeName;
        $this->storeUrl = $storeUrl;
        $this->invoicePrefix = $invoicePrefix;
        $this->customer = $customer;
        $this->billingAddress = $billingAddress;
        $this->shippingAddress = $shippingAddress;
        $this->paymentMethod = $paymentMethod;
        $this->shippingMethod = $shippingMethod;
        $this->products = $products;
        $this->totals = $totals;
        $this->orderTotal = $orderTotal;
        $this->languageId = $languageId;
        $this->languageCode = $languageCode;
        $this->currencyId = $currencyId;
        $this->currencyCode = $currencyCode;
        $this->currencyValue = $currencyValue;
        $this->comment = $comment;
        $this->ip = $ip;
        $this->forwardedIp = $forwardedIp;
        $this->userAgent = $userAgent;
        $this->acceptLanguage = $acceptLanguage;
    }
}
