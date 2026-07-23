<?php

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$db->set_charset('utf8mb4');
$prefix = DB_PREFIX;

upsertSetting($db, $prefix, 'config', 'config_error_display', '0');
upsertSetting($db, $prefix, 'config', 'config_error_log', '1');
upsertSetting($db, $prefix, 'config', 'config_login_attempts', '5');

$secretReferences = array(
	'payment_payment_service_api_key' => array('payment_payment_service', 'env:PAYMENT_SERVICE_API_KEY'),
	'payment_payment_service_shared_secret' => array('payment_payment_service', 'env:PAYMENT_SERVICE_SHARED_SECRET'),
);

foreach ($secretReferences as $key => $setting) {
	normalizeSecretReference($db, $prefix, $setting[0], $key, $setting[1]);
}

echo "Security and email-safe defaults are applied.\n";

function upsertSetting(mysqli $db, string $prefix, string $code, string $key, string $value): void {
	$table = escapeIdentifier($prefix . 'setting');
	$escapedCode = $db->real_escape_string($code);
	$escapedKey = $db->real_escape_string($key);
	$escapedValue = $db->real_escape_string($value);

	$result = $db->query("SELECT setting_id FROM `" . $table . "` WHERE store_id = 0 AND `key` = '" . $escapedKey . "' LIMIT 1");

	if ($result->num_rows) {
		$row = $result->fetch_assoc();
		$db->query("UPDATE `" . $table . "` SET `code` = '" . $escapedCode . "', `value` = '" . $escapedValue . "', serialized = 0 WHERE setting_id = '" . (int)$row['setting_id'] . "'");
		return;
	}

	$db->query("INSERT INTO `" . $table . "` SET store_id = 0, `code` = '" . $escapedCode . "', `key` = '" . $escapedKey . "', `value` = '" . $escapedValue . "', serialized = 0");
}

function normalizeSecretReference(mysqli $db, string $prefix, string $code, string $key, string $reference): void {
	$table = escapeIdentifier($prefix . 'setting');
	$escapedKey = $db->real_escape_string($key);
	$result = $db->query("SELECT setting_id, value FROM `" . $table . "` WHERE store_id = 0 AND `key` = '" . $escapedKey . "' LIMIT 1");

	if (!$result->num_rows) {
		upsertSetting($db, $prefix, $code, $key, '');
		return;
	}

	$row = $result->fetch_assoc();
	$value = trim((string)$row['value']);

	if ($value === '' || isEnvReference($value)) {
		return;
	}

	$db->query("UPDATE `" . $table . "` SET `code` = '" . $db->real_escape_string($code) . "', `value` = '" . $db->real_escape_string($reference) . "', serialized = 0 WHERE setting_id = '" . (int)$row['setting_id'] . "'");
}

function isEnvReference(string $value): bool {
	return preg_match('/^env:[A-Z][A-Z0-9_]{1,127}$/', $value) === 1;
}

function escapeIdentifier(string $identifier): string {
	return str_replace('`', '``', $identifier);
}
