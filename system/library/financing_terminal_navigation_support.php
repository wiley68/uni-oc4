<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Shared Product/Cart/Checkout terminal Thank You navigation (Process 2 + SmartUCF definite failure).
 */
final class FinancingTerminalNavigationSupport
{
    public const STEP_SMARTUCF_TERMINAL_FAILED = 'smartucf_terminal_failed';

    public static function isSmartUcfTerminalFailure(ProductFinancingResult $result): bool
    {
        return $result->step === self::STEP_SMARTUCF_TERMINAL_FAILED;
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
        array &$sessionData
    ): array {
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
