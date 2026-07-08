<?php
// Heading
$_['heading_title']               = 'Payment Service';

// Text
$_['text_extension']              = 'Extensions';
$_['text_success']                = 'Success: You have modified Payment Service payment module!';
$_['text_edit']                   = 'Edit Payment Service';
$_['text_enabled']                = 'Enabled';
$_['text_disabled']               = 'Disabled';
$_['text_all_zones']              = 'All Zones';

// Entry
$_['entry_api_url']               = 'Payment service URL';
$_['entry_api_key']               = 'Merchant API key';
$_['entry_shared_secret']         = 'Shared secret';
$_['entry_callback_url']          = 'Callback URL';
$_['entry_total']                 = 'Minimum total';
$_['entry_pending_status']        = 'Pending status';
$_['entry_success_status']        = 'Succeeded status';
$_['entry_failed_status']         = 'Failed status';
$_['entry_canceled_status']       = 'Canceled status';
$_['entry_refunded_status']       = 'Refunded status';
$_['entry_geo_zone']              = 'Geo Zone';
$_['entry_status']                = 'Status';
$_['entry_sort_order']            = 'Sort Order';
$_['entry_timestamp_skew']        = 'Timestamp skew, sec.';

// Help
$_['help_api_url']                = 'Example: http://localhost:8080 or http://localhost:8080/api/v1. The /payments path is appended automatically.';
$_['help_callback_url']           = 'Use this URL as merchants.callback_url in the payment service.';
$_['help_total']                  = 'The checkout total the order must reach before this payment method becomes active.';
$_['help_timestamp_skew']         = 'Maximum accepted time drift for signed callback requests.';

// Error
$_['error_permission']            = 'Warning: You do not have permission to modify Payment Service!';
$_['error_api_url']               = 'Payment service URL is required and must be valid.';
$_['error_api_key']               = 'Merchant API key is required.';
$_['error_shared_secret']         = 'Merchant shared secret is required.';
