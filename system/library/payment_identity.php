<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Canonical OpenCart payment identity for UniCredit financing orders.
 *
 * Stored payment_method JSON uses code {@see ModuleConstants::PAYMENT_OPTION_CODE}
 * ({extension}.{option} = mt_uni_credit.mt_uni_credit).
 */
final class PaymentIdentity
{
    public const DISPLAY_NAME = 'UniCredit';

    /** @return array{name: string, code: string} */
    public static function paymentMethod(): array
    {
        return [
            'name' => self::DISPLAY_NAME,
            'code' => ModuleConstants::PAYMENT_OPTION_CODE,
        ];
    }

    public static function optionCode(): string
    {
        return ModuleConstants::PAYMENT_OPTION_CODE;
    }

    public static function extensionCode(): string
    {
        return ModuleConstants::PAYMENT_CODE;
    }

    /**
     * @param array<string, mixed>|string|null $storedPaymentMethod JSON-decoded array or raw code
     */
    public static function matchesStoredPayment(array|string|null $storedPaymentMethod): bool
    {
        if (is_string($storedPaymentMethod)) {
            return $storedPaymentMethod === self::optionCode();
        }
        if (!is_array($storedPaymentMethod)) {
            return false;
        }

        return ($storedPaymentMethod['code'] ?? '') === self::optionCode();
    }
}
