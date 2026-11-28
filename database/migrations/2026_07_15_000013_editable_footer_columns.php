<?php

declare(strict_types=1);

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$db->set_charset('utf8mb4');
$prefix = DB_PREFIX;

$brand_items = array(
	array(
		'text' => 'Ручные украшения небольшими партиями: серьги, браслеты, подвески, колье и комплекты с бережной упаковкой.',
		'url' => '',
	),
	array('text' => 'Онлайн-оплата через платежный агрегатор.', 'url' => ''),
);
$payment_methods = getSettingValue($db, $prefix, 'config_footer_payment_methods');

if ($payment_methods !== '') {
	$brand_items[] = array('text' => $payment_methods, 'url' => '');
}

$information_items = array(
	array('text' => 'Политика конфиденциальности', 'url' => '/privacy-policy'),
	array('text' => 'Оферта', 'url' => '/offer'),
	array('text' => 'Карта сайта', 'url' => '/index.php?route=information/sitemap'),
);

foreach (array('telegram' => 'Telegram', 'vk' => 'VK') as $social => $label) {
	$url = getSettingValue($db, $prefix, 'config_footer_social_' . $social);

	if ($url !== '') {
		$information_items[] = array('text' => $label, 'url' => $url);
	}
}

$contact_items = array(
	array('text' => 'Написать нам', 'url' => '/contact'),
);
$telephone = getSettingValue($db, $prefix, 'config_telephone');

if ($telephone !== '') {
	$contact_items[] = array(
		'text' => $telephone,
		'url' => 'tel:' . preg_replace('/[^0-9+]/', '', $telephone),
	);
}

$email = getSettingValue($db, $prefix, 'config_email');

if ($email !== '') {
	$contact_items[] = array('text' => $email, 'url' => 'mailto:' . $email);
}

$address = getSettingValue($db, $prefix, 'config_address');

if ($address !== '') {
	$contact_items[] = array('text' => $address, 'url' => '');
}

$instagram = getSettingValue($db, $prefix, 'config_footer_social_instagram');

if ($instagram !== '' && count($contact_items) < 5) {
	$contact_items[] = array('text' => 'Instagram', 'url' => $instagram);
}

$columns = array(
	array('title' => 'Charm by Sylora', 'item_count' => count($brand_items), 'items' => $brand_items),
	array(
		'title' => 'Каталог',
		'item_count' => 4,
		'items' => array(
			array('text' => 'Главная', 'url' => '/'),
			array('text' => 'Каталог', 'url' => '/all-jewelry'),
			array('text' => 'Обо мне', 'url' => '/about'),
			array('text' => 'Корзина', 'url' => '/index.php?route=checkout/cart'),
		),
	),
	array(
		'title' => 'Покупателям',
		'item_count' => 5,
		'items' => array(
			array('text' => 'Доставка и оплата', 'url' => '/delivery-payment'),
			array('text' => 'Возврат и обмен', 'url' => '/returns'),
			array('text' => 'Уход за украшениями', 'url' => '/jewelry-care'),
			array('text' => 'Размеры и материалы', 'url' => '/sizes-materials'),
			array('text' => 'Подарочная упаковка', 'url' => '/gift-packaging'),
		),
	),
	array('title' => 'Информация', 'item_count' => count($information_items), 'items' => $information_items),
	array('title' => 'Контакты', 'item_count' => count($contact_items), 'items' => $contact_items),
);
$encoded_columns = json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (!is_string($encoded_columns)) {
	throw new RuntimeException('Не удалось подготовить настройки footer.');
}

$db->begin_transaction();

try {
	insertSettingIfMissing($db, $prefix, 'config', 'config_footer_column_count', '5', 0);
	insertSettingIfMissing($db, $prefix, 'config', 'config_footer_columns', $encoded_columns, 1);
	$db->commit();
	echo "Editable footer columns are configured.\n";
} catch (Throwable $exception) {
	$db->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}

function getSettingValue(mysqli $db, string $prefix, string $key): string {
	$table = escapeIdentifier($prefix . 'setting');
	$result = $db->query(
		"SELECT `value` FROM `" . $table . "` WHERE store_id = 0 AND `key` = '" .
		$db->real_escape_string($key) . "' LIMIT 1"
	);

	if (!$result->num_rows) {
		return '';
	}

	$row = $result->fetch_assoc();

	return isset($row['value']) ? trim((string)$row['value']) : '';
}

function insertSettingIfMissing(
	mysqli $db,
	string $prefix,
	string $code,
	string $key,
	string $value,
	int $serialized
): void {
	$table = escapeIdentifier($prefix . 'setting');
	$escaped_key = $db->real_escape_string($key);
	$result = $db->query(
		"SELECT setting_id FROM `" . $table . "` WHERE store_id = 0 AND `key` = '" . $escaped_key . "' LIMIT 1"
	);

	if ($result->num_rows) {
		return;
	}

	$db->query(
		"INSERT INTO `" . $table . "` SET store_id = 0, `code` = '" . $db->real_escape_string($code) .
		"', `key` = '" . $escaped_key . "', `value` = '" . $db->real_escape_string($value) .
		"', serialized = '" . $serialized . "'"
	);
}

function escapeIdentifier(string $identifier): string {
	return str_replace('`', '``', $identifier);
}
