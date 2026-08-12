<?php
// Creates catalog filter groups and assigns them to managed categories and local test products.

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

$groups = array(
	'Материал' => array(
		'sort_order' => 1,
		'filters' => array('Стекло', 'Натуральные бусины', 'Металл', 'Текстильный шнур', 'Полимерная глина')
	),
	'Цвет' => array(
		'sort_order' => 2,
		'filters' => array('Розовый', 'Зеленый', 'Коричневый', 'Золотистый', 'Черный')
	),
	'Тип украшения' => array(
		'sort_order' => 3,
		'filters' => array('Серьги', 'Браслет', 'Подвеска', 'Колье', 'Комплект', 'Подарочный набор')
	)
);

$product_filters = array(
	'SYLORA-TEST-001' => array('Стекло', 'Металл', 'Розовый', 'Зеленый', 'Золотистый', 'Серьги'),
	'SYLORA-TEST-002' => array('Натуральные бусины', 'Текстильный шнур', 'Коричневый', 'Зеленый', 'Браслет'),
	'SYLORA-TEST-003' => array('Стекло', 'Металл', 'Розовый', 'Зеленый', 'Подвеска'),
	'SYLORA-TEST-004' => array('Металл', 'Натуральные бусины', 'Золотистый', 'Коричневый', 'Колье'),
	'SYLORA-TEST-005' => array('Стекло', 'Металл', 'Розовый', 'Коричневый', 'Комплект'),
	'SYLORA-TEST-006' => array('Натуральные бусины', 'Текстильный шнур', 'Черный', 'Розовый', 'Подарочный набор')
);

$category_names = array(
	'Все украшения',
	'Серьги',
	'Браслеты',
	'Колье',
	'Подвески',
	'Кольца при наличии',
	'Комплекты',
	'Подарки',
	'Новинки',
	'Распродажа'
);

$mysqli->begin_transaction();

try {
	$filter_ids = array();

	foreach ($groups as $group_name => $group) {
		$filter_group_id = upsertFilterGroup($mysqli, $language_id, $group_name, (int)$group['sort_order']);

		foreach ($group['filters'] as $sort_order => $filter_name) {
			$filter_ids[$filter_name] = upsertFilter($mysqli, $language_id, $filter_group_id, $filter_name, $sort_order + 1);
		}
	}

	foreach ($category_names as $category_name) {
		$category_id = getCategoryId($mysqli, $language_id, $category_name);

		foreach ($filter_ids as $filter_id) {
			$mysqli->query("INSERT IGNORE INTO category_filter SET category_id = '" . (int)$category_id . "', filter_id = '" . (int)$filter_id . "'");
		}
	}

	foreach ($product_filters as $model => $filters) {
		$product_id = getProductId($mysqli, $model);
		$mysqli->query("DELETE FROM product_filter WHERE product_id = '" . (int)$product_id . "'");

		foreach ($filters as $filter_name) {
			if (!isset($filter_ids[$filter_name])) {
				throw new RuntimeException("Filter '" . $filter_name . "' was not created.");
			}

			$mysqli->query("INSERT IGNORE INTO product_filter SET product_id = '" . (int)$product_id . "', filter_id = '" . (int)$filter_ids[$filter_name] . "'");
		}
	}

	$mysqli->commit();
	echo 'Catalog filters are ready.' . PHP_EOL;
} catch (Throwable $exception) {
	$mysqli->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}

function upsertFilterGroup(mysqli $mysqli, int $language_id, string $name, int $sort_order): int {
	$escaped_name = $mysqli->real_escape_string($name);
	$result = $mysqli->query("SELECT filter_group_id FROM filter_group_description WHERE language_id = '" . (int)$language_id . "' AND name = '" . $escaped_name . "' LIMIT 1");

	if ($result->num_rows) {
		$filter_group_id = (int)$result->fetch_assoc()['filter_group_id'];
		$mysqli->query("UPDATE filter_group SET sort_order = '" . (int)$sort_order . "' WHERE filter_group_id = '" . (int)$filter_group_id . "'");
	} else {
		$mysqli->query("INSERT INTO filter_group SET sort_order = '" . (int)$sort_order . "'");
		$filter_group_id = (int)$mysqli->insert_id;
	}

	$mysqli->query("INSERT INTO filter_group_description SET filter_group_id = '" . (int)$filter_group_id . "', language_id = '" . (int)$language_id . "', name = '" . $escaped_name . "' ON DUPLICATE KEY UPDATE name = VALUES(name)");

	return $filter_group_id;
}

function upsertFilter(mysqli $mysqli, int $language_id, int $filter_group_id, string $name, int $sort_order): int {
	$escaped_name = $mysqli->real_escape_string($name);
	$result = $mysqli->query("SELECT filter_id FROM filter_description WHERE language_id = '" . (int)$language_id . "' AND filter_group_id = '" . (int)$filter_group_id . "' AND name = '" . $escaped_name . "' LIMIT 1");

	if ($result->num_rows) {
		$filter_id = (int)$result->fetch_assoc()['filter_id'];
		$mysqli->query("UPDATE filter SET sort_order = '" . (int)$sort_order . "' WHERE filter_id = '" . (int)$filter_id . "'");
	} else {
		$mysqli->query("INSERT INTO filter SET filter_group_id = '" . (int)$filter_group_id . "', sort_order = '" . (int)$sort_order . "'");
		$filter_id = (int)$mysqli->insert_id;
	}

	$mysqli->query("INSERT INTO filter_description SET filter_id = '" . (int)$filter_id . "', language_id = '" . (int)$language_id . "', filter_group_id = '" . (int)$filter_group_id . "', name = '" . $escaped_name . "' ON DUPLICATE KEY UPDATE filter_group_id = VALUES(filter_group_id), name = VALUES(name)");

	return $filter_id;
}

function getCategoryId(mysqli $mysqli, int $language_id, string $name): int {
	$escaped_name = $mysqli->real_escape_string($name);
	$result = $mysqli->query("SELECT category_id FROM category_description WHERE language_id = '" . (int)$language_id . "' AND name = '" . $escaped_name . "' LIMIT 1");

	if ($result->num_rows) {
		return (int)$result->fetch_assoc()['category_id'];
	}

	throw new RuntimeException("Category '" . $name . "' was not found. Run 2026_07_06_000002_jewelry_categories.php first.");
}

function getProductId(mysqli $mysqli, string $model): int {
	$escaped_model = $mysqli->real_escape_string($model);
	$result = $mysqli->query("SELECT product_id FROM product WHERE model = '" . $escaped_model . "' LIMIT 1");

	if ($result->num_rows) {
		return (int)$result->fetch_assoc()['product_id'];
	}

	throw new RuntimeException("Product '" . $model . "' was not found. Run 2026_07_07_000001_home_showcase_test_data.php first.");
}
