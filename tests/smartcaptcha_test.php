<?php

declare(strict_types=1);

require_once __DIR__ . '/../upload/config.php';
require_once __DIR__ . '/../upload/system/library/sylora_secret.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$db->set_charset('utf8mb4');

assertTrue(extensionExists($db), 'SmartCaptcha extension is registered');
assertSameValue('smartcaptcha', getSettingValue($db, 'config_captcha'), 'SmartCaptcha is selected as captcha provider');
assertSameValue('env:YANDEX_SMARTCAPTCHA_SECRET', getSettingValue($db, 'captcha_smartcaptcha_secret'), 'SmartCaptcha server key is stored as env reference');
assertTrue(SyloraSecret::isReference(getSettingValue($db, 'captcha_smartcaptcha_secret')), 'SmartCaptcha server key is not stored raw');

$pages = json_decode(getSettingValue($db, 'config_captcha_page'), true);
sort($pages);
assertSameValue(array('contact', 'guest', 'register', 'return', 'review'), $pages, 'SmartCaptcha covers all OpenCart captcha pages');

$catalogController = readFileContent(__DIR__ . '/../upload/catalog/controller/extension/captcha/smartcaptcha.php');
assertContains($catalogController, 'smart-token', 'SmartCaptcha validates smart-token');
assertContains($catalogController, 'https://smartcaptcha.cloud.yandex.ru/validate', 'SmartCaptcha uses official validation endpoint');
assertContains($catalogController, 'SyloraSecret::resolve', 'SmartCaptcha resolves server key from env reference');

$template = readFileContent(__DIR__ . '/../upload/catalog/view/theme/charm_by_sylora/template/extension/captcha/smartcaptcha.twig');
assertContains($template, 'https://smartcaptcha.cloud.yandex.ru/captcha.js?render=onload', 'SmartCaptcha widget script is loaded');
assertContains($template, 'window.smartCaptcha.render', 'SmartCaptcha widget is rendered');
assertContains($template, 'data-sitekey="{{ site_key }}"', 'SmartCaptcha receives client key');

echo "SmartCaptcha tests passed.\n";

function extensionExists(mysqli $db): bool {
	$result = $db->query("SELECT 1 FROM `" . DB_PREFIX . "extension` WHERE `type` = 'captcha' AND `code` = 'smartcaptcha' LIMIT 1");

	return $result->num_rows > 0;
}

function getSettingValue(mysqli $db, string $key): string {
	$result = $db->query("SELECT value FROM `" . DB_PREFIX . "setting` WHERE store_id = 0 AND `key` = '" . $db->real_escape_string($key) . "' LIMIT 1");

	if (!$result->num_rows) {
		return '';
	}

	$row = $result->fetch_assoc();

	return (string)$row['value'];
}

function readFileContent(string $path): string {
	$content = file_get_contents($path);

	if (!is_string($content)) {
		throw new RuntimeException('Cannot read file: ' . $path);
	}

	return $content;
}

function assertContains(string $haystack, string $needle, string $message): void {
	if (strpos($haystack, $needle) === false) {
		throw new RuntimeException($message);
	}
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
