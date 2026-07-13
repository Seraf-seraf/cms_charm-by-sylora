<?php

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$db->set_charset('utf8mb4');
$prefix = DB_PREFIX;

registerExtension($db, $prefix);
upsertSetting($db, $prefix, 'captcha_smartcaptcha', 'captcha_smartcaptcha_key', '');
upsertSetting($db, $prefix, 'captcha_smartcaptcha', 'captcha_smartcaptcha_secret', 'env:YANDEX_SMARTCAPTCHA_SECRET');
upsertSetting($db, $prefix, 'captcha_smartcaptcha', 'captcha_smartcaptcha_status', '0');
upsertSetting($db, $prefix, 'config', 'config_captcha', 'smartcaptcha');
upsertSetting($db, $prefix, 'config', 'config_captcha_page', json_encode(array('register', 'guest', 'review', 'return', 'contact')), 1);

echo "Yandex SmartCaptcha extension is registered.\n";

function registerExtension(mysqli $db, string $prefix): void {
	$table = escapeIdentifier($prefix . 'extension');
	$result = $db->query("SELECT extension_id FROM `" . $table . "` WHERE `type` = 'captcha' AND `code` = 'smartcaptcha' LIMIT 1");

	if (!$result->num_rows) {
		$db->query("INSERT INTO `" . $table . "` SET `type` = 'captcha', `code` = 'smartcaptcha'");
	}
}

function upsertSetting(mysqli $db, string $prefix, string $code, string $key, string $value, int $serialized = 0): void {
	$table = escapeIdentifier($prefix . 'setting');
	$escapedKey = $db->real_escape_string($key);
	$result = $db->query("SELECT setting_id FROM `" . $table . "` WHERE store_id = 0 AND `key` = '" . $escapedKey . "' LIMIT 1");

	if ($result->num_rows) {
		$row = $result->fetch_assoc();
		$db->query("UPDATE `" . $table . "` SET `code` = '" . $db->real_escape_string($code) . "', `value` = '" . $db->real_escape_string($value) . "', serialized = '" . $serialized . "' WHERE setting_id = '" . (int)$row['setting_id'] . "'");
		return;
	}

	$db->query("INSERT INTO `" . $table . "` SET store_id = 0, `code` = '" . $db->real_escape_string($code) . "', `key` = '" . $escapedKey . "', `value` = '" . $db->real_escape_string($value) . "', serialized = '" . $serialized . "'");
}

function escapeIdentifier(string $identifier): string {
	return str_replace('`', '``', $identifier);
}
