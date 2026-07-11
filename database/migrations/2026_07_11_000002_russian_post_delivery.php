<?php
require_once __DIR__ . '/../../upload/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$db->set_charset('utf8mb4');
$prefix = DB_PREFIX;

$db->query("CREATE TABLE IF NOT EXISTS `{$prefix}russian_post_order` (`order_id` INT(11) NOT NULL, `office_id` VARCHAR(64) NOT NULL DEFAULT '', `postcode` VARCHAR(6) NOT NULL, `address` VARCHAR(255) NOT NULL, `cost` DECIMAL(15,4) NOT NULL, `min_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0, `max_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0, `date_added` DATETIME NOT NULL, PRIMARY KEY (`order_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$exists = $db->query("SELECT extension_id FROM `{$prefix}extension` WHERE type='shipping' AND code='russian_post' LIMIT 1");
if (!$exists->num_rows) $db->query("INSERT INTO `{$prefix}extension` SET type='shipping', code='russian_post'");

$events = array(
	'russian_post_validate_office' => array('catalog/controller/checkout/shipping_method/save/before', 'extension/shipping/russian_post/validateOffice'),
	'russian_post_save_order' => array('catalog/controller/checkout/confirm/after', 'extension/shipping/russian_post/saveOrder'),
	'russian_post_header_script' => array('catalog/view/common/header/before', 'extension/shipping/russian_post/addHeaderScript'),
	'russian_post_admin_order' => array('admin/view/sale/order_info/before', 'extension/shipping/russian_post/orderInfo')
);
foreach ($events as $code => $event) {
	$check = $db->query("SELECT event_id FROM `{$prefix}event` WHERE code='" . $db->real_escape_string($code) . "' LIMIT 1");
	if (!$check->num_rows) $db->query("INSERT INTO `{$prefix}event` SET code='" . $db->real_escape_string($code) . "', `trigger`='" . $db->real_escape_string($event[0]) . "', action='" . $db->real_escape_string($event[1]) . "', status=1, sort_order=0");
}

$settings = array('widget_id'=>'62604','origin_postcode'=>'','api_url'=>'https://otpravka-api.pochta.ru','token'=>'','login'=>'','password'=>'','default_weight'=>'200','default_length'=>'15','default_width'=>'10','default_height'=>'5','timeout'=>'10','status'=>'0','sort_order'=>'2');
foreach ($settings as $name => $value) {
	$key = 'shipping_russian_post_' . $name;
	$check = $db->query("SELECT setting_id FROM `{$prefix}setting` WHERE store_id=0 AND `key`='" . $db->real_escape_string($key) . "' LIMIT 1");
	if (!$check->num_rows) $db->query("INSERT INTO `{$prefix}setting` SET store_id=0, code='shipping_russian_post', `key`='" . $db->real_escape_string($key) . "', `value`='" . $db->real_escape_string($value) . "', serialized=0");
}
echo "Russian Post extension is registered.\n";
