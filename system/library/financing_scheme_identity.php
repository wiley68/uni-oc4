<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Canonical Product ↔ Checkout financing scheme identity (Buy handoff).
 *
 * Business identity: scheme_type + kop_code + months; prefer exact filter_id.
 * Optional scheme_key (ProductSchemeList pipe key) is matched first when present.
 * First installment is calculated input — not part of identity.
 */
final class FinancingSchemeIdentity
{
    public static function normalizeSchemeType(mixed $type): string
    {
        return strtolower(trim((string) $type));
    }

    public static function normalizeKopCode(mixed $kopCode): string
    {
        $raw = trim((string) $kopCode);
        if ($raw === '') {
            return '';
        }
        // Keys may carry rawurlencoded kop; compare on decoded canonical text.
        $decoded = rawurldecode($raw);

        return trim($decoded);
    }

    public static function normalizeFilterId(mixed $filterId): int
    {
        if ($filterId === null || $filterId === '') {
            return 0;
        }

        return (int) $filterId;
    }

    public static function normalizeMonths(mixed $months): int
    {
        return (int) $months;
    }

    /**
     * @param array<string, mixed> $scheme Presenter row
     * @param array<string, mixed> $preference Session preference
     */
    public static function matchesBusinessIdentity(array $scheme, array $preference): bool
    {
        return self::normalizeSchemeType($scheme['scheme_type'] ?? '') === self::normalizeSchemeType($preference['scheme_type'] ?? '')
            && self::normalizeKopCode($scheme['kop_code'] ?? '') === self::normalizeKopCode($preference['kop_code'] ?? '')
            && self::normalizeMonths($scheme['months'] ?? 0) === self::normalizeMonths($preference['months'] ?? 0);
    }

    public static function filterMatches(array $scheme, array $preference): bool
    {
        return self::normalizeFilterId($scheme['filter_id'] ?? 0)
            === self::normalizeFilterId($preference['filter_id'] ?? 0);
    }

    /**
     * Resolve the Checkout presenter's scheme key for a Product Buy preference.
     *
     * @param list<array<string, mixed>> $schemes
     * @param array<string, mixed> $preference
     */
    public static function resolveCheckoutSchemeKey(array $schemes, array $preference): ?string
    {
        $wantKey = trim((string) ($preference['scheme_key'] ?? ''));
        if ($wantKey !== '') {
            foreach ($schemes as $scheme) {
                if (!is_array($scheme)) {
                    continue;
                }
                $key = trim((string) ($scheme['key'] ?? ''));
                if ($key !== '' && $key === $wantKey) {
                    return $key;
                }
            }
            // Prefer rebuilding from parts when Product/Checkout pipe keys differ only by encoding.
            $rebuilt = self::rebuildKeyFromPreference($preference);
            if ($rebuilt !== null) {
                foreach ($schemes as $scheme) {
                    if (!is_array($scheme)) {
                        continue;
                    }
                    $key = trim((string) ($scheme['key'] ?? ''));
                    if ($key !== '' && $key === $rebuilt) {
                        return $key;
                    }
                }
            }
        }

        $candidates = [];
        foreach ($schemes as $scheme) {
            if (!is_array($scheme)) {
                continue;
            }
            if (!self::matchesBusinessIdentity($scheme, $preference)) {
                continue;
            }
            $candidates[] = $scheme;
        }
        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $scheme) {
            if (self::filterMatches($scheme, $preference)) {
                $key = trim((string) ($scheme['key'] ?? ''));

                return $key !== '' ? $key : null;
            }
        }

        $key = trim((string) ($candidates[0]['key'] ?? ''));

        return $key !== '' ? $key : null;
    }

    /**
     * @param array<string, mixed> $preference
     */
    private static function rebuildKeyFromPreference(array $preference): ?string
    {
        $type = self::normalizeSchemeType($preference['scheme_type'] ?? '');
        $kop = self::normalizeKopCode($preference['kop_code'] ?? '');
        $months = self::normalizeMonths($preference['months'] ?? 0);
        if ($type === '' || $kop === '' || $months <= 0) {
            return null;
        }

        return ProductSchemeList::keyFromParts(
            $type,
            $kop,
            $months,
            self::normalizeFilterId($preference['filter_id'] ?? 0)
        );
    }
}
