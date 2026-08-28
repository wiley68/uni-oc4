<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Common immutable validated submission boundary for Product, Cart and Checkout.
 */
final class ValidatedFinancingSubmission
{
    public string $entryPoint;

    public int $storeId;

    public ?string $submissionToken;

    public string $operationKeyHash;

    public ?int $cartId;

    public ?int $existingOrderId;

    public FinancingCustomerData $customer;

    public FinancingAddressData $billingAddress;

    public ?FinancingAddressData $shippingAddress;

    public CalculationResult $financingCalculation;

    public OrderDraft $orderDraft;

    public string $selectionHash;

    public string $cartFingerprint;

    public string $shopUnicid;

    public string $shopSnapshotFetchedAt;

    public string $submissionSource;

    public function __construct(
        string $entryPoint,
        int $storeId,
        ?string $submissionToken,
        string $operationKeyHash,
        ?int $cartId,
        ?int $existingOrderId,
        FinancingCustomerData $customer,
        FinancingAddressData $billingAddress,
        ?FinancingAddressData $shippingAddress,
        CalculationResult $financingCalculation,
        OrderDraft $orderDraft,
        string $selectionHash,
        string $cartFingerprint,
        string $shopUnicid,
        string $shopSnapshotFetchedAt,
        string $submissionSource
    ) {
        if (!OperationEntryPoint::isValid($entryPoint)) {
            throw new PersistenceValidationException('Unsupported financing submission entry point.');
        }

        $this->entryPoint = $entryPoint;
        $this->storeId = $storeId;
        $this->submissionToken = $submissionToken;
        $this->operationKeyHash = $operationKeyHash;
        $this->cartId = $cartId;
        $this->existingOrderId = $existingOrderId;
        $this->customer = $customer;
        $this->billingAddress = $billingAddress;
        $this->shippingAddress = $shippingAddress;
        $this->financingCalculation = $financingCalculation;
        $this->orderDraft = $orderDraft;
        $this->selectionHash = $selectionHash;
        $this->cartFingerprint = $cartFingerprint;
        $this->shopUnicid = $shopUnicid;
        $this->shopSnapshotFetchedAt = $shopSnapshotFetchedAt;
        $this->submissionSource = $submissionSource;
    }
}
