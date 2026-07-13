<?php

declare(strict_types=1);

require_once __DIR__ . '/../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$mysqli->set_charset('utf8mb4');

$languageId = getLanguageId($mysqli);
$requiredAttributes = array('Материалы', 'Размер', 'Цвет', 'Уход', 'Доставка и возврат', 'Alt основного фото');
$models = array('SYLORA-TEST-001', 'SYLORA-TEST-002', 'SYLORA-TEST-003', 'SYLORA-TEST-004', 'SYLORA-TEST-005', 'SYLORA-TEST-006');

foreach ($requiredAttributes as $attributeName) {
	assertTrue(attributeExists($mysqli, $languageId, $attributeName), 'Attribute exists: ' . $attributeName);
}

foreach ($models as $model) {
	$product = getProductFixture($mysqli, $languageId, $model);

	assertTrue($product['product_id'] > 0, 'Product fixture exists: ' . $model);
	assertTrue($product['name'] !== '', 'Product has name: ' . $model);
	assertTrue($product['description'] !== '', 'Product has description: ' . $model);
	assertTrue((float)$product['price'] > 0, 'Product has price: ' . $model);
	assertTrue((int)$product['quantity'] >= 0, 'Product has quantity: ' . $model);
	assertTrue((int)$product['stock_status_id'] > 0, 'Product has stock status: ' . $model);
	assertTrue((float)$product['weight'] >= 0, 'Product has weight value: ' . $model);
	assertTrue($product['meta_title'] !== '', 'Product has SEO Title: ' . $model);
	assertTrue($product['meta_description'] !== '', 'Product has SEO Description: ' . $model);
	assertTrue(hasSeoUrl($mysqli, $languageId, (int)$product['product_id']), 'Product has SEO URL: ' . $model);
	assertTrue(hasCategory($mysqli, (int)$product['product_id']), 'Product has category: ' . $model);
	assertTrue(hasRequiredAttributes($mysqli, $languageId, (int)$product['product_id'], $requiredAttributes), 'Product has required jewelry attributes: ' . $model);
	assertTrue(hasMainAlt($mysqli, $languageId, (int)$product['product_id']), 'Product has managed main image alt: ' . $model);
}

assertTrue(hasSpecialPrice($mysqli), 'At least one test product has special price');
assertTrue(stockStatusExists($mysqli, $languageId, 'Под заказ'), 'Stock status supports preorder');
assertTrue(adminSurfaceExists($mysqli, 'shipping', array('flat', 'cdek_official', 'russian_post')), 'Shipping modules are registered');
assertTrue(adminSurfaceExists($mysqli, 'payment', array('payment_service')), 'Payment service module is registered');

echo "Admin product data tests passed.\n";

function getLanguageId(mysqli $mysqli): int {
	$result = $mysqli->query("SELECT language_id FROM `language` WHERE code = 'ru-ru' LIMIT 1");

	if (!$result->num_rows) {
		throw new RuntimeException('Language ru-ru was not found.');
	}

	return (int)$result->fetch_assoc()['language_id'];
}

function attributeExists(mysqli $mysqli, int $languageId, string $name): bool {
	$escaped = $mysqli->real_escape_string($name);
	$result = $mysqli->query("SELECT 1 FROM attribute_description WHERE language_id = '" . $languageId . "' AND name = '" . $escaped . "' LIMIT 1");

	return $result->num_rows > 0;
}

function getProductFixture(mysqli $mysqli, int $languageId, string $model): array {
	$escaped = $mysqli->real_escape_string($model);
	$result = $mysqli->query(
		"SELECT p.product_id, p.price, p.quantity, p.stock_status_id, p.weight, pd.name, pd.description, pd.meta_title, pd.meta_description " .
		"FROM product p " .
		"JOIN product_description pd ON pd.product_id = p.product_id AND pd.language_id = '" . $languageId . "' " .
		"WHERE p.model = '" . $escaped . "' LIMIT 1"
	);

	if (!$result->num_rows) {
		return array(
			'product_id' => 0,
			'price' => 0,
			'quantity' => 0,
			'stock_status_id' => 0,
			'weight' => 0,
			'name' => '',
			'description' => '',
			'meta_title' => '',
			'meta_description' => '',
		);
	}

	return $result->fetch_assoc();
}

function hasSeoUrl(mysqli $mysqli, int $languageId, int $productId): bool {
	$result = $mysqli->query("SELECT 1 FROM seo_url WHERE store_id = 0 AND language_id = '" . $languageId . "' AND `query` = 'product_id=" . $productId . "' LIMIT 1");

	return $result->num_rows > 0;
}

function hasCategory(mysqli $mysqli, int $productId): bool {
	$result = $mysqli->query("SELECT 1 FROM product_to_category WHERE product_id = '" . $productId . "' LIMIT 1");

	return $result->num_rows > 0;
}

function hasRequiredAttributes(mysqli $mysqli, int $languageId, int $productId, array $requiredAttributes): bool {
	$names = array_map(static fn (string $name): string => "'" . $mysqli->real_escape_string($name) . "'", $requiredAttributes);
	$result = $mysqli->query(
		"SELECT COUNT(DISTINCT ad.name) AS total " .
		"FROM product_attribute pa " .
		"JOIN attribute_description ad ON ad.attribute_id = pa.attribute_id AND ad.language_id = pa.language_id " .
		"WHERE pa.product_id = '" . $productId . "' AND pa.language_id = '" . $languageId . "' AND pa.text <> '' AND ad.name IN (" . implode(',', $names) . ")"
	);
	$row = $result->fetch_assoc();

	return (int)$row['total'] === count($requiredAttributes);
}

function hasMainAlt(mysqli $mysqli, int $languageId, int $productId): bool {
	$result = $mysqli->query(
		"SELECT 1 FROM product_attribute pa " .
		"JOIN attribute_description ad ON ad.attribute_id = pa.attribute_id AND ad.language_id = pa.language_id " .
		"WHERE pa.product_id = '" . $productId . "' AND pa.language_id = '" . $languageId . "' AND ad.name = 'Alt основного фото' AND pa.text <> '' LIMIT 1"
	);

	return $result->num_rows > 0;
}

function hasSpecialPrice(mysqli $mysqli): bool {
	$result = $mysqli->query("SELECT 1 FROM product_special LIMIT 1");

	return $result->num_rows > 0;
}

function stockStatusExists(mysqli $mysqli, int $languageId, string $name): bool {
	$escaped = $mysqli->real_escape_string($name);
	$result = $mysqli->query("SELECT 1 FROM stock_status WHERE language_id = '" . $languageId . "' AND name = '" . $escaped . "' LIMIT 1");

	return $result->num_rows > 0;
}

function adminSurfaceExists(mysqli $mysqli, string $type, array $codes): bool {
	$escapedType = $mysqli->real_escape_string($type);
	$escapedCodes = array_map(static fn (string $code): string => "'" . $mysqli->real_escape_string($code) . "'", $codes);
	$result = $mysqli->query("SELECT COUNT(DISTINCT code) AS total FROM extension WHERE type = '" . $escapedType . "' AND code IN (" . implode(',', $escapedCodes) . ")");
	$row = $result->fetch_assoc();

	return (int)$row['total'] === count($codes);
}

function assertTrue(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}
