<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class ProductSchemeList
{
    public function __construct(private Calculator $calculator)
    {
    }

    /**
     * @param array<string, mixed> $shop
     * @return AvailableScheme[]
     */
    public function schemes(array $shop, ProductContext $product, string $popupType): array
    {
        if ($popupType === 'promo') {
            return SchemePresentationOrder::sort(
                $this->calculator->availableSchemes($shop, $product, 'promo'),
                $shop
            );
        }
        if ($popupType !== 'standard') {
            return [];
        }

        return SchemePresentationOrder::sort(array_merge(
            $this->calculator->availableSchemes($shop, $product, 'standard'),
            $this->calculator->availableSchemes($shop, $product, 'promo')
        ), $shop);
    }

    public static function key(AvailableScheme $scheme): string
    {
        return self::keyFromParts($scheme->type, $scheme->kopCode, $scheme->months, $scheme->filterId);
    }

    public static function keyFromParts(string $type, string $kopCode, int $months, int $filterId): string
    {
        return implode('|', [
            $type,
            rawurlencode($kopCode),
            (string) $months,
            (string) $filterId,
        ]);
    }

    /**
     * @param array<string, mixed> $shop
     */
    public static function description(array $shop, AvailableScheme $scheme): string
    {
        if (is_array($scheme->filter)) {
            return trim((string) ($scheme->filter['uni_kop_desc'] ?? ''));
        }
        $settings = is_array($shop['kop']['by_default'] ?? null) ? $shop['kop']['by_default'] : [];

        return trim((string) ($settings[$scheme->type === 'promo' ? 'uni_kop_promo_desc' : 'uni_kop_default_desc'] ?? ''));
    }

    /**
     * @param AvailableScheme[] $schemes
     */
    public static function find(array $schemes, string $kopCode, int $months, int $filterId): ?AvailableScheme
    {
        foreach ($schemes as $scheme) {
            if ($scheme->kopCode === $kopCode && $scheme->months === $months && $scheme->filterId === $filterId) {
                return $scheme;
            }
        }

        return null;
    }
}
