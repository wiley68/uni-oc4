<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Authoritative cart-scheme calculation against CartSchemeResolver intersection. */
final class CartSchemeCalculator
{
    public function __construct(
        private Calculator $calculator,
        private CartSchemeResolver $resolver,
        private CurrencyGate $currencyGate,
        private AmountDisplayFormatter $amounts
    ) {
    }

    /**
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    public function calculate(
        array $shop,
        CartContext $cart,
        string $currencyIso,
        string $popupType,
        string $schemeType,
        string $kopCode,
        int $months,
        int $filterId,
        string $schemeKey,
        float $firstInstallment
    ): array {
        $allowedTypes = $popupType === 'standard' ? ['standard', 'promo'] : ($popupType === 'promo' ? ['promo'] : []);
        if (!$this->currencyGate->supports($shop, $currencyIso) || !in_array($schemeType, $allowedTypes, true)) {
            throw new UnavailableSchemeException('The selected financing scheme is unavailable.');
        }

        $resolution = $this->resolver->resolve($shop, $cart);
        $pool = $popupType === 'promo' ? $resolution->promoSchemes : $resolution->standardSchemes;
        if ($popupType === 'standard') {
            $pool = $this->resolver->unifiedSchemes($resolution, $shop);
        }
        $scheme = null;
        foreach ($pool as $candidate) {
            if (hash_equals(ProductSchemeList::key($candidate), $schemeKey)
                && $candidate->type === $schemeType
                && $candidate->kopCode === $kopCode
                && $candidate->months === $months
                && $candidate->filterId === $filterId
            ) {
                $scheme = $candidate;
                break;
            }
        }
        if ($scheme === null) {
            throw new UnavailableSchemeException('The selected financing scheme is unavailable.');
        }

        $result = $this->calculator->calculateScheme($shop, $cart->total, $scheme, $firstInstallment);

        return [
            'scheme_key'                  => ProductSchemeList::key($scheme),
            'scheme_type'                 => $scheme->type,
            'kop_code'                    => $scheme->kopCode,
            'months'                      => $scheme->months,
            'filter_id'                   => $scheme->filterId,
            'price'                       => $result->price,
            'first_installment'           => $result->firstInstallment->amount,
            'first_installment_locked'    => $result->firstInstallment->locked,
            'show_first_installment'      => $result->firstInstallment->visible,
            'financed_amount'             => $result->financedAmount,
            'monthly_installment'         => $result->monthlyInstallment,
            'total_payable'               => $result->totalPayable,
            'glp'                         => $result->glp,
            'gpr'                         => $result->gpr,
            'price_display'               => $this->amounts->format($result->price, $shop),
            'financed_amount_display'     => $this->amounts->format($result->financedAmount, $shop),
            'monthly_installment_display' => $this->amounts->format($result->monthlyInstallment, $shop),
            'total_payable_display'       => $this->amounts->format($result->totalPayable, $shop),
            'glp_display'                 => number_format(abs($result->glp), 2, '.', ''),
            'gpr_display'                 => number_format(abs($result->gpr), 2, '.', ''),
            'zero_interest_promo'         => SchemePresentationCategory::isZeroInterest($scheme),
            'cart_fingerprint'            => CartFingerprint::hash($cart, $currencyIso),
        ];
    }
}
