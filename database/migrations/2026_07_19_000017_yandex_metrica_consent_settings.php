<?php
// Adds configurable cookie-consent fields to the Yandex Metrica extension.

require_once __DIR__ . '/../../upload/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$mysqli->set_charset('utf8mb4');
$mysqli->begin_transaction();

try {
	$privacy_information_id = 0;
	$privacy_result = $mysqli->query("SELECT `query` FROM `" . DB_PREFIX . "seo_url` WHERE `keyword` = 'privacy-policy' LIMIT 1");

	if ($privacy_result->num_rows) {
		$privacy_row = $privacy_result->fetch_assoc();

		if (isset($privacy_row['query']) && preg_match('/^information_id=(\d+)$/', $privacy_row['query'], $matches)) {
			$privacy_information_id = (int)$matches[1];
		}
	}

	$defaults = array(
		'analytics_yandex_metrica_cookie_days' => array('value' => '365', 'serialized' => 0),
		'analytics_yandex_metrica_privacy_information_id' => array('value' => (string)$privacy_information_id, 'serialized' => 0),
		'analytics_yandex_metrica_banner' => array('value' => serialize(array()), 'serialized' => 1)
	);

	foreach ($defaults as $key => $setting) {
		$escaped_key = $mysqli->real_escape_string($key);
		$exists = $mysqli->query("SELECT setting_id FROM `" . DB_PREFIX . "setting` WHERE store_id = 0 AND `key` = '" . $escaped_key . "' LIMIT 1");

		if (!$exists->num_rows) {
			$mysqli->query("INSERT INTO `" . DB_PREFIX . "setting` SET store_id = 0, `code` = 'analytics_yandex_metrica', `key` = '" . $escaped_key . "', `value` = '" . $mysqli->real_escape_string($setting['value']) . "', serialized = " . (int)$setting['serialized']);
		}
	}

	$mysqli->commit();
	echo 'Yandex Metrica consent settings are registered.' . PHP_EOL;
} catch (Throwable $exception) {
	$mysqli->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}
