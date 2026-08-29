<?php

declare(strict_types=1);

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$db->set_charset('utf8mb4');
$prefix = DB_PREFIX;
$languageTable = escapeIdentifier($prefix . 'language');
$languageResult = $db->query("SELECT language_id FROM `" . $languageTable . "` WHERE code = 'ru-ru' LIMIT 1");

if (!$languageResult->num_rows) {
	throw new RuntimeException('Language ru-ru was not found.');
}

$languageId = (int)$languageResult->fetch_assoc()['language_id'];
$deliveryDescription = <<<'HTML'
	<h2>Доставка по России</h2>
	<p>Заказы Charm by Sylora отправляются по России. Основной подключенный способ доставки — СДЭК. Доступные для населенного пункта тарифы и варианты получения показываются после ввода адреса при оформлении заказа.</p>
	<h2>Стоимость и сроки</h2>
	<p>Фиксированной стоимости и единого срока для всей России нет: СДЭК рассчитывает их по адресу получателя и параметрам заказа. Итоговая стоимость и ориентировочный срок отображаются до оплаты и включаются в сумму заказа. Если бесплатная доставка не указана в корзине или условиях акции, доставку оплачивает покупатель.</p>
	<p>После передачи заказа перевозчику покупателю направляется информация для отслеживания. Срок подготовки или изготовления изделия, если он требуется, указывается в карточке товара либо согласовывается до оплаты.</p>
	<h2>Упаковка и получение</h2>
	<p>Украшение отправляется в защитной упаковке. Если при получении заметно повреждение отправления, его нужно зафиксировать вместе с представителем службы доставки и сообщить магазину.</p>
	<h2>Оплата</h2>
	<p>Оплата заказа выполняется онлайн банковской картой через платежный сервис. Магазин не принимает и не хранит данные банковских карт.</p>
	<p>Заказ считается принятым в обработку после подтверждения состава заказа и успешной оплаты, если иной порядок не указан при оформлении.</p>
	<h2>Если возникла проблема</h2>
	<p>Напишите на <a href="mailto:info@charm-by-sylora.ru">info@charm-by-sylora.ru</a> и укажите номер заказа, ФИО покупателя и описание ситуации.</p>
	HTML;

$db->begin_transaction();

try {
	upsertSetting($db, $prefix, 'config_meta_title', 'Авторские украшения ручной работы — Charm by Sylora');
	upsertSetting($db, $prefix, 'config_meta_description', 'Авторские украшения ручной работы небольшими партиями. Колье, брелоки и другие изделия с бережной упаковкой и доставкой по России.');
	updateDeliveryPage($db, $prefix, $languageId, $deliveryDescription);
	$db->commit();
	echo 'SEO metadata and delivery page are updated.' . PHP_EOL;
} catch (Throwable $exception) {
	$db->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}

function upsertSetting(mysqli $db, string $prefix, string $key, string $value): void {
	$table = escapeIdentifier($prefix . 'setting');
	$escapedKey = $db->real_escape_string($key);
	$escapedValue = $db->real_escape_string($value);
	$result = $db->query("SELECT setting_id FROM `" . $table . "` WHERE store_id = 0 AND `key` = '" . $escapedKey . "' LIMIT 1");

	if ($result->num_rows) {
		$settingId = (int)$result->fetch_assoc()['setting_id'];
		$db->query("UPDATE `" . $table . "` SET `code` = 'config', `value` = '" . $escapedValue . "', serialized = 0 WHERE setting_id = '" . $settingId . "'");
		return;
	}

	$db->query("INSERT INTO `" . $table . "` SET store_id = 0, `code` = 'config', `key` = '" . $escapedKey . "', `value` = '" . $escapedValue . "', serialized = 0");
}

function updateDeliveryPage(mysqli $db, string $prefix, int $languageId, string $description): void {
	$seoTable = escapeIdentifier($prefix . 'seo_url');
	$descriptionTable = escapeIdentifier($prefix . 'information_description');
	$result = $db->query(
		"SELECT `query` FROM `" . $seoTable . "` WHERE store_id = 0 AND language_id = '" . $languageId .
		"' AND keyword = 'delivery-payment' LIMIT 1"
	);

	if (!$result->num_rows) {
		throw new RuntimeException('Delivery page SEO URL was not found.');
	}

	$query = (string)$result->fetch_assoc()['query'];

	if (!preg_match('/^information_id=(\d+)$/', $query, $matches)) {
		throw new RuntimeException('Delivery page SEO URL has an unexpected target.');
	}

	$informationId = (int)$matches[1];
	$escapedDescription = $db->real_escape_string($description);
	$metaTitle = $db->real_escape_string('Доставка и оплата украшений | Charm by Sylora');
	$metaDescription = $db->real_escape_string('Доставка украшений Charm by Sylora по России через СДЭК: расчет стоимости и срока до оплаты, отслеживание заказа и безопасная онлайн-оплата.');
	$db->query(
		"UPDATE `" . $descriptionTable . "` SET description = '" . $escapedDescription . "', meta_title = '" .
		$metaTitle . "', meta_description = '" . $metaDescription . "' WHERE information_id = '" . $informationId .
		"' AND language_id = '" . $languageId . "'"
	);

	if ($db->affected_rows < 1) {
		$exists = $db->query(
			"SELECT information_id FROM `" . $descriptionTable . "` WHERE information_id = '" . $informationId .
			"' AND language_id = '" . $languageId . "' LIMIT 1"
		);

		if (!$exists->num_rows) {
			throw new RuntimeException('Delivery page description was not found.');
		}
	}
}

function escapeIdentifier(string $identifier): string {
	return str_replace('`', '``', $identifier);
}
