<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Module-owned storefront CSRF for Product AJAX (guest + logged-in).
 *
 * OpenCart catalog does not guarantee session['csrf_token'] on Product pages.
 */
final class ProductStorefrontCsrf
{
    public const SESSION_KEY = 'mt_uni_credit_csrf_token';

    public const TOKEN_BYTES = 32;

    /**
     * @param array<string, mixed> $sessionData
     */
    public function getOrCreate(array &$sessionData): string
    {
        $existing = (string) ($sessionData[self::SESSION_KEY] ?? '');
        if ($this->isValidFormat($existing)) {
            return $existing;
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $sessionData[self::SESSION_KEY] = $token;

        return $token;
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return 'ok'|'missing_csrf'|'invalid_csrf'
     */
    public function validate(array $sessionData, string $provided): string
    {
        $expected = (string) ($sessionData[self::SESSION_KEY] ?? '');
        $provided = trim($provided);

        if ($expected === '' || !$this->isValidFormat($expected)) {
            return 'missing_csrf';
        }
        if ($provided === '' || !$this->isValidFormat($provided)) {
            return 'missing_csrf';
        }
        if (!hash_equals($expected, $provided)) {
            return 'invalid_csrf';
        }

        return 'ok';
    }

    public function isValidFormat(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $token);
    }
}
