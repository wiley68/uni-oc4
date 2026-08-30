<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Cart financing: local order materialization + shared CP lifecycle (Phase 10B).
 * Live OpenCart cart is preserved (cart_unchanged) for commerce; CP uses frozen payload.
 */
final class CartFinancingSubmissionService
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
        private ControlPanelOrderLifecycleService $cpLifecycle,
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
        string $ip = '127.0.0.1',
        array $storeAddressDefaults = [],
        ?int $cartId = null
    ): ProductFinancingResult {
        $posted = $this->popupFormNormalizer->normalize($posted, $storeAddressDefaults);
        $attemptRow = $this->attempts->findByToken($storeId, $submissionToken);
        if ($attemptRow === null) {
            throw new ProductFinancingFlowException('validation', 'Невалиден token за заявката.');
        }
        if ((string) ($attemptRow['entry_point'] ?? '') !== OperationEntryPoint::CART) {
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
            $resume = ResumeSubmissionFactory::create(
                OperationEntryPoint::CART,
                $storeId,
                $submissionToken,
                (string) ($attemptRow['operation_key_hash'] ?? ''),
                $boundOrderId
            );

            return FinancingControlPanelCompletion::resumeExistingCp(
                $this->cpLifecycle,
                (int) $attemptRow['attempt_id'],
                $resume,
                $boundOrderId,
                $existingCpId,
                $shop
            );
        }

        if ($cart->lines === [] || $cart->total <= 0.0) {
            throw new ProductFinancingFlowException('cart_empty', 'Количката е празна.');
        }

        $fingerprint = CartFingerprint::hash($cart, $currencyCode);
        if (!hash_equals($expectedFingerprint, $fingerprint)
            || (!empty($attemptRow['cart_fingerprint']) && !hash_equals((string) $attemptRow['cart_fingerprint'], $fingerprint))
        ) {
            throw new ProductFinancingFlowException(
                'cart_changed',
                'Съдържанието на количката е променено. Моля, презаредете калкулатора.'
            );
        }

        $selectionHash = CartSelectionHash::hash(
            $storeId,
            $fingerprint,
            $currencyCode,
            $cart->total,
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
        $shippingAddress = $shippingRequired
            ? $this->addressCatalog->resolveShippingAddress(
                true,
                $billingAddress,
                $addressInput,
                $shippingAddressId,
                $customerId
            )
            : null;
        $shippingMethod = ShippingMethodSnapshot::empty();
        if ($shippingRequired) {
            $shippingTarget = $shippingAddress ?? $billingAddress;
            $probeLine = new OpenCartProductLine(
                0,
                0,
                [],
                'cart',
                '',
                1,
                0,
                0,
                true,
                0.0,
                0.0,
                0.0,
                [],
                []
            );
            $resolvedShipping = $this->addressCatalog->resolveShippingMethod($shippingTarget, $probeLine);
            if ($resolvedShipping === null) {
                throw new ProductFinancingFlowException(
                    'shipping_unavailable',
                    'Няма наличен метод за доставка за посочения адрес.'
                );
            }
            $shippingMethod = ShippingMethodSnapshot::normalize($resolvedShipping);
        }

        $this->consents->validate($shop, $posted['consent'] ?? []);
        $process2Data = ProcessTwoSubmissionSupport::validateIfRequired($shop, $posted, false);

        if (!$this->attempts->transitionFromStates(
            (int) $attemptRow['attempt_id'],
            [
                FinancingAttemptState::ISSUED,
                FinancingAttemptState::VALIDATING,
            ],
            FinancingAttemptState::VALIDATING
        )) {
            $fresh = $this->attempts->findById((int) $attemptRow['attempt_id']);
            if ($fresh !== null
                && isset($fresh['control_panel_order_id'])
                && (int) $fresh['control_panel_order_id'] > 0
                && (string) ($fresh['state'] ?? '') === FinancingAttemptState::CP_CREATED
            ) {
                $resume = ResumeSubmissionFactory::create(
                    OperationEntryPoint::CART,
                    $storeId,
                    $submissionToken,
                    (string) ($fresh['operation_key_hash'] ?? ''),
                    (int) $fresh['order_id']
                );

                return FinancingControlPanelCompletion::resumeExistingCp(
                    $this->cpLifecycle,
                    (int) $fresh['attempt_id'],
                    $resume,
                    (int) $fresh['order_id'],
                    (int) $fresh['control_panel_order_id'],
                    $shop
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
            OperationEntryPoint::CART,
            $storeId,
            $submissionToken,
            (string) $attemptRow['operation_key_hash'],
            $cartId,
            null,
            $validatedCustomer['customer'],
            $billingAddress,
            $shippingAddress,
            $calculation,
            $draft,
            $selectionHash,
            $fingerprint,
            $shopUnicid,
            $shopSnapshotFetchedAt,
            'cart_modal'
        );

        if ($process2Data !== null) {
            ProcessTwoSubmissionSupport::persist(
                $submission,
                (int) $attemptRow['attempt_id'],
                $this->attempts->database(),
                $process2Data
            );
        }

        $attemptContext = new FinancingAttemptContext($this->attempts->findById((int) $attemptRow['attempt_id']) ?? $attemptRow);
        try {
            $created = $this->materialization->materializeAndBind($submission, $attemptContext, $lockOwnerToken);
        } catch (OrderMaterializationException $exception) {
            throw new ProductFinancingFlowException('order_materialization', 'Поръчката не може да бъде създадена. Моля, опитайте отново.', [], $exception);
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
