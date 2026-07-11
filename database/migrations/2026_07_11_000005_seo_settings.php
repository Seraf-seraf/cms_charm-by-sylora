<?php
// Enables OpenCart SEO URLs and the database-driven sitemap feed.

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$mysqli->set_charset('utf8mb4');
$mysqli->begin_transaction();

try {
	$mysqli->query("DELETE FROM `setting` WHERE store_id = 0 AND `code` = 'config' AND `key` = 'config_seo_url'");
	$mysqli->query("INSERT INTO `setting` SET store_id = 0, `code` = 'config', `key` = 'config_seo_url', `value` = '1', serialized = 0");

	$mysqli->query("DELETE FROM `setting` WHERE store_id = 0 AND `code` = 'feed_google_sitemap' AND `key` = 'feed_google_sitemap_status'");
	$mysqli->query("INSERT INTO `setting` SET store_id = 0, `code` = 'feed_google_sitemap', `key` = 'feed_google_sitemap_status', `value` = '1', serialized = 0");

	$result = $mysqli->query("SELECT extension_id FROM `extension` WHERE `type` = 'feed' AND `code` = 'google_sitemap' LIMIT 1");

	if (!$result->num_rows) {
		$mysqli->query("INSERT INTO `extension` SET `type` = 'feed', `code` = 'google_sitemap'");
	}

	$mysqli->commit();
	echo 'SEO URLs and sitemap feed are enabled.' . PHP_EOL;
} catch (Throwable $exception) {
	$mysqli->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}
