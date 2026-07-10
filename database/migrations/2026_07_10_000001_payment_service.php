<?php
// Installs the Payment Service extension without storing merchant credentials in the repository.

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$mysqli->set_charset('utf8mb4');

$prefix = DB_PREFIX;

try {
	createPaymentTables($mysqli, $prefix);
	registerExtension($mysqli, $prefix);

	$pending_status_id = getOrCreateOrderStatus($mysqli, $prefix, 'Ожидает оплаты', 'Awaiting payment');
	$success_status_id = getOrCreateOrderStatus($mysqli, $prefix, 'Оплачен', 'Paid');
	$failed_status_id = getOrCreateOrderStatus($mysqli, $prefix, 'Ошибка оплаты', 'Payment failed');
	$canceled_status_id = getOrCreateOrderStatus($mysqli, $prefix, 'Отменен', 'Canceled');
	$refunded_status_id = getOrCreateOrderStatus($mysqli, $prefix, 'Возврат', 'Refunded');

	$settings = array(
		'payment_payment_service_api_url' => 'https://pay.charm-by-sylora.ru',
		'payment_payment_service_total' => '0',
		'payment_payment_service_pending_status_id' => (string)$pending_status_id,
		'payment_payment_service_success_status_id' => (string)$success_status_id,
		'payment_payment_service_failed_status_id' => (string)$failed_status_id,
		'payment_payment_service_canceled_status_id' => (string)$canceled_status_id,
		'payment_payment_service_refunded_status_id' => (string)$refunded_status_id,
		'payment_payment_service_geo_zone_id' => '0',
		'payment_payment_service_status' => '0',
		'payment_payment_service_sort_order' => '1',
		'payment_payment_service_timestamp_skew' => '300'
	);

	foreach ($settings as $key => $value) {
		insertSettingIfMissing($mysqli, $prefix, 'payment_payment_service', $key, $value);
	}

	addProcessingStatus($mysqli, $prefix, $success_status_id);

	echo 'Payment Service extension is registered. Add merchant credentials in OpenCart before enabling it.' . PHP_EOL;
} catch (Throwable $exception) {
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}

function createPaymentTables(mysqli $mysqli, string $prefix): void {
	$event_table = escapeIdentifier($prefix . 'payment_service_event');
	$payment_table = escapeIdentifier($prefix . 'payment_service_payment');

	$mysqli->query("CREATE TABLE IF NOT EXISTS `" . $event_table . "` (
		`payment_service_event_id` INT(11) NOT NULL AUTO_INCREMENT,
		`event_id` VARCHAR(64) NOT NULL,
		`order_id` INT(11) NOT NULL,
		`payment_id` VARCHAR(64) NOT NULL,
		`event_type` VARCHAR(64) NOT NULL,
		`status` VARCHAR(32) NOT NULL,
		`payload` MEDIUMTEXT NOT NULL,
		`date_added` DATETIME NOT NULL,
		PRIMARY KEY (`payment_service_event_id`),
		UNIQUE KEY `event_id` (`event_id`),
		KEY `order_id` (`order_id`),
		KEY `payment_id` (`payment_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

	$mysqli->query("ALTER TABLE `" . $event_table . "` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
	$mysqli->query("ALTER TABLE `" . $event_table . "` MODIFY `payload` MEDIUMTEXT NOT NULL");

	if (!indexExists($mysqli, $event_table, 'payment_id')) {
		$mysqli->query("ALTER TABLE `" . $event_table . "` ADD KEY `payment_id` (`payment_id`)");
	}

	$mysqli->query("CREATE TABLE IF NOT EXISTS `" . $payment_table . "` (
		`payment_service_payment_id` INT(11) NOT NULL AUTO_INCREMENT,
		`order_id` INT(11) NOT NULL,
		`payment_id` VARCHAR(64) NOT NULL,
		`status` VARCHAR(32) NOT NULL,
		`amount_minor` BIGINT NOT NULL,
		`currency` CHAR(3) NOT NULL,
		`date_added` DATETIME NOT NULL,
		`date_modified` DATETIME NOT NULL,
		PRIMARY KEY (`payment_service_payment_id`),
		UNIQUE KEY `order_id` (`order_id`),
		UNIQUE KEY `payment_id` (`payment_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function registerExtension(mysqli $mysqli, string $prefix): void {
	$table = escapeIdentifier($prefix . 'extension');
	$result = $mysqli->query("SELECT extension_id FROM `" . $table . "` WHERE `type` = 'payment' AND `code` = 'payment_service' LIMIT 1");

	if (!$result->num_rows) {
		$mysqli->query("INSERT INTO `" . $table . "` SET `type` = 'payment', `code` = 'payment_service'");
	}
}

function getOrCreateOrderStatus(mysqli $mysqli, string $prefix, string $russian_name, string $english_name): int {
	$table = escapeIdentifier($prefix . 'order_status');
	$escaped_russian_name = $mysqli->real_escape_string($russian_name);
	$escaped_english_name = $mysqli->real_escape_string($english_name);
	$result = $mysqli->query("SELECT order_status_id FROM `" . $table . "` WHERE name IN ('" . $escaped_russian_name . "', '" . $escaped_english_name . "') ORDER BY (name = '" . $escaped_russian_name . "') DESC LIMIT 1");

	if ($result->num_rows) {
		$order_status_id = (int)$result->fetch_assoc()['order_status_id'];
	} else {
		$result = $mysqli->query("SELECT COALESCE(MAX(order_status_id), 0) + 1 AS order_status_id FROM `" . $table . "`");
		$order_status_id = (int)$result->fetch_assoc()['order_status_id'];
	}

	$language_table = escapeIdentifier($prefix . 'language');
	$languages = $mysqli->query("SELECT language_id, code FROM `" . $language_table . "`");

	while ($language = $languages->fetch_assoc()) {
		$name = $language['code'] === 'ru-ru' ? $russian_name : $english_name;
		$escaped_name = $mysqli->real_escape_string($name);
		$mysqli->query("INSERT INTO `" . $table . "` SET order_status_id = '" . $order_status_id . "', language_id = '" . (int)$language['language_id'] . "', name = '" . $escaped_name . "' ON DUPLICATE KEY UPDATE name = VALUES(name)");
	}

	return $order_status_id;
}

function insertSettingIfMissing(mysqli $mysqli, string $prefix, string $code, string $key, string $value): void {
	$table = escapeIdentifier($prefix . 'setting');
	$escaped_code = $mysqli->real_escape_string($code);
	$escaped_key = $mysqli->real_escape_string($key);
	$result = $mysqli->query("SELECT setting_id FROM `" . $table . "` WHERE store_id = 0 AND `code` = '" . $escaped_code . "' AND `key` = '" . $escaped_key . "' LIMIT 1");

	if (!$result->num_rows) {
		$escaped_value = $mysqli->real_escape_string($value);
		$mysqli->query("INSERT INTO `" . $table . "` SET store_id = 0, `code` = '" . $escaped_code . "', `key` = '" . $escaped_key . "', `value` = '" . $escaped_value . "', serialized = 0");
	}
}

function addProcessingStatus(mysqli $mysqli, string $prefix, int $status_id): void {
	$table = escapeIdentifier($prefix . 'setting');
	$result = $mysqli->query("SELECT setting_id, value FROM `" . $table . "` WHERE store_id = 0 AND `key` = 'config_processing_status' LIMIT 1");

	if (!$result->num_rows) {
		$value = $mysqli->real_escape_string(json_encode(array($status_id)));
		$mysqli->query("INSERT INTO `" . $table . "` SET store_id = 0, `code` = 'config', `key` = 'config_processing_status', `value` = '" . $value . "', serialized = 1");
		return;
	}

	$setting = $result->fetch_assoc();
	$statuses = json_decode($setting['value'], true);

	if (!is_array($statuses)) {
		throw new RuntimeException('config_processing_status must contain a JSON array');
	}

	$statuses = array_values(array_unique(array_map('intval', $statuses)));

	if (!in_array($status_id, $statuses, true)) {
		$statuses[] = $status_id;
		$value = $mysqli->real_escape_string(json_encode($statuses));
		$mysqli->query("UPDATE `" . $table . "` SET `value` = '" . $value . "', serialized = 1 WHERE setting_id = '" . (int)$setting['setting_id'] . "'");
	}
}

function escapeIdentifier(string $identifier): string {
	return str_replace('`', '``', $identifier);
}

function indexExists(mysqli $mysqli, string $table, string $index): bool {
	$escaped_index = $mysqli->real_escape_string($index);
	$result = $mysqli->query("SHOW INDEX FROM `" . $table . "` WHERE Key_name = '" . $escaped_index . "'");

	return $result->num_rows > 0;
}
