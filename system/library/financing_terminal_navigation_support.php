<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Shared Product/Cart/Checkout terminal Thank You navigation
 * (Process 2 + SmartUCF definite failure + Checkout Process 1 success).
 */
final class FinancingTerminalNavigationSupport
{
    public const STEP_SMARTUCF_TERMINAL_FAILED = 'smartucf_terminal_failed';

    public const STEP_BANK_REDIRECT = 'bank_redirect';

    public static function isSmartUcfTerminalFailure(ProductFinancingResult $result): bool
    {
        return $result->step === self::STEP_SMARTUCF_TERMINAL_FAILED;
    }

    /**
     * Checkout Process 1 success: local Thank You (not bank application URL).
     * Product/Cart keep bank redirect via {@see ProductFinancingResult::$redirectUrl}.
     */
    public static function isCheckoutProcess1Success(ProductFinancingResult $result): bool
    {
        return $result->success
            && $result->step === self::STEP_BANK_REDIRECT
            && $result->bankSubmitted;
    }

    public static function isThankYouTerminalStep(ProductFinancingResult $result): bool
    {
        return self::isSmartUcfTerminalFailure($result)
            || ($result->success && $result->step === 'process2_prepared');
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $sessionData
     * @return array<string, mixed>
     */
    public static function enrichTerminalPayload(
        array $payload,
        ProductFinancingResult $result,
        string $thankYouUrl,
        array &$sessionData,
        bool $checkoutProcess1ToThankYou = false
    ): array {
        if ($checkoutProcess1ToThankYou && self::isCheckoutProcess1Success($result) && $result->orderId !== null) {
            $sessionData['order_id'] = $result->orderId;
            $sessionData['mt_uni_credit_success_order_id'] = $result->orderId;
            // Checkout must navigate to local Thank You — never the SmartUCF application URL.
            $payload['redirect_url'] = $thankYouUrl;
            $payload['redirect'] = $thankYouUrl;
            $payload['terminal'] = true;
            $payload['continuation'] = 'thank_you';
            $payload['outcome'] = 'success';

            return $payload;
        }

        if ($result->orderId === null || !self::isThankYouTerminalStep($result)) {
            return $payload;
        }

        $sessionData['order_id'] = $result->orderId;
        $sessionData['mt_uni_credit_success_order_id'] = $result->orderId;
        if (empty($payload['redirect_url'])) {
            $payload['redirect_url'] = $thankYouUrl;
        }

        return $payload;
    }
}
