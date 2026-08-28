<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Final product financing validation and local order materialization (Phase 7 boundary).
 */
final class ProductFinancingSubmissionService
{
    public function __construct(
        private FinancingAttemptRepository $attempts,
        private OperationLockRepository $locks,
        private OrderMaterializationService $materialization,
        private OpenCartProductContextFactory $productFactory,
        private ProductSchemeCalculator $schemeCalculator,
        private ProductCustomerValidator $customerValidator,
        private ProductAddressValidator $addressValidator,
        private ProductAddressCatalogPort $addressCatalog,
        private ConsentResolver $consents,
        private OpenCartProductOrderDraftBuilder $draftBuilder,
        private PersistenceClock $clock,
        private Calculator $calculator
    ) {
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $posted
     * @param array<int, int|string|list<int>> $requestedOptions
     */
    public function submit(
        array $shop,
        int $storeId,
        string $submissionToken,
        string $actorBindingHash,
        string $sessionFingerprint,
        int $customerId,
        int $customerGroupId,
        int $productId,
        int $quantity,
        array $requestedOptions,
        string $currencyCode,
        string $popupType,
        string $schemeType,
        string $kopCode,
        int $months,
        int $filterId,
        string $schemeKey,
        float $firstInstallment,
        array $posted,
        string $shopUnicid,
        string $shopSnapshotFetchedAt,
        int $languageId,
        string $languageCode,
        int $currencyId,
        float $currencyValue,
        string $storeName,
        string $storeUrl,
        string $invoicePrefix,
        string $lockOwnerToken,
        string $ip = '127.0.0.1'
    ): ProductFinancingResult {
        $attemptRow = $this->attempts->findByToken($storeId, $submissionToken);
        if ($attemptRow === null) {
            throw new ProductFinancingFlowException('validation', 'Невалиден token за заявката.');
        }
        if ((string) ($attemptRow['entry_point'] ?? '') !== OperationEntryPoint::PRODUCT) {
            throw new ProductFinancingFlowException('validation', 'Невалиден token за заявката.');
        }
        if (!hash_equals((string) ($attemptRow['actor_binding_hash'] ?? ''), $actorBindingHash)) {
            throw new ProductFinancingFlowException('attempt_conflict', 'Заявката не принадлежи на текущата сесия.');
        }
        if ($this->isExpired($attemptRow)) {
            throw new ProductFinancingFlowException('expired_attempt', 'Token за заявката е изтекъл. Моля, започнете отначало.');
        }

        $boundOrderId = isset($attemptRow['order_id']) ? (int) $attemptRow['order_id'] : 0;
        if ($boundOrderId > 0) {
            return new ProductFinancingResult(
                true,
                'local_order_prepared',
                $boundOrderId,
                'Локалната поръчка вече е подготвена. Следващата стъпка ще бъде финансирането.',
                true
            );
        }

        $line = $this->productFactory->create($storeId, $productId, $quantity, $requestedOptions);
        $selectionHash = ProductSelectionHash::hash(
            $storeId,
            $productId,
            $line->normalizedOptions,
            $quantity,
            $currencyCode,
            $line->financingPrice,
            $schemeKey,
            $schemeType,
            $kopCode,
            $months,
            $filterId,
            $firstInstallment,
            $actorBindingHash
        );
        if (!hash_equals((string) ($attemptRow['selection_hash'] ?? ''), $selectionHash)) {
            throw new ProductFinancingFlowException(
                'stale_selection',
                'Избраните условия са променени. Моля, презаредете калкулатора и опитайте отново.'
            );
        }

        $this->schemeCalculator->calculate(
            $shop,
            $line->toProductContext(),
            $currencyCode,
            $popupType,
            $schemeType,
            $kopCode,
            $months,
            $filterId,
            $schemeKey,
            $firstInstallment
        );

        $scheme = ProductSchemeList::find(
            $this->calculator->availableSchemes($shop, $line->toProductContext(), $schemeType),
            $kopCode,
            $months,
            $filterId
        );
        if ($scheme === null) {
            throw new ProductFinancingFlowException('unavailable_scheme', 'Избраната схема вече не е налична.');
        }
        $calculation = $this->calculator->calculateScheme($shop, $line->financingPrice, $scheme, $firstInstallment);

        $validatedCustomer = $this->customerValidator->validate($posted, $customerGroupId, $customerId);
        $addressInput = $this->addressValidator->extractPostedAddress($posted);
        $this->addressValidator->validateRequired($addressInput);
        $postedAddressId = isset($posted['address_id']) ? (int) $posted['address_id'] : null;
        $billingAddress = $this->addressCatalog->resolveBillingAddress(
            $customerId,
            $addressInput,
            $postedAddressId,
            $validatedCustomer['customer']
        );
        $shippingAddressId = isset($posted['shipping_address_id']) ? (int) $posted['shipping_address_id'] : null;
        $shippingAddress = $this->addressCatalog->resolveShippingAddress(
            $line->shippingRequired,
            $billingAddress,
            $addressInput,
            $shippingAddressId,
            $customerId
        );
        $shippingMethod = null;
        if ($line->shippingRequired) {
            $shippingTarget = $shippingAddress ?? $billingAddress;
            $shippingMethod = $this->addressCatalog->resolveShippingMethod($shippingTarget, $line);
            if ($shippingMethod === null) {
                throw new ProductFinancingFlowException(
                    'validation',
                    'Не може да се определи начин на доставка. Моля, коригирайте адреса.'
                );
            }
        }

        $this->consents->validate($shop, $posted['consent'] ?? []);

        if (!$this->attempts->transitionFromStates(
            (int) $attemptRow['attempt_id'],
            [FinancingAttemptState::ISSUED],
            FinancingAttemptState::VALIDATING
        )) {
            $fresh = $this->attempts->findById((int) $attemptRow['attempt_id']);
            if ($fresh !== null && isset($fresh['order_id']) && (int) $fresh['order_id'] > 0) {
                return new ProductFinancingResult(
                    true,
                    'local_order_prepared',
                    (int) $fresh['order_id'],
                    'Локалната поръчка вече е подготвена. Следващата стъпка ще бъде финансирането.',
                    true
                );
            }
            throw new ProductFinancingFlowException('operation_processing', 'Заявката се обработва. Моля, изчакайте.');
        }

        $orderTotal = round($line->unitPriceExTax * $line->quantity + $line->unitTax * $line->quantity, 4);
        $draft = $this->draftBuilder->build(
            $storeId,
            $storeName,
            $storeUrl,
            $invoicePrefix,
            $validatedCustomer['customer'],
            $billingAddress,
            $shippingAddress,
            $shippingMethod,
            $line,
            $orderTotal,
            $languageId,
            $languageCode,
            $currencyId,
            $currencyCode,
            $currencyValue,
            $ip
        );

        $submission = new ValidatedFinancingSubmission(
            OperationEntryPoint::PRODUCT,
            $storeId,
            $submissionToken,
            (string) $attemptRow['operation_key_hash'],
            null,
            null,
            $validatedCustomer['customer'],
            $billingAddress,
            $shippingAddress,
            $calculation,
            $draft,
            $selectionHash,
            hash('sha256', 'product|' . $productId . '|' . $selectionHash),
            $shopUnicid,
            $shopSnapshotFetchedAt,
            'product_modal'
        );

        $attemptContext = new FinancingAttemptContext($this->attempts->findById((int) $attemptRow['attempt_id']) ?? $attemptRow);
        try {
            $created = $this->materialization->materializeAndBind($submission, $attemptContext, $lockOwnerToken);
        } catch (OrderMaterializationException $exception) {
            throw new ProductFinancingFlowException('order_materialization', 'Поръчката не може да бъде създадена. Моля, опитайте отново.', [], $exception);
        }

        return new ProductFinancingResult(
            true,
            'local_order_prepared',
            $created->orderId,
            'Локалната поръчка е подготвена успешно. Следващата стъпка ще бъде финансирането.'
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isExpired(array $row): bool
    {
        $expiresAt = (string) ($row['expires_at'] ?? '');
        if ($expiresAt === '') {
            return false;
        }
        $expires = strtotime($expiresAt . ' UTC');

        return $expires !== false && $this->clock->now() >= $expires;
    }
}
