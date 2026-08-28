<?php

$_['heading_title']           = 'UniCredit Purchases on Credit';
$_['text_extension']          = 'Extensions';
$_['text_home']               = 'Home';
$_['text_success']            = 'Settings saved successfully.';
$_['text_edit']               = 'Module settings';
$_['text_enabled']            = 'Enabled';
$_['text_disabled']           = 'Disabled';
$_['text_yes']                = 'Yes';
$_['text_no']                 = 'No';
$_['text_health']             = 'Diagnostics';
$_['text_health_placeholder'] = 'Deployment and bank-data diagnostics. Tokens, secrets and PEM are never shown.';
$_['text_version']            = 'Version';
$_['text_extension_code']     = 'Extension code';
$_['text_events_registered']  = 'Registered events';
$_['text_cp_endpoint']        = 'Bank endpoint';
$_['text_environment_config'] = 'Environment config';
$_['text_secret_config']      = 'Secret configuration';
$_['text_certificate']        = 'Certificate file';
$_['text_private_key']        = 'Private key file';
$_['text_certificate_validity'] = 'Certificate validity';
$_['text_certificate_not_after'] = 'Valid until';
$_['text_certificate_key_match'] = 'Certificate / key match';
$_['text_deployment_ready']   = 'Deployment ready';
$_['text_bank_actions']       = 'Bank data';
$_['text_bank_actions_help']  = 'Save UNICID and secret, then refresh bank data. Authentication is automatic.';
$_['text_cp_auth_state']      = 'Connection state';
$_['text_cp_token_expires']   = 'Session expires';
$_['text_cp_cache_present']   = 'Bank data cache';
$_['text_cp_cache_fetched_at'] = 'Last refresh';
$_['text_cp_cache_expires_at'] = 'Cache expires';
$_['text_cp_cache_fresh']     = 'Cache is fresh';

$_['text_auth_state_missing_credentials'] = 'Missing credentials';
$_['text_auth_state_disconnected'] = 'No active session';
$_['text_auth_state_authenticated'] = 'Active session';
$_['text_auth_state_expired'] = 'Session expired';

$_['entry_status']            = 'Status';
$_['entry_unicid']            = 'Shop unique identification code';
$_['entry_secret']            = 'Shop secret code';
$_['help_secret']             = 'Bank shop secret. Never displayed after save.';
$_['text_secret_configured']  = 'Secret configured';
$_['text_secret_keep_current'] = 'Leave blank to keep the current secret.';
$_['help_journal_unavailable'] = 'The operations journal will be available in a later phase (bank request diagnostics).';

$_['button_save']             = 'Save settings';
$_['button_back']             = 'Back';
$_['button_refresh_bank_data'] = 'Refresh bank data';
$_['button_download_journal'] = 'Download operations journal';

$_['text_bank_data_refreshed'] = 'Bank data refreshed successfully.';
$_['text_bank_data_refreshed_at'] = 'Updated at: %s.';
$_['text_bank_data_scheme_count'] = 'Schemes in cache: %d.';

$_['error_bank_unicid_missing'] = 'UNICID is missing. Save settings and try again.';
$_['error_bank_secret_missing'] = 'Shop secret is missing. Save settings and try again.';
$_['error_bank_secret_unreadable'] = 'Stored secret cannot be read. Enter it again and save.';
$_['error_bank_authentication_failed'] = 'Bank credentials were rejected.';
$_['error_bank_shop_snapshot_invalid'] = 'Bank data is invalid and was not applied. Previous configuration is kept.';
$_['error_bank_transient_failure'] = 'Bank connection is temporarily unavailable. Please try again.';
$_['error_bank_request_failed'] = 'Bank data could not be refreshed. Check settings and try again.';

$_['error_secret_required']   = 'Shop secret code is required.';
$_['error_unicid_required']   = 'UNICID is required.';

$_['text_health_status_healthy'] = 'OK';
$_['text_health_status_missing'] = 'Missing';
$_['text_health_status_invalid'] = 'Invalid';
$_['text_health_status_unreadable'] = 'Unreadable';
$_['text_health_status_expired'] = 'Expired';
$_['text_health_status_not_yet_valid'] = 'Not yet valid';
$_['text_health_status_mismatch'] = 'Mismatch';
$_['text_health_status_unknown'] = 'Unknown';

$_['error_permission']        = 'Warning: You do not have permission to modify UniCredit module settings!';
