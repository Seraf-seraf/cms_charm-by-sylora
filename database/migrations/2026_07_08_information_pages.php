<?php
// Adds SEO URLs for trust pages without changing customer-provided content.

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

$seo_urls = array(
	'information_id=4' => 'about',
	'information/contact' => 'contact'
);

$mysqli->begin_transaction();

try {
	foreach ($seo_urls as $query => $keyword) {
		$escaped_query = $mysqli->real_escape_string($query);
		$escaped_keyword = $mysqli->real_escape_string($keyword);

		$mysqli->query("DELETE FROM seo_url WHERE store_id = 0 AND language_id = '" . (int)$language_id . "' AND (`query` = '" . $escaped_query . "' OR keyword = '" . $escaped_keyword . "')");
		$mysqli->query("INSERT INTO seo_url SET store_id = 0, language_id = '" . (int)$language_id . "', `query` = '" . $escaped_query . "', keyword = '" . $escaped_keyword . "'");
	}

	$mysqli->commit();
	echo 'Information page SEO URLs are ready.' . PHP_EOL;
} catch (Throwable $exception) {
	$mysqli->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}
