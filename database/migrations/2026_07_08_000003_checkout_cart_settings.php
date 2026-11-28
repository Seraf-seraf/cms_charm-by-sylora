<?php
// Enables cart discount modules, guest checkout and required checkout agreements.

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$mysqli->set_charset('utf8mb4');

$total_extensions = array('coupon', 'voucher', 'reward', 'shipping', 'sub_total', 'total');
$settings = array(
	'config_checkout_guest' => '1',
	'config_account_id' => (string)getInformationId($mysqli, array('Privacy Policy', 'Политика конфиденциальности'), 3),
	'config_checkout_id' => (string)getInformationId($mysqli, array('Terms &amp; Conditions', 'Terms & Conditions', 'Условия соглашения', 'Оферта'), 5),
	'total_coupon_status' => '1',
	'total_coupon_sort_order' => '4',
	'total_voucher_status' => '1',
	'total_voucher_sort_order' => '5',
	'total_reward_status' => '1',
	'total_reward_sort_order' => '6',
	'total_shipping_status' => '1',
	'total_shipping_estimator' => '1',
	'total_shipping_sort_order' => '2'
);

$mysqli->begin_transaction();

try {
	foreach ($total_extensions as $code) {
		$escaped_code = $mysqli->real_escape_string($code);
		$result = $mysqli->query("SELECT extension_id FROM `extension` WHERE `type` = 'total' AND `code` = '" . $escaped_code . "' LIMIT 1");

		if (!$result->num_rows) {
			$mysqli->query("INSERT INTO `extension` SET `type` = 'total', `code` = '" . $escaped_code . "'");
		}
	}

	foreach ($settings as $key => $value) {
		$code = getSettingCode($key);
		upsertSetting($mysqli, 0, $code, $key, $value);
	}

	$mysqli->commit();
	echo 'Checkout and cart settings are ready.' . PHP_EOL;
} catch (Throwable $exception) {
	$mysqli->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}

function getInformationId(mysqli $mysqli, array $titles, int $fallback): int {
	foreach ($titles as $title) {
		$escaped_title = $mysqli->real_escape_string($title);
		$result = $mysqli->query("SELECT information_id FROM information_description WHERE title = '" . $escaped_title . "' ORDER BY language_id LIMIT 1");

		if ($result->num_rows) {
			return (int)$result->fetch_assoc()['information_id'];
		}
	}

	return $fallback;
}

function getSettingCode(string $key): string {
	if (strpos($key, 'total_coupon_') === 0) {
		return 'total_coupon';
	}

	if (strpos($key, 'total_voucher_') === 0) {
		return 'total_voucher';
	}

	if (strpos($key, 'total_reward_') === 0) {
		return 'total_reward';
	}

	if (strpos($key, 'total_shipping_') === 0) {
		return 'total_shipping';
	}

	return 'config';
}

function upsertSetting(mysqli $mysqli, int $store_id, string $code, string $key, string $value): void {
	$escaped_code = $mysqli->real_escape_string($code);
	$escaped_key = $mysqli->real_escape_string($key);
	$escaped_value = $mysqli->real_escape_string($value);

	$mysqli->query("DELETE FROM `setting` WHERE store_id = '" . (int)$store_id . "' AND `code` = '" . $escaped_code . "' AND `key` = '" . $escaped_key . "'");
	$mysqli->query("INSERT INTO `setting` SET store_id = '" . (int)$store_id . "', `code` = '" . $escaped_code . "', `key` = '" . $escaped_key . "', `value` = '" . $escaped_value . "', serialized = 0");
}
