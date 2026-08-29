<?php

$_['heading_title']           = 'UniCredit Purchases on Credit';
$_['text_extension']          = 'Extensions';
$_['text_home']               = 'Home';
$_['text_success']            = 'Settings saved successfully.';
$_['text_edit']               = 'Module settings';
$_['text_enabled']            = 'Enabled';
$_['text_disabled']           = 'Disabled';
$_['text_product_button_add_to_cart'] = 'Add to cart';
$_['text_product_button_buy'] = 'Buy';

$_['entry_status']            = 'Status';
$_['entry_unicid']            = 'Shop unique identification code';
$_['entry_secret']            = 'Shop secret code';
$_['entry_advertising_enabled'] = 'Show advertising';
$_['entry_debug_enabled']     = 'Debug mode';
$_['entry_product_button_action'] = 'Buy button';
$_['entry_button_top_spacing'] = 'Space above the button';
$_['entry_awaiting_financing_order_status'] = 'Status after Product/Cart local order';

$_['help_unicid']             = 'Your unique shop identification code in the UniCredit system.';
$_['help_secret']             = 'Your shop secret code in the UniCredit system.';
$_['text_secret_keep_current'] = 'Leave blank to keep the current secret.';
$_['help_advertising_enabled'] = 'Enable or disable advertising on the store home page.';
$_['help_debug_enabled']      = 'When enabled, technical events are written to the OpenCart server log only (never to the customer browser console).';
$_['help_product_button_action'] = 'Behavior of the secondary button in the product popup.';
$_['help_button_top_spacing'] = 'Space above the button in px (0–200).';
$_['help_awaiting_financing_order_status'] = 'After successful Product/Cart financing the local order moves from status 0 to this status (visible in Admin). “Same as Payment” uses payment_mt_uni_credit_order_status_id.';
$_['text_awaiting_use_payment'] = 'Same as Payment UniCredit status';
$_['help_journal_unavailable'] = 'The operations journal will be available in a later phase (bank request diagnostics).';

$_['button_save']             = 'Save';
$_['button_back']             = 'Back';
$_['button_refresh_bank_data'] = 'Refresh bank data';
$_['button_download_journal'] = 'Download operations journal';

$_['text_bank_data_refreshed'] = 'Bank data refreshed successfully.';
$_['text_bank_data_refreshed_at'] = 'Updated at: %s.';
$_['text_bank_data_scheme_count'] = 'Schemes in cache: %d.';

$_['error_bank_unicid_missing'] = 'UNICID is missing.';
$_['error_bank_secret_missing'] = 'Secret is missing.';
$_['error_bank_secret_unreadable'] = 'Stored secret cannot be read. Enter it again and save.';
$_['error_bank_shop_url_missing'] = 'Shop URL is missing for Control Panel connection.';
$_['error_bank_authentication_failed'] = 'Control Panel authentication failed.';
$_['error_bank_shop_snapshot_invalid'] = 'Invalid bank data was received.';
$_['error_bank_transient_failure'] = 'Control Panel is temporarily unavailable.';
$_['error_bank_request_failed'] = 'Bank data could not be refreshed due to a technical error.';

$_['error_secret_required']   = 'Shop secret code is required.';
$_['error_unicid_required']   = 'UNICID is required.';
$_['error_permission']        = 'Warning: You do not have permission to modify UniCredit module settings!';
