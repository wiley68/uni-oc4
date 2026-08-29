<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Presentation-only scheme category. Does not change AvailableScheme::type or identity.
 *
 * Canonical list order (Product / Cart / Checkout):
 * 1. months ASC
 * 2. AvailableScheme::type — standard BEFORE promo
 * 3. within same type: presentation rank standard → nonzero_promo → zero_promo
 * 4. filterId ASC, kopCode, type (stable)
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

    /** standard=0, promo=1 — equal-month secondary key. */
    public static function typeRank(AvailableScheme $scheme): int
    {
        return $scheme->type === 'promo' ? 1 : 0;
    }

    /** @param array<string, mixed> $shop */
    public static function compare(AvailableScheme $left, AvailableScheme $right, array $shop): int
    {
        if ($left->months !== $right->months) {
            return $left->months <=> $right->months;
        }

        $leftType = self::typeRank($left);
        $rightType = self::typeRank($right);
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

        return strcmp($left->type, $right->type);
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
    private static function defaultKop(array $shop): string
    {
        $byDefault = is_array($shop['kop']['by_default'] ?? null) ? $shop['kop']['by_default'] : [];

        return trim((string) ($byDefault['uni_kop_default'] ?? ''));
    }
}
