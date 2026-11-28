<?php

declare(strict_types=1);

// Prepares admin-managed product dictionaries required by ТЗ 21.1.
// This migration does not create real catalog content.

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

$mysqli->begin_transaction();

try {
	$stock_statuses = array(
		5 => 'Нет в наличии',
		6 => 'Ожидается 2-3 дня',
		7 => 'В наличии',
		8 => 'Под заказ',
	);

	foreach ($stock_statuses as $stock_status_id => $name) {
		$escaped_name = $mysqli->real_escape_string($name);
		$mysqli->query("INSERT INTO stock_status SET stock_status_id = '" . (int)$stock_status_id . "', language_id = '" . (int)$language_id . "', name = '" . $escaped_name . "' ON DUPLICATE KEY UPDATE name = VALUES(name)");
	}

	$attribute_group_id = upsertAttributeGroup($mysqli, $language_id, 'Детали украшения', 1);
	$attributes = array(
		'Материалы' => 1,
		'Размер' => 2,
		'Цвет' => 3,
		'Уход' => 4,
		'Доставка и возврат' => 5,
		'Alt основного фото' => 6,
	);

	foreach ($attributes as $name => $sort_order) {
		upsertAttribute($mysqli, $language_id, $attribute_group_id, $name, $sort_order);
	}

	$mysqli->commit();
	echo 'Admin product data defaults are ready.' . PHP_EOL;
} catch (Throwable $exception) {
	$mysqli->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}

function upsertAttributeGroup(mysqli $mysqli, int $language_id, string $name, int $sort_order): int {
	$escaped_name = $mysqli->real_escape_string($name);
	$result = $mysqli->query("SELECT attribute_group_id FROM attribute_group_description WHERE language_id = '" . (int)$language_id . "' AND name = '" . $escaped_name . "' LIMIT 1");

	if ($result->num_rows) {
		$attribute_group_id = (int)$result->fetch_assoc()['attribute_group_id'];
		$mysqli->query("UPDATE attribute_group SET sort_order = '" . (int)$sort_order . "' WHERE attribute_group_id = '" . (int)$attribute_group_id . "'");
	} else {
		$mysqli->query("INSERT INTO attribute_group SET sort_order = '" . (int)$sort_order . "'");
		$attribute_group_id = (int)$mysqli->insert_id;
	}

	$mysqli->query("INSERT INTO attribute_group_description SET attribute_group_id = '" . (int)$attribute_group_id . "', language_id = '" . (int)$language_id . "', name = '" . $escaped_name . "' ON DUPLICATE KEY UPDATE name = VALUES(name)");

	return $attribute_group_id;
}

function upsertAttribute(mysqli $mysqli, int $language_id, int $attribute_group_id, string $name, int $sort_order): int {
	$escaped_name = $mysqli->real_escape_string($name);
	$result = $mysqli->query("SELECT attribute_id FROM attribute_description WHERE language_id = '" . (int)$language_id . "' AND name = '" . $escaped_name . "' LIMIT 1");

	if ($result->num_rows) {
		$attribute_id = (int)$result->fetch_assoc()['attribute_id'];
		$mysqli->query("UPDATE attribute SET attribute_group_id = '" . (int)$attribute_group_id . "', sort_order = '" . (int)$sort_order . "' WHERE attribute_id = '" . (int)$attribute_id . "'");
	} else {
		$mysqli->query("INSERT INTO attribute SET attribute_group_id = '" . (int)$attribute_group_id . "', sort_order = '" . (int)$sort_order . "'");
		$attribute_id = (int)$mysqli->insert_id;
	}

	$mysqli->query("INSERT INTO attribute_description SET attribute_id = '" . (int)$attribute_id . "', language_id = '" . (int)$language_id . "', name = '" . $escaped_name . "' ON DUPLICATE KEY UPDATE name = VALUES(name)");

	return $attribute_id;
}
