<?php

declare(strict_types=1);

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$db->set_charset('utf8mb4');
$prefix = DB_PREFIX;

$settings = array(
	'config_review_status' => array('config', '1'),
	'config_review_guest' => array('config', '1'),
	'config_captcha' => array('config', 'smartcaptcha'),
	'config_captcha_page' => array('config', json_encode(array('register', 'guest', 'review', 'return', 'contact')), 1),
	'total_coupon_status' => array('total_coupon', '0'),
	'total_coupon_sort_order' => array('total_coupon', '4'),
	'config_footer_social_telegram' => array('config', ''),
	'config_footer_social_vk' => array('config', ''),
	'config_footer_social_instagram' => array('config', ''),
	'config_footer_payment_methods' => array('config', ''),
);

$db->begin_transaction();

try {
	registerExtension($db, $prefix, 'captcha', 'smartcaptcha');
	unregisterExtension($db, $prefix, 'total', 'coupon');

	foreach ($settings as $key => $setting) {
		upsertSetting($db, $prefix, $setting[0], $key, $setting[1], $setting[2] ?? 0);
	}

	$db->commit();
	echo "Review, promo and footer defaults are applied.\n";
} catch (Throwable $exception) {
	$db->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}

function registerExtension(mysqli $db, string $prefix, string $type, string $code): void {
	$table = escapeIdentifier($prefix . 'extension');
	$escapedType = $db->real_escape_string($type);
	$escapedCode = $db->real_escape_string($code);
	$result = $db->query("SELECT extension_id FROM `" . $table . "` WHERE `type` = '" . $escapedType . "' AND `code` = '" . $escapedCode . "' LIMIT 1");

	if (!$result->num_rows) {
		$db->query("INSERT INTO `" . $table . "` SET `type` = '" . $escapedType . "', `code` = '" . $escapedCode . "'");
	}
}

function unregisterExtension(mysqli $db, string $prefix, string $type, string $code): void {
	$table = escapeIdentifier($prefix . 'extension');
	$db->query(
		"DELETE FROM `" . $table . "` WHERE `type` = '" .
		$db->real_escape_string($type) . "' AND `code` = '" . $db->real_escape_string($code) . "'"
	);
}

function upsertSetting(mysqli $db, string $prefix, string $code, string $key, string $value, int $serialized = 0): void {
	$table = escapeIdentifier($prefix . 'setting');
	$escapedKey = $db->real_escape_string($key);
	$result = $db->query("SELECT setting_id FROM `" . $table . "` WHERE store_id = 0 AND `key` = '" . $escapedKey . "' LIMIT 1");

	if ($result->num_rows) {
		$row = $result->fetch_assoc();
		$db->query(
			"UPDATE `" . $table . "` SET `code` = '" . $db->real_escape_string($code) .
			"', `value` = '" . $db->real_escape_string($value) .
			"', serialized = '" . $serialized . "' WHERE setting_id = '" . (int)$row['setting_id'] . "'"
		);
		return;
	}

	$db->query(
		"INSERT INTO `" . $table . "` SET store_id = 0, `code` = '" . $db->real_escape_string($code) .
		"', `key` = '" . $escapedKey . "', `value` = '" . $db->real_escape_string($value) .
		"', serialized = '" . $serialized . "'"
	);
}

function escapeIdentifier(string $identifier): string {
	return str_replace('`', '``', $identifier);
}
