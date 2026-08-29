<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Single shared ordering entry point for Product / Cart / Checkout scheme lists.
 *
 * Sort tuple:
 * months ASC → business type rank (standard=0, promo-like=1) → presentation rank
 * → filterId → kopCode → type → scheme key.
 */
final class SchemePresentationOrder
{
    /**
     * @param AvailableScheme[]    $schemes
     * @param array<string, mixed> $shop
     *
     * @return AvailableScheme[]
     */
    public static function sort(array $schemes, array $shop): array
    {
        return SchemePresentationCategory::sort($schemes, $shop);
    }

    /**
     * Re-assert canonical order on the final presenter DTO rows handed to Twig/JS.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $shop
     *
     * @return list<array<string, mixed>>
     */
    public static function sortPresentedRows(array $rows, array $shop): array
    {
        $schemes = [];
        $byKey = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            $scheme = new AvailableScheme(
                (string) ($row['scheme_type'] ?? 'standard'),
                (string) ($row['kop_code'] ?? ''),
                (int) ($row['months'] ?? 0),
                (int) ($row['filter_id'] ?? 0),
                [
                    'uni_promo'    => (($row['scheme_type'] ?? '') === 'promo') ? 1 : 0,
                    'uni_kop_desc' => (string) ($row['description'] ?? ''),
                ],
                [
                    'interestPercent' => !empty($row['zero_interest_promo']) ? 0.0 : 1.0,
                    'coeff'           => 0.09,
                ]
            );
            $schemes[] = $scheme;
            $byKey[ProductSchemeList::key($scheme)] = $row;
            if ($key !== '') {
                $byKey[$key] = $row;
            }
        }

        $sorted = [];
        $seen = [];
        foreach (self::sort($schemes, $shop) as $scheme) {
            $key = ProductSchemeList::key($scheme);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (isset($byKey[$key])) {
                $sorted[] = $byKey[$key];
            }
        }

        return $sorted;
    }
}
