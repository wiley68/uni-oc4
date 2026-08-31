<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * TEMPORARY Phase 11C Remediation 09E browser/runtime proof.
 *
 * Enable with ?mtuc_trace=1 or POST mtuc_trace=1 (stored in session for the handoff TTL).
 * Emits only non-PII booleans/codes/keys into JSON _mtuc_trace fields / X-Mtuc-Trace headers.
 *
 * Remove after operator gate is proven.
 */
final class ProductBuyHandoffTrace
{
    public const SESSION_KEY = 'mt_uni_credit_09e_trace';

    public const BUILD = '09E-dd3c0d8-trace1';

    public const JSON_KEY = '_mtuc_trace';

    public const TTL_SECONDS = 1800;

    /**
     * @param array<string, mixed> $sessionData
     * @param array<string, mixed> $requestGet
     * @param array<string, mixed> $requestPost
     */
    public static function captureRequest(array &$sessionData, array $requestGet, array $requestPost): void
    {
        $flag = $requestGet['mtuc_trace'] ?? $requestPost['mtuc_trace'] ?? null;
        if ((string) $flag === '1' || $flag === 1 || $flag === true) {
            self::enable($sessionData);

            return;
        }
        if ((string) $flag === '0' || $flag === 0) {
            unset($sessionData[self::SESSION_KEY]);
        }
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    public static function enable(array &$sessionData): void
    {
        $sessionData[self::SESSION_KEY] = [
            'enabled'    => true,
            'build'      => self::BUILD,
            'seq'        => 0,
            'created_at' => time(),
        ];
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    public static function isEnabled(array &$sessionData): bool
    {
        $raw = $sessionData[self::SESSION_KEY] ?? null;
        if (!is_array($raw) || empty($raw['enabled'])) {
            return false;
        }
        $created = (int) ($raw['created_at'] ?? 0);
        if ($created <= 0 || (time() - $created) > self::TTL_SECONDS) {
            unset($sessionData[self::SESSION_KEY]);

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    public static function nextSeq(array &$sessionData): int
    {
        if (!self::isEnabled($sessionData)) {
            return 0;
        }
        $raw = $sessionData[self::SESSION_KEY];
        $seq = (int) ($raw['seq'] ?? 0) + 1;
        $raw['seq'] = $seq;
        $sessionData[self::SESSION_KEY] = $raw;

        return $seq;
    }

    /**
     * Safe preference snapshot for Network traces (no PII).
     *
     * @param array<string, mixed>|null $preference
     * @return array<string, mixed>
     */
    public static function preferenceSnapshot(?array $preference): array
    {
        if ($preference === null) {
            return [
                'preference_present'      => false,
                'prefer_payment'          => false,
                'payment_code'            => '',
                'scheme_key'              => '',
                'months'                  => 0,
                'scheme_user_override'    => false,
                'payment_user_overridden' => false,
            ];
        }

        return [
            'preference_present'      => true,
            'prefer_payment'          => !empty($preference['prefer_payment']),
            'payment_code'            => (string) ($preference['payment_code'] ?? ''),
            'scheme_key'              => (string) ($preference['scheme_key'] ?? ''),
            'months'                  => (int) ($preference['months'] ?? 0),
            'scheme_user_override'    => !empty($preference['scheme_user_override']),
            'payment_user_overridden' => !empty($preference['payment_user_overridden']),
        ];
    }

    /**
     * @param array<string, mixed> $paymentMethods
     * @return list<string>
     */
    public static function paymentOrderCodes(array $paymentMethods): array
    {
        $codes = [];
        foreach ($paymentMethods as $extension) {
            if (!is_array($extension)) {
                continue;
            }
            $options = $extension['option'] ?? [];
            if (!is_array($options)) {
                continue;
            }
            foreach ($options as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $code = (string) ($option['code'] ?? '');
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }

    /**
     * @param array<string, mixed> $sessionData
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function wrap(array &$sessionData, string $hook, array $payload): array
    {
        return array_merge([
            'hook'    => $hook,
            'build'   => self::BUILD,
            'seq'     => self::nextSeq($sessionData),
            'enabled' => true,
        ], $payload);
    }
}
