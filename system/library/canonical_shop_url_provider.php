<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Deterministic canonical shop URL for CP login `name` field.
 */
final class CanonicalShopUrlProvider
{
    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        if (str_starts_with(strtolower($url), 'http://')) {
            $url = 'https://' . substr($url, 7);
        }

        return rtrim($url, '/');
    }

    /**
     * Prefer SSL catalog URL when available (Cloudflare / proxy aware via OpenCart config).
     */
    public function resolve(?string $sslUrl, ?string $plainUrl): string
    {
        $candidate = trim((string) ($sslUrl ?? ''));
        if ($candidate === '') {
            $candidate = trim((string) ($plainUrl ?? ''));
        }

        return self::normalize($candidate);
    }
}
