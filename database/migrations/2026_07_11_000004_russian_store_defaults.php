<?php
require_once __DIR__ . '/../../upload/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$db->set_charset('utf8mb4');
$prefix = DB_PREFIX;

$currency = $db->query("SELECT currency_id FROM `{$prefix}currency` WHERE code = 'RUB' LIMIT 1");
if (!$currency->num_rows) {
	$db->query("INSERT INTO `{$prefix}currency` SET title='Российский рубль', code='RUB', symbol_left='', symbol_right=' ₽', decimal_place='2', value='1.00000000', status='1', date_modified=NOW()");
} else {
	$db->query("UPDATE `{$prefix}currency` SET status='1', value='1.00000000', date_modified=NOW() WHERE code='RUB'");
}

foreach (array('config_currency'=>'RUB', 'config_country_id'=>'176', 'config_zone_id'=>'2769') as $key => $value) {
	$stmt = $db->prepare("UPDATE `{$prefix}setting` SET value = ? WHERE store_id = 0 AND `key` = ?");
	$stmt->bind_param('ss', $value, $key);
	$stmt->execute();
}
echo "Russian store defaults are configured.\n";
