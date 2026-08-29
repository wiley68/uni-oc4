<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Checkout financing validation against native session.order_id (Phase 9 boundary).
 * Does not call addOrder — OrderMaterializationService routes to CheckoutExistingOrderGateway.
 */
final class CheckoutFinancingSubmissionService
{
    public function __construct(
        private FinancingAttemptRepository $attempts,
        private OperationLockRepository $locks,
        private OrderMaterializationService $materialization,
        private CartSchemeCalculator $schemeCalculator,
        private Calculator $calculator,
        private CartSchemeResolver $resolver,
        private ProductCustomerValidator $customerValidator,
        private ProductAddressValidator $addressValidator,
        private ProductAddressCatalogPort $addressCatalog,
        private ConsentResolver $consents,
        private CartOrderDraftFactory $draftFactory,
        private PersistenceClock $clock,
        private ProductPopupFormNormalizer $popupFormNormalizer = new ProductPopupFormNormalizer()
    ) {
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $posted
     * @param list<array<string, mixed>> $orderProducts
     * @param list<array<string, mixed>> $orderTotals
     */
    public function submit(
        array $shop,
        int $storeId,
        string $submissionToken,
        string $actorBindingHash,
        string $sessionFingerprint,
        int $customerId,
        int $customerGroupId,
        CartContext $cart,
        string $expectedFingerprint,
        string $currencyCode,
        string $popupType,
        string $schemeType,
        string $kopCode,
        int $months,
        int $filterId,
        string $schemeKey,
        float $firstInstallment,
        array $posted,
        array $orderProducts,
        array $orderTotals,
        float $orderTotal,
        bool $shippingRequired,
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
        int $existingOrderId,
        string $ip = '127.0.0.1',
        array $storeAddressDefaults = []
    ): ProductFinancingResult {
        if ($existingOrderId <= 0) {
            throw new ProductFinancingFlowException(
                'checkout_order_missing',
                'Поръчката от касата липсва. Моля, започнете отново от плащането.'
            );
        }
        if ($submissionToken === '' || !SubmissionTokenGenerator::isValidFormat($submissionToken)) {
            throw new ProductFinancingFlowException('validation', 'Невалиден token за заявката.');
        }

        $posted = $this->popupFormNormalizer->normalize($posted, $storeAddressDefaults);
        $attemptRow = $this->attempts->findByToken($storeId, $submissionToken);
        if ($attemptRow === null) {
            throw new ProductFinancingFlowException('validation', 'Невалиден token за заявката.');
        }
        if ((string) ($attemptRow['entry_point'] ?? '') !== OperationEntryPoint::CHECKOUT) {
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

        if ($cart->lines === [] || $cart->total <= 0.0) {
            throw new ProductFinancingFlowException(
                'checkout_order_changed',
                'Поръчката или съдържанието на касата е променено. Моля, презаредете плащането.'
            );
        }

        $fingerprint = CartFingerprint::hash($cart, $currencyCode);
        if (!hash_equals($expectedFingerprint, $fingerprint)
            || (!empty($attemptRow['cart_fingerprint']) && !hash_equals((string) $attemptRow['cart_fingerprint'], $fingerprint))
        ) {
            throw new ProductFinancingFlowException(
                'checkout_order_changed',
                'Поръчката или съдържанието на касата е променено. Моля, презаредете плащането.'
            );
        }

        $selectionHash = CheckoutSelectionHash::hash(
            $storeId,
            $existingOrderId,
            $fingerprint,
            $currencyCode,
            $orderTotal,
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
            $cart,
            $currencyCode,
            $popupType,
            $schemeType,
            $kopCode,
            $months,
            $filterId,
            $schemeKey,
            $firstInstallment
        );

        $resolution = $this->resolver->resolve($shop, $cart);
        $pool = $this->resolver->unifiedSchemes($resolution, $shop);
        $scheme = null;
        foreach ($pool as $candidate) {
            if (hash_equals(ProductSchemeList::key($candidate), $schemeKey)) {
                $scheme = $candidate;
                break;
            }
        }
        if ($scheme === null) {
            throw new ProductFinancingFlowException('unavailable_scheme', 'Избраната схема вече не е налична.');
        }
        $calculation = $this->calculator->calculateScheme($shop, $cart->total, $scheme, $firstInstallment);

        try {
            $validatedCustomer = $this->customerValidator->validate($posted, $customerGroupId, $customerId);
        } catch (ProductFinancingFlowException $exception) {
            throw new ProductFinancingFlowException(
                'invalid_customer',
                $exception->getMessage(),
                $exception->fieldErrors(),
                $exception
            );
        }

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
        $shippingAddress = $shippingRequired
            ? $this->addressCatalog->resolveShippingAddress(
                true,
                $billingAddress,
                $addressInput,
                $shippingAddressId,
                $customerId
            )
            : null;
        $shippingMethod = ['name' => '', 'code' => ''];
        if ($shippingRequired) {
            $shippingMethod = [
                'name' => (string) ($posted['shipping_method_name'] ?? 'Shipping'),
                'code' => (string) ($posted['shipping_method_code'] ?? ''),
            ];
        }

        try {
            $this->consents->validate($shop, $posted['consent'] ?? []);
        } catch (ProductFinancingFlowException $exception) {
            throw new ProductFinancingFlowException(
                'invalid_consent',
                $exception->getMessage(),
                $exception->fieldErrors(),
                $exception
            );
        }

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

        $draft = $this->draftFactory->create(
            $storeId,
            $storeName,
            $storeUrl,
            $invoicePrefix,
            $validatedCustomer['customer'],
            $billingAddress,
            $shippingAddress ?? $billingAddress,
            $orderProducts,
            $orderTotals,
            $orderTotal,
            $languageId,
            $languageCode,
            $currencyId,
            $currencyCode,
            $currencyValue,
            $shippingMethod,
            $ip
        );

        $submission = new ValidatedFinancingSubmission(
            OperationEntryPoint::CHECKOUT,
            $storeId,
            $submissionToken,
            (string) $attemptRow['operation_key_hash'],
            null,
            $existingOrderId,
            $validatedCustomer['customer'],
            $billingAddress,
            $shippingAddress,
            $calculation,
            $draft,
            $selectionHash,
            $fingerprint,
            $shopUnicid,
            $shopSnapshotFetchedAt,
            'checkout_payment'
        );

        $attemptContext = new FinancingAttemptContext($this->attempts->findById((int) $attemptRow['attempt_id']) ?? $attemptRow);
        try {
            $created = $this->materialization->materializeAndBind($submission, $attemptContext, $lockOwnerToken);
        } catch (OrderMaterializationException $exception) {
            throw new ProductFinancingFlowException(
                'technical_failure',
                'Поръчката не може да бъде потвърдена. Моля, опитайте отново.',
                [],
                $exception
            );
        }

        return new ProductFinancingResult(
            true,
            'local_order_prepared',
            $created->orderId,
            'Локалната поръчка е подготвена успешно. Следващата стъпка ще бъде финансирането.'
        );
    }

    /** @param array<string, mixed> $row */
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
