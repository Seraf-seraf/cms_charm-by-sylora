<?php
// Registers the bundled CDEK extension without storing production credentials.

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$mysqli->set_charset('utf8mb4');
$prefix = DB_PREFIX;

try {
	registerExtension($mysqli, $prefix);
	createOrderMetaTable($mysqli, $prefix);
	registerEvents($mysqli, $prefix);
	insertSettingIfMissing($mysqli, $prefix, 'shipping_cdek_official', 'shipping_cdek_official_status', '0');

	echo 'CDEK extension is registered. Configure credentials before enabling it.' . PHP_EOL;
} catch (Throwable $exception) {
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}

function registerExtension(mysqli $mysqli, string $prefix): void {
	$table = escapeIdentifier($prefix . 'extension');
	$result = $mysqli->query("SELECT extension_id FROM `" . $table . "` WHERE `type` = 'shipping' AND `code` = 'cdek_official' LIMIT 1");

	if (!$result->num_rows) {
		$mysqli->query("INSERT INTO `" . $table . "` SET `type` = 'shipping', `code` = 'cdek_official'");
	}
}

function createOrderMetaTable(mysqli $mysqli, string $prefix): void {
	$table = escapeIdentifier($prefix . 'cdek_order_meta');
	$mysqli->query("CREATE TABLE IF NOT EXISTS `" . $table . "` (
		`id` INT(11) NOT NULL AUTO_INCREMENT,
		`order_id` INT(11) NOT NULL,
		`cdek_number` VARCHAR(255) NOT NULL DEFAULT '',
		`cdek_uuid` VARCHAR(255) NOT NULL DEFAULT '',
		`pvz_code` VARCHAR(255) NOT NULL DEFAULT '',
		`length` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		`width` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		`height` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		`weight` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
		`deleted_at` TIMESTAMP NULL DEFAULT NULL,
		PRIMARY KEY (`id`),
		UNIQUE KEY `order_id_unique` (`order_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function registerEvents(mysqli $mysqli, string $prefix): void {
	$events = array(
		'cdek_official_order_info' => array('admin/view/sale/order_info/before', 'extension/shipping/cdek_official/orderInfo'),
		'cdek_official_order_info_scripts' => array('admin/controller/sale/order/info/before', 'extension/shipping/cdek_official/orderInfoScripts'),
		'cdek_official_validate_office_code' => array('catalog/controller/checkout/shipping_method/save/before', 'extension/shipping/cdek_official/validateOfficeCode'),
		'cdek_official_checkout_success' => array('catalog/controller/checkout/success/before', 'extension/shipping/cdek_official/saveOfficeCode'),
		'cdek_official_checkout_confirm' => array('catalog/controller/checkout/confirm/after', 'extension/shipping/cdek_official/saveOfficeCode'),
		'cdek_official_header_before' => array('catalog/view/common/header/before', 'extension/shipping/cdek_official/addCheckoutHeaderScript')
	);
	$table = escapeIdentifier($prefix . 'event');

	foreach ($events as $code => $event) {
		$escaped_code = $mysqli->real_escape_string($code);
		$result = $mysqli->query("SELECT event_id FROM `" . $table . "` WHERE `code` = '" . $escaped_code . "' LIMIT 1");

		if (!$result->num_rows) {
			$mysqli->query("INSERT INTO `" . $table . "` SET `code` = '" . $escaped_code . "', `trigger` = '" . $mysqli->real_escape_string($event[0]) . "', `action` = '" . $mysqli->real_escape_string($event[1]) . "', `status` = 1, `sort_order` = 0");
		}
	}
}

function insertSettingIfMissing(mysqli $mysqli, string $prefix, string $code, string $key, string $value): void {
	$table = escapeIdentifier($prefix . 'setting');
	$escaped_code = $mysqli->real_escape_string($code);
	$escaped_key = $mysqli->real_escape_string($key);
	$result = $mysqli->query("SELECT setting_id FROM `" . $table . "` WHERE store_id = 0 AND `code` = '" . $escaped_code . "' AND `key` = '" . $escaped_key . "' LIMIT 1");

	if (!$result->num_rows) {
		$mysqli->query("INSERT INTO `" . $table . "` SET store_id = 0, `code` = '" . $escaped_code . "', `key` = '" . $escaped_key . "', `value` = '" . $mysqli->real_escape_string($value) . "', serialized = 0");
	}
}

function escapeIdentifier(string $identifier): string {
	return str_replace('`', '``', $identifier);
}
