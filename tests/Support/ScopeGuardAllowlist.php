<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

/**
 * Shared production-path allowlist for phase scope-guard tests (Phase 11+ domains).
 */
final class ScopeGuardAllowlist
{
    public static function isPhase11PlusProductionFile(string $path): bool
    {
        return str_contains($path, '/system/library/smart_ucf_')
            || str_contains($path, '/system/library/certificate_')
            || str_contains($path, '/system/library/process_two_')
            || str_ends_with($path, '/system/library/recording_process_two_mailer.php')
            || str_ends_with($path, '/system/library/php_mail_process_two_mailer.php')
            || str_ends_with($path, '/system/library/resume_submission_factory.php')
            || str_ends_with($path, '/system/library/bank_status.php')
            || str_ends_with($path, '/system/library/control_panel_order_status_port.php')
            || str_ends_with($path, '/system/library/post_control_panel_lifecycle_service.php')
            || str_ends_with($path, '/system/library/shop_configuration_flags.php')
            || str_ends_with($path, '/system/library/financing_control_panel_completion.php')
            || str_ends_with($path, '/system/library/control_panel_client.php')
            || str_ends_with($path, '/system/library/control_panel_order_payload_builder.php')
            || str_ends_with($path, '/system/library/control_panel_order_lifecycle_service.php')
            || str_ends_with($path, '/system/library/financing_presentation_repository.php')
            || str_ends_with($path, '/system/library/financing_presentation_service.php')
            || str_ends_with($path, '/system/library/financing_presentation_snapshot.php')
            || str_ends_with($path, '/system/library/financing_leasing_presenter.php')
            || str_ends_with($path, '/system/library/product_buy_checkout_preference.php')
            || str_ends_with($path, '/system/library/mtls_private_key_passphrase_provider.php')
            || str_ends_with($path, '/system/library/financing_terminal_navigation_support.php')
            || str_ends_with($path, '/system/library/financing_order_status_policy.php')
            || str_ends_with($path, '/system/library/product_financing_result.php')
            || str_ends_with($path, '/system/library/checkout_financing_submission_service.php')
            || str_ends_with($path, '/system/library/cart_financing_submission_service.php')
            || str_ends_with($path, '/system/library/product_financing_submission_service.php')
            || str_ends_with($path, '/system/library/inbound_bank_status_vocabulary.php')
            || str_ends_with($path, '/catalog/controller/module/mt_uni_credit_product.php')
            || str_ends_with($path, '/catalog/controller/module/mt_uni_credit_cart.php')
            || str_ends_with($path, '/catalog/model/module/mt_uni_credit_checkout.php')
            || str_ends_with($path, '/catalog/view/javascript/mt_uni_credit_checkout.js')
            || str_ends_with($path, '/catalog/controller/payment/mt_uni_credit.php')
            || str_contains($path, '/catalog/controller/event/mt_uni_credit_')
            || str_ends_with($path, '/admin/controller/module/mt_uni_credit.php');
    }

    public static function isBridgeAInboundProductionFile(string $path): bool
    {
        return str_contains($path, '/catalog/controller/api/')
            || str_contains($path, '/system/library/module_request_')
            || str_contains($path, '/system/library/module_api_exception.php')
            || str_contains($path, '/system/library/inbound_')
            || str_contains($path, '/system/library/order_bank_status_repository.php')
            || str_contains($path, '/system/library/diagnostic_');
    }
}
