<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class CartSchemeResolver
{
    private Calculator $calculator;

    public function __construct(Calculator $calculator)
    {
        $this->calculator = $calculator;
    }

    /** @param array<string, mixed> $shop */
    public function resolve(array $shop, CartContext $cart): CartResolution
    {
        if ($cart->lines === [] || !$this->calculator->isAvailableForAmount($shop, $cart->total)) {
            return new CartResolution([], [], null, null);
        }

        $standardSets = [];
        $promoSets = [];
        foreach ($cart->lines as $line) {
            $product = clone $line->product;
            $product->price = $cart->total;
            $standard = $this->calculator->availableSchemes($shop, $product, 'standard');
            $promo = $this->calculator->availableSchemes($shop, $product, 'promo');
            if ((int) ($shop['uni_typekop'] ?? -1) === 1) {
                $standard = array_merge($standard, $promo);
            }
            $standardSets[] = $standard;
            $promoSets[] = $promo;
        }

        $standard = $this->intersect($standardSets, $shop);
        $promo = $this->intersect($promoSets, $shop);
        $preferredMonths = (int) ($shop['uni_shema_current'] ?? 0);

        return new CartResolution(
            $standard,
            $promo,
            $this->preferred($standard, $shop, $cart->total, 'standard', $preferredMonths, $standardSets),
            $this->preferred($promo, $shop, $cart->total, 'promo', $preferredMonths, $promoSets)
        );
    }

    /**
     * @param array<string, mixed> $shop
     *
     * @return AvailableScheme[]
     */
    public function unifiedSchemes(CartResolution $resolution, array $shop = []): array
    {
        $schemes = [];
        $seen = [];
        foreach ($resolution->standardSchemes as $scheme) {
            if ($scheme->firstInstallmentAmbiguous) {
                continue;
            }
            $schemes[] = $scheme;
            $seen[$this->key($scheme)] = true;
        }
        foreach ($resolution->promoSchemes as $scheme) {
            if ($scheme->firstInstallmentAmbiguous || isset($seen[$this->key($scheme)])) {
                continue;
            }
            $schemes[] = $scheme;
            $seen[$this->key($scheme)] = true;
        }

        return SchemePresentationOrder::sort($schemes, $shop);
    }

    /**
     * @param array<int, AvailableScheme[]> $sets
     * @param array<string, mixed> $shop
     *
     * @return AvailableScheme[]
     */
    public function intersect(array $sets, array $shop = []): array
    {
        if ($sets === []) {
            return [];
        }
        $common = array_values($sets[0]);
        foreach ($sets as $set) {
            $keys = [];
            foreach ($set as $scheme) {
                $keys[$this->key($scheme)] = true;
            }
            $common = array_values(array_filter(
                $common,
                static fn(AvailableScheme $scheme): bool => isset($keys[$scheme->identityKey()])
            ));
            if ($common === []) {
                return [];
            }
        }

        $existing = [];
        foreach ($common as $scheme) {
            $existing[$this->key($scheme)] = true;
        }
        foreach ($this->groups($sets) as $group) {
            $lineMonthSets = [];
            foreach ($sets as $set) {
                $months = [];
                foreach ($set as $scheme) {
                    if ($scheme->type === $group['type'] && $scheme->kopCode === $group['kop']) {
                        $months[] = $scheme->months;
                    }
                }
                if ($months === []) {
                    continue 2;
                }
                $lineMonthSets[] = $months;
            }
            $lineLcms = array_map([$this, 'lcm'], $lineMonthSets);
            $target = $this->lcm($lineLcms);
            $key = $group['type'] . '|' . $group['kop'] . '|' . $target;
            if ($target <= 0 || isset($existing[$key])) {
                continue;
            }
            $template = null;
            foreach ($sets as $set) {
                $found = null;
                foreach ($set as $scheme) {
                    if ($this->key($scheme) === $key) {
                        $found = $scheme;
                        break;
                    }
                }
                if ($found === null) {
                    continue 2;
                }
                $template = $found;
            }
            if ($template !== null) {
                $common[] = $template;
                $existing[$key] = true;
            }
        }

        $common = array_map(
            fn(AvailableScheme $scheme): AvailableScheme => $this->reconcileCommonScheme($scheme, $sets),
            $common
        );

        return SchemePresentationOrder::sort($common, $shop);
    }

    /**
     * @param array<int, AvailableScheme[]> $sets
     */
    private function reconcileCommonScheme(AvailableScheme $seed, array $sets): AvailableScheme
    {
        $contributors = [];
        $key = $this->key($seed);
        foreach ($sets as $set) {
            foreach ($set as $candidate) {
                if ($this->key($candidate) === $key) {
                    $contributors[] = $candidate;
                }
            }
        }
        if ($contributors === []) {
            return $seed;
        }

        $policies = [];
        foreach ($contributors as $candidate) {
            $policies[] = is_array($candidate->filter) && (int) ($candidate->filter['uni_parva'] ?? 0) === 1 ? 1 : 0;
        }
        if (count(array_values(array_unique($policies))) > 1) {
            return new AvailableScheme(
                $seed->type,
                $seed->kopCode,
                $seed->months,
                0,
                null,
                $seed->coefficient,
                true
            );
        }

        usort($contributors, static fn(AvailableScheme $left, AvailableScheme $right): int => $left->filterId <=> $right->filterId);
        $chosen = $contributors[0];

        return new AvailableScheme(
            $chosen->type,
            $chosen->kopCode,
            $chosen->months,
            $chosen->filterId,
            $chosen->filter,
            $chosen->coefficient,
            false
        );
    }

    /**
     * @param AvailableScheme[] $schemes
     * @param array<string, mixed> $shop
     * @param array<int, AvailableScheme[]> $lineSets
     */
    private function preferred(
        array $schemes,
        array $shop,
        float $total,
        string $buttonType,
        int $preferredMonths,
        array $lineSets
    ): ?Offer {
        $offers = [];
        foreach ($schemes as $scheme) {
            if ($buttonType === 'promo' && abs((float) ($scheme->coefficient['interestPercent'] ?? -1)) > 0.00001) {
                continue;
            }
            if (
                $buttonType === 'standard'
                && SchemePresentationCategory::classify($scheme, $shop) === SchemePresentationCategory::ZERO_PROMO
            ) {
                continue;
            }
            if ($scheme->firstInstallmentAmbiguous || $this->hasConflictingAutomaticFirstInstallment($scheme, $lineSets)) {
                continue;
            }
            try {
                $calculation = $this->calculator->calculateScheme($shop, $total, $scheme);
            } catch (UnavailableSchemeException) {
                continue;
            }
            $offer = $this->calculator->createButtonOffer($scheme, $calculation->financedAmount, $buttonType);
            if ($offer !== null) {
                $offers[] = $offer;
            }
        }

        return $this->calculator->selectPreferredOffer($offers, $preferredMonths);
    }

    /**
     * @param array<int, AvailableScheme[]> $lineSets
     */
    private function hasConflictingAutomaticFirstInstallment(AvailableScheme $scheme, array $lineSets): bool
    {
        if ($lineSets === []) {
            return false;
        }

        $policies = [];
        $key = $this->key($scheme);
        foreach ($lineSets as $set) {
            $linePolicies = [];
            foreach ($set as $candidate) {
                if ($this->key($candidate) !== $key) {
                    continue;
                }
                $linePolicies[] = is_array($candidate->filter) && (int) ($candidate->filter['uni_parva'] ?? 0) === 1 ? 1 : 0;
            }
            if ($linePolicies === []) {
                continue;
            }
            $unique = array_values(array_unique($linePolicies));
            if (count($unique) !== 1) {
                return true;
            }
            $policies[] = $unique[0];
        }

        return count(array_unique($policies)) > 1;
    }

    private function key(AvailableScheme $scheme): string
    {
        return $scheme->identityKey();
    }

    /** @param array<int, AvailableScheme[]> $sets @return array<int, array{type:string,kop:string}> */
    private function groups(array $sets): array
    {
        $groups = [];
        foreach ($sets as $set) {
            foreach ($set as $scheme) {
                $groupKey = $scheme->type . '|' . $scheme->kopCode;
                $groups[$groupKey] = ['type' => $scheme->type, 'kop' => $scheme->kopCode];
            }
        }

        return array_values($groups);
    }

    /** @param int[] $values */
    public function lcm(array $values): int
    {
        $values = array_values(array_filter(array_map('abs', $values)));
        if ($values === []) {
            return 0;
        }
        $result = (int) $values[0];
        foreach (array_slice($values, 1) as $value) {
            $result = (int) (($result / $this->gcd($result, (int) $value)) * $value);
        }

        return $result;
    }

    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            $temp = $b;
            $b = $a % $b;
            $a = $temp;
        }

        return max(1, abs($a));
    }
}
