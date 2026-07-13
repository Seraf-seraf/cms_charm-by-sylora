<?php

declare(strict_types=1);

require_once __DIR__ . '/../upload/config.php';
require_once __DIR__ . '/../upload/system/library/sylora_secret.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$db->set_charset('utf8mb4');

$secretKeys = array(
	'payment_payment_service_api_key',
	'payment_payment_service_shared_secret',
	'shipping_russian_post_token',
	'shipping_russian_post_login',
	'shipping_russian_post_password',
	'cdek_official__authSecret',
	'captcha_smartcaptcha_secret',
);

foreach ($secretKeys as $key) {
	$value = getSettingValue($db, $key);
	assertTrue($value === '' || SyloraSecret::isReference($value), 'Secret setting must be empty or env reference: ' . $key);
}

assertSameValue('0', getSettingValue($db, 'config_error_display'), 'Technical errors are hidden from users');
assertSameValue('1', getSettingValue($db, 'config_error_log'), 'Error logging remains enabled');
assertSameValue('resolved-secret', resolveWithEnv('SYLORA_SECURITY_TEST_SECRET', 'resolved-secret'), 'Env secret references are resolved');
assertSameValue('', SyloraSecret::resolve('env:invalid-name'), 'Invalid env reference is ignored');

echo "Security and email tests passed.\n";

function getSettingValue(mysqli $db, string $key): string {
	$escapedKey = $db->real_escape_string($key);
	$result = $db->query("SELECT value FROM `" . DB_PREFIX . "setting` WHERE store_id = 0 AND `key` = '" . $escapedKey . "' LIMIT 1");

	if (!$result->num_rows) {
		return '';
	}

	$row = $result->fetch_assoc();

	return (string)$row['value'];
}

function resolveWithEnv(string $name, string $value): string {
	putenv($name . '=' . $value);
	$resolved = SyloraSecret::resolve('env:' . $name);
	putenv($name);

	return $resolved;
}

function assertSameValue($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

function assertTrue(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}
