<?php
// Registers the configurable Yandex Metrica analytics extension.

require_once __DIR__ . '/../../upload/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$mysqli->set_charset('utf8mb4');
$mysqli->begin_transaction();

try {
	$result = $mysqli->query("SELECT extension_id FROM extension WHERE `type` = 'analytics' AND `code` = 'yandex_metrica' LIMIT 1");
	if (!$result->num_rows) $mysqli->query("INSERT INTO extension SET `type` = 'analytics', `code` = 'yandex_metrica'");

	$defaults = array('analytics_yandex_metrica_counter' => '', 'analytics_yandex_metrica_webvisor' => '0', 'analytics_yandex_metrica_ecommerce' => '1', 'analytics_yandex_metrica_status' => '0');
	foreach ($defaults as $key => $value) {
		$key = $mysqli->real_escape_string($key);
		$exists = $mysqli->query("SELECT setting_id FROM setting WHERE store_id = 0 AND `code` = 'analytics_yandex_metrica' AND `key` = '" . $key . "' LIMIT 1");
		if (!$exists->num_rows) $mysqli->query("INSERT INTO setting SET store_id = 0, `code` = 'analytics_yandex_metrica', `key` = '" . $key . "', `value` = '" . $mysqli->real_escape_string($value) . "', serialized = 0");
	}

	$mysqli->commit();
	echo 'Yandex Metrica analytics extension is registered.' . PHP_EOL;
} catch (Throwable $exception) {
	$mysqli->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}
