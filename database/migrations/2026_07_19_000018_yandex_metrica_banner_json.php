<?php
// Normalizes legacy serialized banner settings to OpenCart's JSON format.

require_once __DIR__ . '/../../upload/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$mysqli->set_charset('utf8mb4');
$mysqli->begin_transaction();

try {
	$result = $mysqli->query("SELECT setting_id, `value` FROM `" . DB_PREFIX . "setting` WHERE `key` = 'analytics_yandex_metrica_banner' AND serialized = 1");

	while ($row = $result->fetch_assoc()) {
		$value = json_decode($row['value'], true);

		if (is_array($value)) {
			continue;
		}

		$legacy_value = @unserialize($row['value']);
		$value = is_array($legacy_value) ? $legacy_value : array();
		$mysqli->query("UPDATE `" . DB_PREFIX . "setting` SET `value` = '" . $mysqli->real_escape_string(json_encode($value)) . "' WHERE setting_id = " . (int)$row['setting_id']);
	}

	$mysqli->commit();
	echo 'Yandex Metrica banner settings use JSON format.' . PHP_EOL;
} catch (Throwable $exception) {
	$mysqli->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}
