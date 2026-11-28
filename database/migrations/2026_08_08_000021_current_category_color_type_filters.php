<?php

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$mysqli->set_charset('utf8mb4');

$languageResult = $mysqli->query("SELECT language_id FROM `language` WHERE code = 'ru-ru' LIMIT 1");

if (!$languageResult->num_rows) {
	throw new RuntimeException('Language ru-ru was not found.');
}

$languageId = (int)$languageResult->fetch_assoc()['language_id'];
$categoryNames = array('Ожерелья и колье', 'Браслеты', 'Серьги', 'Кольца', 'Брелоки', 'Комплекты', 'Броши');
$filterGroups = array(
	'Цвет' => array(
		'Белый' => array('бел', 'молочн'),
		'Розовый' => array('розов'),
		'Голубой' => array('голуб'),
		'Синий' => array('син'),
		'Зелёный' => array('зелён', 'салатов', 'оливков', 'бирюз'),
		'Фиолетовый' => array('фиолет', 'сиренев', 'лилов'),
		'Жёлтый' => array('жёлт'),
		'Серебристый' => array('серебрист'),
		'Прозрачный' => array('прозрачн'),
	),
	'Тип изделия' => array(
		'Брелок-подвеска' => array('брелок'),
		'Колье' => array('колье'),
	),
);
$attributeNames = array(
	'Цвет' => array('основные цвета', 'цвет'),
	'Тип изделия' => array('тип изделия'),
);

$mysqli->begin_transaction();

try {
	$filterIds = array();

	foreach ($filterGroups as $groupName => $filters) {
		$filterGroupId = upsertCatalogFilterGroup($mysqli, $languageId, $groupName);
		$filterIds[$groupName] = array();

		foreach (array_keys($filters) as $sortOrder => $filterName) {
			$filterIds[$groupName][$filterName] = upsertCatalogFilter($mysqli, $languageId, $filterGroupId, $filterName, $sortOrder + 1);
		}
	}

	foreach ($categoryNames as $categoryName) {
		$escapedName = $mysqli->real_escape_string($categoryName);
		$categoryResult = $mysqli->query("SELECT category_id FROM `category_description` WHERE language_id = '" . $languageId . "' AND name = '" . $escapedName . "'");

		while ($category = $categoryResult->fetch_assoc()) {
			foreach ($filterIds as $groupFilterIds) {
				foreach ($groupFilterIds as $filterId) {
					$mysqli->query("INSERT IGNORE INTO `category_filter` SET category_id = '" . (int)$category['category_id'] . "', filter_id = '" . $filterId . "'");
				}
			}
		}
	}

	$attributeResult = $mysqli->query("SELECT pa.product_id, ad.name, pa.text FROM `product_attribute` pa INNER JOIN `attribute_description` ad ON (ad.attribute_id = pa.attribute_id AND ad.language_id = pa.language_id) WHERE pa.language_id = '" . $languageId . "'");

	while ($attribute = $attributeResult->fetch_assoc()) {
		$attributeName = normalizeCatalogFilterText($attribute['name']);
		$text = normalizeCatalogFilterText($attribute['text']);

		foreach ($filterGroups as $groupName => $filters) {
			if (!in_array($attributeName, $attributeNames[$groupName], true)) {
				continue;
			}

			foreach ($filters as $filterName => $needles) {
				foreach ($needles as $needle) {
					if (strpos($text, $needle) === false) {
						continue;
					}

					$mysqli->query("INSERT IGNORE INTO `product_filter` SET product_id = '" . (int)$attribute['product_id'] . "', filter_id = '" . $filterIds[$groupName][$filterName] . "'");
					break;
				}
			}
		}
	}

	$mysqli->commit();
	echo 'Current category color and type filters are ready.' . PHP_EOL;
} catch (Throwable $exception) {
	$mysqli->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}

function normalizeCatalogFilterText(string $text): string {
	return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function upsertCatalogFilterGroup(mysqli $mysqli, int $languageId, string $name): int {
	$escapedName = $mysqli->real_escape_string($name);
	$result = $mysqli->query("SELECT filter_group_id FROM `filter_group_description` WHERE language_id = '" . $languageId . "' AND name = '" . $escapedName . "' LIMIT 1");

	if ($result->num_rows) {
		return (int)$result->fetch_assoc()['filter_group_id'];
	}

	$mysqli->query("INSERT INTO `filter_group` SET sort_order = 2");
	$filterGroupId = (int)$mysqli->insert_id;
	$mysqli->query("INSERT INTO `filter_group_description` SET filter_group_id = '" . $filterGroupId . "', language_id = '" . $languageId . "', name = '" . $escapedName . "'");

	return $filterGroupId;
}

function upsertCatalogFilter(mysqli $mysqli, int $languageId, int $filterGroupId, string $name, int $sortOrder): int {
	$escapedName = $mysqli->real_escape_string($name);
	$result = $mysqli->query("SELECT filter_id FROM `filter_description` WHERE language_id = '" . $languageId . "' AND filter_group_id = '" . $filterGroupId . "' AND name = '" . $escapedName . "' LIMIT 1");

	if ($result->num_rows) {
		return (int)$result->fetch_assoc()['filter_id'];
	}

	$mysqli->query("INSERT INTO `filter` SET filter_group_id = '" . $filterGroupId . "', sort_order = '" . $sortOrder . "'");
	$filterId = (int)$mysqli->insert_id;
	$mysqli->query("INSERT INTO `filter_description` SET filter_id = '" . $filterId . "', language_id = '" . $languageId . "', filter_group_id = '" . $filterGroupId . "', name = '" . $escapedName . "'");

	return $filterId;
}
