<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Whether UniCredit may appear as a checkout payment method. */
final class CheckoutFinancingEligibility
{
    public function __construct(
        private Calculator $calculator,
        private CartSchemeResolver $resolver,
        private CurrencyGate $currencyGate
    ) {
    }

    /**
     * @param array<string, mixed> $shop
     */
    public function isEligible(
        array $shop,
        CartContext $cart,
        string $currencyCode,
        bool $moduleEnabled,
        bool $paymentEnabled
    ): bool {
        if (!$moduleEnabled || !$paymentEnabled) {
            return false;
        }
        if (!$this->currencyGate->supports($shop, $currencyCode)) {
            return false;
        }
        if ($cart->lines === [] || $cart->total <= 0.0) {
            return false;
        }
        if (!$this->calculator->isAvailableForAmount($shop, $cart->total)) {
            return false;
        }

        $resolution = $this->resolver->resolve($shop, $cart);

        return $resolution->standardOffer !== null || $resolution->promoOffer !== null;
    }
}
