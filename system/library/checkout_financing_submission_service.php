<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Checkout financing: reuse native session.order_id + shared CP lifecycle (Phase 10B).
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
        private CheckoutCustomerValidator $customerValidator,
        private ProductAddressValidator $addressValidator,
        private ProductAddressCatalogPort $addressCatalog,
        private ConsentResolver $consents,
        private CartOrderDraftFactory $draftFactory,
        private PersistenceClock $clock,
        private ControlPanelOrderLifecycleService $cpLifecycle,
        private CheckoutOrderCustomerAdapter $orderCustomerAdapter = new CheckoutOrderCustomerAdapter(),
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
        array $orderSnapshot = [],
        string $ip = '127.0.0.1',
        array $storeAddressDefaults = [],
        array $sessionCheckout = [],
        ?array $verifiedAddress = null
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
        if ($orderSnapshot === [] || (int) ($orderSnapshot['order_id'] ?? 0) !== $existingOrderId) {
            throw new ProductFinancingFlowException(
                'checkout_order_missing',
                'Поръчката от касата липсва. Моля, започнете отново от плащането.'
            );
        }

        // Customer/address: native Checkout context (order + session). POST customer keys ignored.
        $postedConsents = $this->orderCustomerAdapter->extractPostedConsents($posted);
        $resolved = $this->orderCustomerAdapter->fromCheckoutContext(
            $orderSnapshot,
            $sessionCheckout,
            $verifiedAddress
        );
        if ($resolved['missing'] !== []) {
            $missingMap = [
                'checkout_customer_missing_fields' => implode(',', $resolved['missing']),
            ];
            foreach ($resolved['missing'] as $field) {
                $missingMap[$field] = 'required';
            }
            throw new ProductFinancingFlowException(
                'invalid_customer',
                'Данните на клиента в поръчката са непълни. Моля, върнете се към адресните данни в касата и опитайте отново.',
                $missingMap
            );
        }
        $customerPosted = $this->popupFormNormalizer->normalize($resolved['input'], $storeAddressDefaults);
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
        $existingCpId = isset($attemptRow['control_panel_order_id']) ? (int) $attemptRow['control_panel_order_id'] : 0;
        if ($boundOrderId > 0
            && $existingCpId > 0
            && (string) ($attemptRow['state'] ?? '') === FinancingAttemptState::CP_CREATED
        ) {
            return new ProductFinancingResult(
                true,
                'cp_order_prepared',
                $boundOrderId,
                ControlPanelOrderLifecycleService::CUSTOMER_SUCCESS_MESSAGE,
                true,
                FinancingAttemptState::CP_CREATED,
                $existingCpId
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
            $validatedCustomer = $this->customerValidator->validate($customerPosted, $customerGroupId, $customerId);
        } catch (ProductFinancingFlowException $exception) {
            throw new ProductFinancingFlowException(
                'invalid_customer',
                'Данните на клиента в поръчката са непълни. Моля, върнете се към адресните данни в касата и опитайте отново.',
                $exception->fieldErrors(),
                $exception
            );
        }

        try {
            $addressInput = $this->addressValidator->extractPostedAddress($customerPosted);
            $this->addressValidator->validateRequired($addressInput);
        } catch (ProductFinancingFlowException $exception) {
            throw new ProductFinancingFlowException(
                'invalid_customer',
                'Адресът в поръчката е непълен. Моля, върнете се към адресните данни в касата и опитайте отново.',
                $exception->fieldErrors(),
                $exception
            );
        }

        $billingAddress = $this->orderCustomerAdapter->billingAddressFromResolved(
            $resolved['input'],
            $validatedCustomer['customer']
        );
        $shippingAddress = $shippingRequired
            ? $this->orderCustomerAdapter->shippingAddressFromOrder($orderSnapshot, $billingAddress)
            : null;
        $shippingMethod = $shippingRequired
            ? $this->orderCustomerAdapter->shippingMethodFromOrder($orderSnapshot)
            : ['name' => '', 'code' => ''];

        try {
            $this->consents->validate($shop, $postedConsents);
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
            [
                FinancingAttemptState::ISSUED,
                FinancingAttemptState::VALIDATING,
                FinancingAttemptState::ORDER_CREATING,
            ],
            FinancingAttemptState::VALIDATING
        )) {
            $fresh = $this->attempts->findById((int) $attemptRow['attempt_id']);
            if ($fresh !== null
                && isset($fresh['control_panel_order_id'])
                && (int) $fresh['control_panel_order_id'] > 0
                && (string) ($fresh['state'] ?? '') === FinancingAttemptState::CP_CREATED
            ) {
                return new ProductFinancingResult(
                    true,
                    'cp_order_prepared',
                    (int) $fresh['order_id'],
                    ControlPanelOrderLifecycleService::CUSTOMER_SUCCESS_MESSAGE,
                    true,
                    FinancingAttemptState::CP_CREATED,
                    (int) $fresh['control_panel_order_id']
                );
            }
            if ($fresh === null || !isset($fresh['order_id']) || (int) $fresh['order_id'] <= 0) {
                throw new ProductFinancingFlowException('operation_processing', 'Заявката се обработва. Моля, изчакайте.');
            }
            $attemptRow = $fresh;
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

        $fresh = $this->attempts->findById((int) $attemptRow['attempt_id']) ?? $attemptRow;

        return FinancingControlPanelCompletion::apply(
            $this->cpLifecycle,
            new FinancingAttemptContext($fresh),
            $submission,
            $created->orderId,
            $shop,
            $lockOwnerToken
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
