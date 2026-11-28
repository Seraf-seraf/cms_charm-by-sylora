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
$categories = array(
	'Ожерелья и колье' => 'ozherelya-i-kolie',
	'Браслеты' => 'braslety',
	'Серьги' => 'sergi',
	'Кольца' => 'kolca',
	'Брелоки' => 'breloki',
	'Комплекты' => 'komplekty',
	'Броши' => 'broshi',
);

$mysqli->begin_transaction();

try {
	foreach ($categories as $name => $keyword) {
		$escapedName = $mysqli->real_escape_string($name);
		$categoryResult = $mysqli->query("SELECT category_id FROM `category_description` WHERE language_id = '" . $languageId . "' AND name = '" . $escapedName . "'");

		while ($category = $categoryResult->fetch_assoc()) {
			$categoryId = (int)$category['category_id'];
			$query = 'category_id=' . $categoryId;
			$escapedQuery = $mysqli->real_escape_string($query);
			$existingResult = $mysqli->query("SELECT seo_url_id FROM `seo_url` WHERE store_id = 0 AND language_id = '" . $languageId . "' AND `query` = '" . $escapedQuery . "' LIMIT 1");

			if ($existingResult->num_rows) {
				continue;
			}

			$escapedKeyword = $mysqli->real_escape_string($keyword);
			$conflictResult = $mysqli->query("SELECT `query` FROM `seo_url` WHERE store_id = 0 AND language_id = '" . $languageId . "' AND keyword = '" . $escapedKeyword . "' LIMIT 1");

			if ($conflictResult->num_rows) {
				$conflict = $conflictResult->fetch_assoc();
				throw new RuntimeException("SEO keyword '" . $keyword . "' is already assigned to '" . $conflict['query'] . "'.");
			}

			$mysqli->query("INSERT INTO `seo_url` SET store_id = 0, language_id = '" . $languageId . "', `query` = '" . $escapedQuery . "', keyword = '" . $escapedKeyword . "'");
		}
	}

	$mysqli->commit();
	echo 'Current category SEO URLs are ready.' . PHP_EOL;
} catch (Throwable $exception) {
	$mysqli->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}
