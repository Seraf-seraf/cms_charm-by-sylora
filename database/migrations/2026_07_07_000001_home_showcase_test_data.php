<?php
// Seeds local showcase products and generated test images for home page layout checks.

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$mysqli->set_charset('utf8mb4');

$language = $mysqli->query("SELECT language_id FROM `language` WHERE code = 'ru-ru' LIMIT 1");

if (!$language || !$language->num_rows) {
	fwrite(STDERR, "Language ru-ru was not found." . PHP_EOL);
	exit(1);
}

$language_id = (int)$language->fetch_assoc()['language_id'];
$image_dir = DIR_IMAGE . 'catalog/sylora/test-products/';

if (!is_dir($image_dir)) {
	mkdir($image_dir, 0775, true);
}

$products = array(
	array(
		'model' => 'SYLORA-TEST-001',
		'name' => 'Серьги Розовый рассвет',
		'category' => 'Серьги',
		'image' => 'catalog/sylora/test-products/earrings-portrait.png',
		'price' => 2400,
		'special' => 2100,
		'quantity' => 8,
		'viewed' => 62,
		'sort_order' => 1,
		'palette' => array('#f0c4d0', '#8ba37c', '#d7a85f')
	),
	array(
		'model' => 'SYLORA-TEST-002',
		'name' => 'Браслет Лесная нить с очень длинным названием для проверки переполнения карточки',
		'category' => 'Браслеты',
		'image' => 'catalog/sylora/test-products/bracelet-wide.png',
		'price' => 3100,
		'special' => false,
		'quantity' => 2,
		'viewed' => 48,
		'sort_order' => 2,
		'palette' => array('#805a43', '#b78c66', '#d9b28a')
	),
	array(
		'model' => 'SYLORA-TEST-003',
		'name' => 'Подвеска Тихий сад',
		'category' => 'Подвески',
		'image' => 'catalog/sylora/test-products/pendant-square.png',
		'price' => 1800,
		'special' => 1550,
		'quantity' => 0,
		'viewed' => 35,
		'sort_order' => 3,
		'palette' => array('#c7d5b8', '#efbac8', '#b07a50')
	),
	array(
		'model' => 'SYLORA-TEST-004',
		'name' => 'Колье Медовая ветвь',
		'category' => 'Колье',
		'image' => 'catalog/sylora/test-products/necklace-tall.png',
		'price' => 4200,
		'special' => false,
		'quantity' => 5,
		'viewed' => 74,
		'sort_order' => 4,
		'palette' => array('#f1d184', '#7f604b', '#22201d')
	),
	array(
		'model' => 'SYLORA-TEST-005',
		'name' => 'Комплект Пыльная роза',
		'category' => 'Комплекты',
		'image' => 'catalog/sylora/test-products/set-landscape.png',
		'price' => 5600,
		'special' => 4900,
		'quantity' => 12,
		'viewed' => 92,
		'sort_order' => 5,
		'palette' => array('#dda4b7', '#ead7cd', '#916c58')
	),
	array(
		'model' => 'SYLORA-TEST-006',
		'name' => 'Подарочный набор с несколькими украшениями и длинной подписью для проверки автослайдера',
		'category' => 'Подарки',
		'image' => 'catalog/sylora/test-products/gift-compact.png',
		'price' => 6900,
		'special' => false,
		'quantity' => 4,
		'viewed' => 66,
		'sort_order' => 6,
		'palette' => array('#23201e', '#d19a78', '#f1d6c4')
	)
);

foreach ($products as $product) {
	createTestImage(DIR_IMAGE . $product['image'], $product['palette'], $product['model']);
}

$mysqli->begin_transaction();

try {
	foreach ($products as $product) {
		$category_id = getCategoryId($mysqli, $language_id, $product['category']);
		$all_category_id = getCategoryId($mysqli, $language_id, 'Все украшения');

		$model = $mysqli->real_escape_string($product['model']);
		$product_result = $mysqli->query("SELECT product_id FROM product WHERE model = '" . $model . "' LIMIT 1");

		if ($product_result->num_rows) {
			$product_id = (int)$product_result->fetch_assoc()['product_id'];
		} else {
			$mysqli->query("INSERT INTO product SET model = '" . $model . "', sku = '', upc = '', ean = '', jan = '', isbn = '', mpn = '', location = '', stock_status_id = 6, manufacturer_id = 0, shipping = 1, points = 0, tax_class_id = 0, date_available = CURDATE(), weight = 0, weight_class_id = 1, length = 0, width = 0, height = 0, length_class_id = 1, subtract = 1, minimum = 1, status = 1, date_added = NOW(), date_modified = NOW()");
			$product_id = (int)$mysqli->insert_id;
		}

		$name = $mysqli->real_escape_string($product['name']);
		$description = $mysqli->real_escape_string('<p>Тестовое украшение для проверки главной витрины, автослайдера, длинных названий и разных пропорций изображений.</p>');
		$meta_title = $mysqli->real_escape_string($product['name'] . ' - Charm by Sylora');
		$meta_description = $mysqli->real_escape_string('Тестовая карточка Charm by Sylora для проверки витрины и SEO-подстановки данных товара.');
		$image = $mysqli->real_escape_string($product['image']);

		$mysqli->query("UPDATE product SET image = '" . $image . "', quantity = '" . (int)$product['quantity'] . "', price = '" . (float)$product['price'] . "', viewed = '" . (int)$product['viewed'] . "', sort_order = '" . (int)$product['sort_order'] . "', status = 1, date_added = DATE_SUB(NOW(), INTERVAL " . (int)$product['sort_order'] . " DAY), date_modified = NOW() WHERE product_id = '" . (int)$product_id . "'");
		$mysqli->query("INSERT INTO product_description SET product_id = '" . (int)$product_id . "', language_id = '" . (int)$language_id . "', name = '" . $name . "', description = '" . $description . "', tag = 'sylora,test', meta_title = '" . $meta_title . "', meta_description = '" . $meta_description . "', meta_keyword = '' ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), tag = VALUES(tag), meta_title = VALUES(meta_title), meta_description = VALUES(meta_description), meta_keyword = VALUES(meta_keyword)");
		$mysqli->query("INSERT IGNORE INTO product_to_store SET product_id = '" . (int)$product_id . "', store_id = 0");
		$mysqli->query("DELETE FROM product_to_category WHERE product_id = '" . (int)$product_id . "'");
		$mysqli->query("INSERT IGNORE INTO product_to_category SET product_id = '" . (int)$product_id . "', category_id = '" . (int)$all_category_id . "'");
		$mysqli->query("INSERT IGNORE INTO product_to_category SET product_id = '" . (int)$product_id . "', category_id = '" . (int)$category_id . "'");
		$mysqli->query("DELETE FROM product_special WHERE product_id = '" . (int)$product_id . "'");

		if ($product['special'] !== false) {
			$mysqli->query("INSERT INTO product_special SET product_id = '" . (int)$product_id . "', customer_group_id = 1, priority = 1, price = '" . (float)$product['special'] . "', date_start = '2026-01-01', date_end = '2036-12-31'");
		}

		$mysqli->query("DELETE FROM product_image WHERE product_id = '" . (int)$product_id . "'");
		$mysqli->query("INSERT INTO product_image SET product_id = '" . (int)$product_id . "', image = '" . $image . "', sort_order = 1");
	}

	$mysqli->commit();
	echo 'Home showcase test products are ready.' . PHP_EOL;
} catch (Throwable $exception) {
	$mysqli->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}

function getCategoryId(mysqli $mysqli, int $language_id, string $name): int {
	$escaped_name = $mysqli->real_escape_string($name);
	$result = $mysqli->query("SELECT category_id FROM category_description WHERE language_id = '" . (int)$language_id . "' AND name = '" . $escaped_name . "' LIMIT 1");

	if ($result->num_rows) {
		return (int)$result->fetch_assoc()['category_id'];
	}

	throw new RuntimeException("Category '" . $name . "' was not found. Run 2026_07_06_000002_jewelry_categories.php first.");
}

function createTestImage(string $path, array $palette, string $label): void {
	$width = 1200;
	$height = 1500;
	$image = imagecreatetruecolor($width, $height);

	$bg = allocateColor($image, $palette[0]);
	$accent = allocateColor($image, $palette[1]);
	$dark = allocateColor($image, $palette[2]);
	$white = imagecolorallocatealpha($image, 255, 255, 255, 30);

	imagefilledrectangle($image, 0, 0, $width, $height, $bg);

	for ($i = 0; $i < 9; $i++) {
		$offset = $i * 170 - 250;
		imagefilledellipse($image, $offset + 220, 320 + ($i % 3) * 210, 520, 180, $white);
		imageline($image, $offset, 0, $offset + 720, $height, $accent);
		imageline($image, $offset + 8, 0, $offset + 728, $height, $dark);
	}

	imagefilledellipse($image, 620, 760, 520, 520, $accent);
	imagefilledellipse($image, 620, 760, 360, 360, $bg);
	imagefilledellipse($image, 520, 640, 95, 95, $dark);
	imagefilledellipse($image, 720, 880, 120, 120, $dark);
	imagefilledellipse($image, 520, 640, 44, 44, $white);
	imagefilledellipse($image, 720, 880, 54, 54, $white);

	imagestring($image, 5, 42, $height - 58, $label, $dark);

	imagepng($image, $path, 8);
	imagedestroy($image);
}

function allocateColor($image, string $hex): int {
	$hex = ltrim($hex, '#');
	return imagecolorallocate($image, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
}
