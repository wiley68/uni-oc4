<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class CartCalculatorPresenter
{
    public function __construct(
        private CartSchemeResolver $resolver,
        private Calculator $calculator,
        private CurrencyGate $currencyGate,
        private InstallmentLabelFormatter $labels
    ) {
    }

    /**
     * @param array<string, mixed> $shop
     * @return array<string, mixed>|null
     */
    public function present(array $shop, CartContext $cart, string $currencyIso): ?array
    {
        if (!$this->currencyGate->supports($shop, $currencyIso) || $cart->lines === []) {
            return null;
        }
        if (!$this->calculator->isAvailableForAmount($shop, $cart->total)) {
            return null;
        }

        $resolution = $this->resolver->resolve($shop, $cart);
        $offers = [];
        foreach (['standard' => $resolution->standardOffer, 'promo' => $resolution->promoOffer] as $type => $preferred) {
            if ($preferred === null) {
                continue;
            }
            $schemes = [];
            // Standard offer dropdown is the unified list (Product parity): months ASC, standard before promo.
            $pool = $type === 'promo'
                ? $resolution->promoSchemes
                : $this->resolver->unifiedSchemes($resolution, $shop);
            $seen = [];
            foreach (SchemePresentationOrder::sort($pool, $shop) as $scheme) {
                if ($scheme->firstInstallmentAmbiguous) {
                    continue;
                }
                $key = ProductSchemeList::key($scheme);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                try {
                    $result = $this->calculator->calculateScheme($shop, $cart->total, $scheme, 0.0);
                } catch (UnavailableSchemeException) {
                    continue;
                }
                $schemes[] = [
                    'key'                      => $key,
                    'scheme_type'              => $scheme->type,
                    'months'                   => $scheme->months,
                    'filter_id'                => $scheme->filterId,
                    'kop_code'                 => $scheme->kopCode,
                    'description'              => ProductSchemeList::description($shop, $scheme),
                    'first_installment'        => $result->firstInstallment->amount,
                    'first_installment_locked' => $result->firstInstallment->locked,
                    'financed_amount'          => $result->financedAmount,
                    'monthly_installment'      => $result->monthlyInstallment,
                    'total_due'                => $result->totalPayable,
                    'glp'                      => $result->glp,
                    'gpr'                      => $result->gpr,
                    'zero_interest_promo'      => SchemePresentationCategory::isZeroInterest($scheme),
                ];
            }
            if ($schemes === []) {
                continue;
            }
            $schemes = SchemePresentationOrder::sortPresentedRows($schemes, $shop);
            $offers[$type] = [
                'type'                 => $type,
                'months'               => $preferred->months,
                'preferred_scheme_key' => ProductSchemeList::keyFromParts(
                    $preferred->type,
                    $preferred->kopCode,
                    $preferred->months,
                    $preferred->filterId
                ),
                'monthly_installment'  => $preferred->monthlyInstallment,
                'installment_label'    => $this->labels->format(
                    $preferred->months,
                    $preferred->monthlyInstallment,
                    (int) ($shop['uni_eur'] ?? 0)
                ),
                'schemes'              => $schemes,
            ];
        }

        if ($offers === []) {
            return null;
        }

        return [
            'product_id'             => 0,
            'price'                  => $cart->total,
            'currency_iso'           => strtoupper($currencyIso),
            'cart_fingerprint'       => CartFingerprint::hash($cart, $currencyIso),
            'show_installment'       => $this->flag($shop['uni_vnoska'] ?? 0),
            'button_type'            => $this->flag($shop['uni_vnoska'] ?? 0) ? 'standard' : 'image',
            'show_first_installment' => $this->flag($shop['uni_first_vnoska'] ?? 0),
            'dark_button'            => $this->flag($shop['uni_type_button'] ?? 0),
            'design'                 => $this->flag($shop['uni_type_button'] ?? 0) ? 'alternative' : 'standard',
            'buttons_in_row'         => (int) ($shop['uni_button_row'] ?? 1) === 1,
            'button_width'           => $this->dimension($shop['uni_button_width'] ?? 290, 290, 100, 600),
            'button_height'          => $this->dimension($shop['uni_button_height'] ?? 56, 56, 30, 120),
            'heading'                => trim((string) ($shop['uni_zaglavie'] ?? '')),
            'offers'                 => $offers,
            'hide_secondary'         => true,
            'source'                 => 'cart',
        ];
    }

    private function flag(mixed $value): bool
    {
        return (int) $value === 1;
    }

    private function dimension(mixed $value, int $default, int $min, int $max): int
    {
        $n = (int) $value;

        return max($min, min($max, $n > 0 ? $n : $default));
    }
}
