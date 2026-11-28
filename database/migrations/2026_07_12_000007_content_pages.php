<?php
// Prepares editable CMS pages and SEO URLs without supplying or overwriting content.

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$mysqli->set_charset('utf8mb4');

$language = $mysqli->query("SELECT language_id FROM `language` WHERE code = 'ru-ru' LIMIT 1");

if (!$language->num_rows) {
	fwrite(STDERR, "Language ru-ru was not found." . PHP_EOL);
	exit(1);
}

$language_id = (int)$language->fetch_assoc()['language_id'];
$pages = array(
	array('slug' => 'delivery-payment', 'title' => 'Доставка и оплата', 'sort' => 10),
	array('slug' => 'returns', 'title' => 'Возврат и обмен', 'sort' => 20),
	array('slug' => 'jewelry-care', 'title' => 'Уход за украшениями', 'sort' => 30),
	array('slug' => 'sizes-materials', 'title' => 'Размеры и материалы', 'sort' => 40),
	array('slug' => 'gift-packaging', 'title' => 'Подарочная упаковка', 'sort' => 50),
	array('slug' => 'privacy-policy', 'title' => 'Политика обработки персональных данных', 'sort' => 90),
	array('slug' => 'personal-data-consent', 'title' => 'Согласие на обработку персональных данных', 'sort' => 95),
	array('slug' => 'offer', 'title' => 'Публичная оферта', 'sort' => 100)
);

$mysqli->begin_transaction();

try {
	foreach ($pages as $page) {
		$slug = $mysqli->real_escape_string($page['slug']);
		$title = $mysqli->real_escape_string($page['title']);
		$existing = $mysqli->query("SELECT su.query FROM seo_url su WHERE su.store_id = 0 AND su.language_id = '" . $language_id . "' AND su.keyword = '" . $slug . "' LIMIT 1");
		$information_id = 0;

		if ($existing->num_rows && preg_match('/^information_id=(\d+)$/', $existing->fetch_assoc()['query'], $matches)) {
			$information_id = (int)$matches[1];
		}

		if (!$information_id) {
			$found = $mysqli->query("SELECT information_id FROM information_description WHERE language_id = '" . $language_id . "' AND title = '" . $title . "' LIMIT 1");

			if ($found->num_rows) {
				$information_id = (int)$found->fetch_assoc()['information_id'];
			}
		}

		if (!$information_id) {
			// Empty pages stay disabled until an administrator supplies content and SEO fields.
			$mysqli->query("INSERT INTO information SET bottom = 1, sort_order = '" . (int)$page['sort'] . "', status = 0");
			$information_id = (int)$mysqli->insert_id;
			$mysqli->query("INSERT INTO information_description SET information_id = '" . $information_id . "', language_id = '" . $language_id . "', title = '" . $title . "', description = '', meta_title = '', meta_description = '', meta_keyword = ''");
			$mysqli->query("INSERT INTO information_to_store SET information_id = '" . $information_id . "', store_id = 0");
		}

		// Preserve all CMS-managed fields. Only ensure that the stable SEO URL exists.
		$mysqli->query("DELETE FROM seo_url WHERE store_id = 0 AND language_id = '" . $language_id . "' AND (`query` = 'information_id=" . $information_id . "' OR keyword = '" . $slug . "')");
		$mysqli->query("INSERT INTO seo_url SET store_id = 0, language_id = '" . $language_id . "', `query` = 'information_id=" . $information_id . "', keyword = '" . $slug . "'");
	}

	$mysqli->commit();
	echo 'Editable CMS content page structure is ready.' . PHP_EOL;
} catch (Throwable $exception) {
	$mysqli->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}
