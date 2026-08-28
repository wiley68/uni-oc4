<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Module-local operator settings (PS9/Woo parity), excluding credentials.
 */
final class ModuleLocalSettings
{
    public const ADVERTISING_ENABLED = 'module_mt_uni_credit_advertising_enabled';
    public const DEBUG_ENABLED = 'module_mt_uni_credit_debug_enabled';
    public const PRODUCT_BUTTON_ACTION = 'module_mt_uni_credit_product_button_action';
    public const BUTTON_TOP_SPACING = 'module_mt_uni_credit_button_top_spacing';

    public const BUTTON_ACTION_ADD_TO_CART = 'add_to_cart';
    public const BUTTON_ACTION_BUY = 'buy';

    public const DEFAULT_ADVERTISING_ENABLED = 0;
    public const DEFAULT_DEBUG_ENABLED = 0;
    public const DEFAULT_PRODUCT_BUTTON_ACTION = self::BUTTON_ACTION_ADD_TO_CART;
    public const DEFAULT_BUTTON_TOP_SPACING = 0;
    public const MAX_BUTTON_TOP_SPACING = 200;

    /**
     * @return list<string>
     */
    public static function productButtonActions(): array
    {
        return [self::BUTTON_ACTION_ADD_TO_CART, self::BUTTON_ACTION_BUY];
    }

    public static function normalizeProductButtonAction(string $action): string
    {
        $action = trim($action);

        return in_array($action, self::productButtonActions(), true)
            ? $action
            : self::DEFAULT_PRODUCT_BUTTON_ACTION;
    }

    public static function normalizeButtonTopSpacing(mixed $spacing): int
    {
        if (!is_numeric($spacing)) {
            return self::DEFAULT_BUTTON_TOP_SPACING;
        }

        return max(0, min(self::MAX_BUTTON_TOP_SPACING, (int) $spacing));
    }

    public static function normalizeFlag(mixed $value): int
    {
        return ((int) $value) === 1 ? 1 : 0;
    }
}
