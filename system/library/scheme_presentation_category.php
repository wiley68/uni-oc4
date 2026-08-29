<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Presentation-only scheme category. Does not change AvailableScheme::type or identity.
 *
 * Canonical list order (Product / Cart / Checkout) — see SchemePresentationOrder:
 * 1. months ASC
 * 2. business type rank — standard(0) BEFORE promo-like(1)
 * 3. presentation category rank — standard → nonzero_promo → zero_promo
 * 4. filterId ASC, kopCode, type, scheme key (stable)
 */
final class SchemePresentationCategory
{
    public const STANDARD = 'standard';

    public const NONZERO_PROMO = 'nonzero_promo';

    public const ZERO_PROMO = 'zero_promo';

    /** @param array<string, mixed> $shop */
    public static function classify(AvailableScheme $scheme, array $shop): string
    {
        $zeroInterest = self::isZeroInterest($scheme);
        $inPromoFlow = $scheme->type === 'promo'
            || (is_array($scheme->filter) && (int) ($scheme->filter['uni_promo'] ?? 0) === 1);

        if ($inPromoFlow && $zeroInterest) {
            return self::ZERO_PROMO;
        }

        $defaultKop = self::defaultKop($shop);
        if ($defaultKop !== '' && $scheme->kopCode === $defaultKop) {
            return self::STANDARD;
        }

        if ($defaultKop !== '' && $scheme->kopCode !== $defaultKop && !$zeroInterest) {
            return self::NONZERO_PROMO;
        }

        // No reliable baseline: promotional description marks overlay schemes.
        if ($defaultKop === '' && self::hasPromotionalDescription($scheme, $shop) && !$zeroInterest) {
            return self::NONZERO_PROMO;
        }

        return self::STANDARD;
    }

    public static function rank(string $category): int
    {
        return match ($category) {
            self::STANDARD => 0,
            self::NONZERO_PROMO => 1,
            self::ZERO_PROMO => 2,
            default => 99,
        };
    }

    /**
     * Explicit business bucket for AvailableScheme::type: standard=0, promo=1.
     * Overlay schemes that remain type=standard are ordered via presentation rank.
     *
     * @param array<string, mixed> $shop unused; kept for call-site uniformity
     */
    public static function typeRank(AvailableScheme $scheme, array $shop = []): int
    {
        unset($shop);

        return $scheme->type === 'promo' ? 1 : 0;
    }

    /** @param array<string, mixed> $shop */
    public static function compare(AvailableScheme $left, AvailableScheme $right, array $shop): int
    {
        if ($left->months !== $right->months) {
            return $left->months <=> $right->months;
        }

        $leftType = self::typeRank($left, $shop);
        $rightType = self::typeRank($right, $shop);
        if ($leftType !== $rightType) {
            return $leftType <=> $rightType;
        }

        $leftRank = self::rank(self::classify($left, $shop));
        $rightRank = self::rank(self::classify($right, $shop));
        if ($leftRank !== $rightRank) {
            return $leftRank <=> $rightRank;
        }

        if ($left->filterId !== $right->filterId) {
            return $left->filterId <=> $right->filterId;
        }

        $kop = strcmp($left->kopCode, $right->kopCode);
        if ($kop !== 0) {
            return $kop;
        }

        $type = strcmp($left->type, $right->type);
        if ($type !== 0) {
            return $type;
        }

        return strcmp(ProductSchemeList::key($left), ProductSchemeList::key($right));
    }

    /**
     * @param AvailableScheme[] $schemes
     * @param array<string, mixed> $shop
     *
     * @return AvailableScheme[]
     */
    public static function sort(array $schemes, array $shop): array
    {
        $sorted = array_values($schemes);
        usort(
            $sorted,
            static fn(AvailableScheme $left, AvailableScheme $right): int => self::compare($left, $right, $shop)
        );

        return $sorted;
    }

    public static function isZeroInterest(AvailableScheme $scheme): bool
    {
        return array_key_exists('interestPercent', $scheme->coefficient)
            && abs((float) $scheme->coefficient['interestPercent']) <= 0.00001;
    }

    public static function presentationLabel(AvailableScheme $scheme, array $shop): string
    {
        return $scheme->months . ':' . self::classify($scheme, $shop);
    }

    /** @param array<string, mixed> $shop */
    public static function defaultKop(array $shop): string
    {
        $byDefault = is_array($shop['kop']['by_default'] ?? null) ? $shop['kop']['by_default'] : [];
        $configured = trim((string) ($byDefault['uni_kop_default'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        return self::inferBaselineKop($shop);
    }

    /**
     * Schema-mode shops often leave kop.by_default.uni_kop_default empty.
     * Prefer the broadest non-promo filter as the baseline KOP (PS parity intent).
     *
     * @param array<string, mixed> $shop
     */
    private static function inferBaselineKop(array $shop): string
    {
        $filters = $shop['kop']['by_schema']['filters'] ?? [];
        if (!is_array($filters)) {
            return '';
        }

        $bestKop = '';
        $bestScore = -1;
        $bestFilterId = PHP_INT_MAX;
        foreach ($filters as $filter) {
            if (!is_array($filter) || (int) ($filter['uni_promo'] ?? 0) === 1) {
                continue;
            }
            $kop = trim((string) ($filter['uni_kop'] ?? ''));
            if ($kop === '') {
                continue;
            }
            $score = 0;
            if (self::isBlankFilterScope($filter['product_id'] ?? null)) {
                $score += 4;
            }
            if (self::isBlankFilterScope($filter['category_id'] ?? null)) {
                $score += 4;
            }
            if (self::isBlankFilterScope($filter['uni_meseci'] ?? null)) {
                $score += 4;
            }
            $filterId = (int) ($filter['id'] ?? 0);
            if ($score > $bestScore || ($score === $bestScore && $filterId < $bestFilterId)) {
                $bestScore = $score;
                $bestFilterId = $filterId;
                $bestKop = $kop;
            }
        }

        return $bestKop;
    }

    private static function isBlankFilterScope(mixed $value): bool
    {
        return $value === null || $value === '' || $value === false;
    }

    /** @param array<string, mixed> $shop */
    private static function hasPromotionalDescription(AvailableScheme $scheme, array $shop): bool
    {
        return ProductSchemeList::description($shop, $scheme) !== '';
    }
}
