<?php

declare(strict_types=1);

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$db->set_charset('utf8mb4');
$prefix = DB_PREFIX;
$languageResult = $db->query("SELECT language_id FROM `" . escapeIdentifier($prefix . "language") . "` WHERE code = 'ru-ru' LIMIT 1");

if (!$languageResult->num_rows) {
	throw new RuntimeException('Language ru-ru was not found.');
}

$languageId = (int)$languageResult->fetch_assoc()['language_id'];
$db->begin_transaction();

try {
	$categoryId = ensureCatalogCategory($db, $prefix, $languageId);
	assignActiveProducts($db, $prefix, $categoryId);

	$settings = array(
		'config_address' => "117623, Россия, г. Москва\nул. 1-я Мелитопольская, д. 8, кв. 221",
		'config_email' => 'info@charm-by-sylora.ru',
		'config_telephone' => '+7 951 413-56-59',
		'config_geocode' => '',
		'config_sylora_region' => 'Москва',
		'config_sylora_legal_name' => 'Индивидуальный предприниматель Глава крестьянского (фермерского) хозяйства Кравчук Серафим Сергеевич',
		'config_sylora_tax_id' => '771377623413',
		'config_sylora_registration_id' => 'ОГРНИП 325774600851993',
		'config_sylora_street_address' => 'ул. 1-я Мелитопольская, д. 8, кв. 221',
		'config_sylora_address_locality' => 'Москва',
		'config_sylora_postal_code' => '117623',
		'config_sylora_legal_info' => "ИП Глава КФХ Кравчук Серафим Сергеевич\nИНН 771377623413\nОГРНИП 325774600851993"
	);

	foreach ($settings as $key => $value) {
		upsertSetting($db, $prefix, $key, $value);
	}

	$db->commit();
	echo 'SEO catalog and online store settings are ready.' . PHP_EOL;
} catch (Throwable $exception) {
	$db->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}

function ensureCatalogCategory(mysqli $db, string $prefix, int $languageId): int {
	$categoryTable = escapeIdentifier($prefix . 'category');
	$descriptionTable = escapeIdentifier($prefix . 'category_description');
	$storeTable = escapeIdentifier($prefix . 'category_to_store');
	$pathTable = escapeIdentifier($prefix . 'category_path');
	$seoTable = escapeIdentifier($prefix . 'seo_url');
	$result = $db->query("SELECT category_id FROM `" . $descriptionTable . "` WHERE language_id = '" . $languageId . "' AND name = 'Все украшения' ORDER BY category_id LIMIT 1");

	if ($result->num_rows) {
		$categoryId = (int)$result->fetch_assoc()['category_id'];
	} else {
		$db->query("INSERT INTO `" . $categoryTable . "` SET image = '', parent_id = 0, top = 1, `column` = 1, sort_order = 1, status = 1, date_added = NOW(), date_modified = NOW()");
		$categoryId = (int)$db->insert_id;
	}

	$db->query("UPDATE `" . $categoryTable . "` SET parent_id = 0, top = 1, `column` = 1, sort_order = 1, status = 1, date_modified = NOW() WHERE category_id = '" . $categoryId . "'");
	$db->query("INSERT INTO `" . $descriptionTable . "` SET category_id = '" . $categoryId . "', language_id = '" . $languageId . "', name = 'Все украшения', description = 'Полный каталог авторских украшений Charm by Sylora: колье, брелоки и другие изделия ручной работы с доставкой по России.', meta_title = 'Все украшения ручной работы — Charm by Sylora', meta_description = 'Полный каталог украшений ручной работы Charm by Sylora: актуальные цены, наличие, материалы, бережная упаковка и доставка по России.', meta_keyword = '' ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), meta_title = VALUES(meta_title), meta_description = VALUES(meta_description)");
	$db->query("INSERT IGNORE INTO `" . $storeTable . "` SET category_id = '" . $categoryId . "', store_id = 0");
	$db->query("DELETE FROM `" . $pathTable . "` WHERE category_id = '" . $categoryId . "'");
	$db->query("INSERT INTO `" . $pathTable . "` SET category_id = '" . $categoryId . "', path_id = '" . $categoryId . "', level = 0");

	$query = $db->real_escape_string('category_id=' . $categoryId);
	$db->query("DELETE FROM `" . $seoTable . "` WHERE store_id = 0 AND language_id = '" . $languageId . "' AND (`query` = '" . $query . "' OR keyword = 'all-jewelry')");
	$db->query("INSERT INTO `" . $seoTable . "` SET store_id = 0, language_id = '" . $languageId . "', `query` = '" . $query . "', keyword = 'all-jewelry'");

	return $categoryId;
}

function assignActiveProducts(mysqli $db, string $prefix, int $categoryId): void {
	$productTable = escapeIdentifier($prefix . 'product');
	$productStoreTable = escapeIdentifier($prefix . 'product_to_store');
	$productCategoryTable = escapeIdentifier($prefix . 'product_to_category');
	$db->query("INSERT IGNORE INTO `" . $productCategoryTable . "` (product_id, category_id) SELECT p.product_id, '" . $categoryId . "' FROM `" . $productTable . "` p INNER JOIN `" . $productStoreTable . "` p2s ON (p.product_id = p2s.product_id AND p2s.store_id = 0) WHERE p.status = 1 AND p.date_available <= NOW()");
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

function escapeIdentifier(string $identifier): string {
	return str_replace('`', '``', $identifier);
}
