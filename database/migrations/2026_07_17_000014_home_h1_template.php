<?php
// Adds the configurable H1 fallback for the storefront home page.

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$mysqli->set_charset('utf8mb4');

$key = 'config_seo_home_h1_template';
$value = 'Ручные украшения {store}';
$escaped_key = $mysqli->real_escape_string($key);
$escaped_value = $mysqli->real_escape_string($value);
$result = $mysqli->query("SELECT setting_id FROM setting WHERE store_id = 0 AND `code` = 'config' AND `key` = '" . $escaped_key . "' LIMIT 1");

if (!$result->num_rows) {
	$mysqli->query("INSERT INTO setting SET store_id = 0, `code` = 'config', `key` = '" . $escaped_key . "', `value` = '" . $escaped_value . "', serialized = 0");
}

echo 'Home page H1 fallback template is configured.' . PHP_EOL;
