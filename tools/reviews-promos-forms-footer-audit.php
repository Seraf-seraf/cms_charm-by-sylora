<?php

declare(strict_types=1);

require_once __DIR__ . '/../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = dirname(__DIR__);
$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$db->set_charset('utf8mb4');

$css = readFileContent($root . '/upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
$footerController = readFileContent($root . '/upload/catalog/controller/common/footer.php');
$footerTemplate = readFileContent($root . '/upload/catalog/view/theme/charm_by_sylora/template/common/footer.twig');
$settingsController = readFileContent($root . '/upload/admin/controller/setting/setting.php');
$settingsTemplate = readFileContent($root . '/upload/admin/view/template/setting/setting.twig');
$reviewModel = readFileContent($root . '/upload/catalog/model/catalog/review.php');
$productController = readFileContent($root . '/upload/catalog/controller/product/product.php');

$checks = array(
	checkSetting($db, 'config_review_status', '1', 'Отзывы', 'отзывы включены'),
	checkSetting($db, 'config_review_guest', '1', 'Отзывы гостей', 'гостевые отзывы разрешены'),
	checkContains($reviewModel, 'date_added = NOW()', 'Модерация отзывов', 'новые отзывы создаются без status=1 и ожидают модерации'),
	checkContains($productController, "in_array('review'", 'Антиспам отзывов', 'страница отзывов подключена к captcha-проверке'),
	checkSetting($db, 'config_captcha', 'smartcaptcha', 'Captcha', 'SmartCaptcha выбрана как активная капча'),
	checkSerializedPages($db),
	checkSetting($db, 'total_coupon_status', '0', 'Промокоды', 'модуль промокодов отключен'),
	checkExtensionMissing($db, 'total', 'coupon', 'Промокоды', 'total/coupon не установлен в списке расширений'),
	checkContains($css, '.btn-default:active', 'Кнопки: active', 'active-состояние задано для обычных кнопок'),
	checkContains($css, '.btn[disabled]', 'Кнопки: disabled', 'disabled-состояние задано для кнопок'),
	checkContains($css, '.btn[data-loading-text].disabled::after', 'Кнопки: loading', 'loading-состояние получает spinner'),
	checkContains($css, '[id^="account-"] #content', 'Формы аккаунта', 'формы аккаунта получают общий card-style'),
	checkContains($css, '[id^="affiliate-"] #content', 'Служебные формы', 'служебные affiliate-формы получают общий card-style'),
	checkContains($css, '.form-control:focus', 'Формы: focus', 'поля форм имеют единый focus-state'),
	checkContains($settingsController, 'config_footer_social_', 'Footer: настройки', 'соцсети footer читаются и сохраняются через настройки магазина'),
	checkContains($settingsTemplate, 'config_footer_payment_methods', 'Footer: админка', 'способы оплаты доступны в настройках магазина'),
	checkContains($footerController, 'getSocialLinks', 'Footer: соцсети', 'footer получает управляемые соцсети'),
	checkContains($footerTemplate, 'social_links', 'Footer: шаблон соцсетей', 'footer выводит соцсети при заполнении'),
	checkContains($footerTemplate, 'payment_methods', 'Footer: способы оплаты', 'footer выводит способы оплаты при заполнении'),
	checkContains($footerTemplate, 'privacy', 'Footer: юридические ссылки', 'footer содержит юридические ссылки'),
);

$failed = false;

foreach ($checks as $check) {
	$status = $check['ok'] ? 'OK' : 'FAIL';
	printf("[%s] %s: %s\n", $status, $check['name'], $check['message']);

	if (!$check['ok']) {
		$failed = true;
	}
}

exit($failed ? 1 : 0);

function checkSetting(mysqli $db, string $key, string $expected, string $name, string $okMessage): array {
	$value = getSetting($db, $key);
	$ok = $value === $expected;

	return array(
		'name' => $name,
		'ok' => $ok,
		'message' => $ok ? $okMessage : sprintf('%s: expected %s, got %s', $key, var_export($expected, true), var_export($value, true)),
	);
}

function checkSerializedPages(mysqli $db): array {
	$pages = json_decode(getSetting($db, 'config_captcha_page'), true);

	if (!is_array($pages)) {
		return array(
			'name' => 'Captcha-страницы',
			'ok' => false,
			'message' => 'config_captcha_page не является JSON-массивом',
		);
	}

	sort($pages);
	$expected = array('contact', 'guest', 'register', 'return', 'review');

	return array(
		'name' => 'Captcha-страницы',
		'ok' => $pages === $expected,
		'message' => $pages === $expected ? 'captcha покрывает register, guest, review, return и contact' : 'неверный набор: ' . json_encode($pages, JSON_UNESCAPED_UNICODE),
	);
}

function checkExtensionMissing(mysqli $db, string $type, string $code, string $name, string $okMessage): array {
	$result = $db->query(
		"SELECT 1 FROM `" . DB_PREFIX . "extension` WHERE `type` = '" .
		$db->real_escape_string($type) . "' AND `code` = '" . $db->real_escape_string($code) . "' LIMIT 1"
	);
	$ok = $result->num_rows === 0;

	return array(
		'name' => $name,
		'ok' => $ok,
		'message' => $ok ? $okMessage : $type . '/' . $code . ' всё еще установлен',
	);
}

function checkContains(string $content, string $needle, string $name, string $okMessage): array {
	$ok = str_contains($content, $needle);

	return array(
		'name' => $name,
		'ok' => $ok,
		'message' => $ok ? $okMessage : 'не найдено: ' . $needle,
	);
}

function getSetting(mysqli $db, string $key): string {
	$result = $db->query("SELECT value FROM `" . DB_PREFIX . "setting` WHERE store_id = 0 AND `key` = '" . $db->real_escape_string($key) . "' LIMIT 1");

	if (!$result->num_rows) {
		return '';
	}

	$row = $result->fetch_assoc();

	return (string)$row['value'];
}

function readFileContent(string $path): string {
	$content = is_file($path) ? file_get_contents($path) : false;

	if (!is_string($content)) {
		throw new RuntimeException('Cannot read file: ' . $path);
	}

	return $content;
}
