<?php
// Creates the managed jewelry category structure required by the project brief.

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);

if ($mysqli->connect_errno) {
	fwrite(STDERR, 'DB connection failed: ' . $mysqli->connect_error . PHP_EOL);
	exit(1);
}

$mysqli->set_charset('utf8mb4');

$language = $mysqli->query("SELECT language_id FROM `language` WHERE code = 'ru-ru' LIMIT 1");

if (!$language || !$language->num_rows) {
	fwrite(STDERR, "Language ru-ru was not found." . PHP_EOL);
	exit(1);
}

$language_id = (int)$language->fetch_assoc()['language_id'];

$categories = array(
	array(
		'name' => 'Все украшения',
		'slug' => 'all-jewelry',
		'sort_order' => 1,
		'description' => 'Полный каталог авторских украшений Charm by Sylora: серьги, браслеты, колье, подвески, комплекты и подарочные подборки.',
		'meta_title' => 'Все украшения Charm by Sylora',
		'meta_description' => 'Все украшения ручной работы Charm by Sylora: серьги, браслеты, колье, подвески, комплекты и подарки.'
	),
	array(
		'name' => 'Серьги',
		'slug' => 'earrings',
		'sort_order' => 2,
		'description' => 'Серьги ручной работы для повседневных образов, подарков и аккуратных акцентов.',
		'meta_title' => 'Серьги ручной работы',
		'meta_description' => 'Авторские серьги ручной работы Charm by Sylora в спокойной современной эстетике.'
	),
	array(
		'name' => 'Браслеты',
		'slug' => 'bracelets',
		'sort_order' => 3,
		'description' => 'Браслеты ручной работы с мягкими оттенками, деликатными деталями и удобной посадкой.',
		'meta_title' => 'Браслеты ручной работы',
		'meta_description' => 'Браслеты ручной работы Charm by Sylora: аккуратные формы, натуральные оттенки и подарочная упаковка.'
	),
	array(
		'name' => 'Колье',
		'slug' => 'necklaces',
		'sort_order' => 4,
		'description' => 'Колье ручной работы для выразительных, но не перегруженных образов.',
		'meta_title' => 'Колье ручной работы',
		'meta_description' => 'Колье ручной работы Charm by Sylora для повседневных и особенных образов.'
	),
	array(
		'name' => 'Подвески',
		'slug' => 'pendants',
		'sort_order' => 5,
		'description' => 'Минималистичные подвески ручной работы для личных образов и небольших подарков.',
		'meta_title' => 'Подвески ручной работы',
		'meta_description' => 'Подвески ручной работы Charm by Sylora: лаконичные украшения для себя и в подарок.'
	),
	array(
		'name' => 'Кольца при наличии',
		'aliases' => array('Кольца'),
		'slug' => 'rings',
		'sort_order' => 6,
		'description' => 'Кольца ручной работы появятся в каталоге при наличии ассортимента.',
		'meta_title' => 'Кольца ручной работы',
		'meta_description' => 'Кольца ручной работы Charm by Sylora при наличии ассортимента.'
	),
	array(
		'name' => 'Комплекты',
		'slug' => 'sets',
		'sort_order' => 7,
		'description' => 'Готовые сочетания украшений ручной работы в едином стиле.',
		'meta_title' => 'Комплекты украшений ручной работы',
		'meta_description' => 'Комплекты украшений Charm by Sylora: готовые сочетания ручной работы.'
	),
	array(
		'name' => 'Подарки',
		'slug' => 'gifts',
		'sort_order' => 8,
		'description' => 'Украшения и подборки, которые удобно выбрать в подарок.',
		'meta_title' => 'Украшения в подарок',
		'meta_description' => 'Украшения ручной работы в подарок с бережной упаковкой Charm by Sylora.'
	),
	array(
		'name' => 'Новинки',
		'slug' => 'new-arrivals',
		'sort_order' => 9,
		'description' => 'Новые украшения и свежие поступления в каталоге Charm by Sylora.',
		'meta_title' => 'Новинки украшений',
		'meta_description' => 'Новые украшения ручной работы Charm by Sylora и свежие поступления каталога.'
	),
	array(
		'name' => 'Распродажа',
		'slug' => 'sale',
		'sort_order' => 10,
		'description' => 'Украшения со скидкой и специальные предложения.',
		'meta_title' => 'Распродажа украшений',
		'meta_description' => 'Украшения ручной работы Charm by Sylora со скидкой и специальные предложения.'
	)
);

$demo_categories = array(
	'Desktops',
	'Laptops &amp; Notebooks',
	'Components',
	'Tablets',
	'Software',
	'Phones &amp; PDAs',
	'Cameras',
	'MP3 Players'
);

$mysqli->begin_transaction();

try {
	foreach ($demo_categories as $name) {
		$name = $mysqli->real_escape_string($name);

		$mysqli->query("UPDATE category c JOIN category_description cd ON c.category_id = cd.category_id SET c.status = 0, c.top = 0, c.date_modified = NOW() WHERE c.parent_id = 0 AND cd.language_id = '" . (int)$language_id . "' AND cd.name = '" . $name . "'");
	}

	foreach ($categories as $category) {
		$name = $mysqli->real_escape_string($category['name']);
		$description = $mysqli->real_escape_string($category['description']);
		$meta_title = $mysqli->real_escape_string($category['meta_title']);
		$meta_description = $mysqli->real_escape_string($category['meta_description']);
		$slug = $mysqli->real_escape_string($category['slug']);

		$lookup_names = array($name);

		if (!empty($category['aliases'])) {
			foreach ($category['aliases'] as $alias) {
				$lookup_names[] = $mysqli->real_escape_string($alias);
			}
		}

		$result = $mysqli->query("SELECT category_id FROM category_description WHERE language_id = '" . (int)$language_id . "' AND name IN ('" . implode("','", $lookup_names) . "') ORDER BY FIELD(name, '" . implode("','", $lookup_names) . "') LIMIT 1");

		if ($result && $result->num_rows) {
			$category_id = (int)$result->fetch_assoc()['category_id'];
		} else {
			$mysqli->query("INSERT INTO category SET image = '', parent_id = 0, top = 1, `column` = 1, sort_order = '" . (int)$category['sort_order'] . "', status = 1, date_added = NOW(), date_modified = NOW()");
			$category_id = (int)$mysqli->insert_id;
		}

		$mysqli->query("INSERT INTO category_description SET category_id = '" . (int)$category_id . "', language_id = '" . (int)$language_id . "', name = '" . $name . "', description = '" . $description . "', meta_title = '" . $meta_title . "', meta_description = '" . $meta_description . "', meta_keyword = '' ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), meta_title = VALUES(meta_title), meta_description = VALUES(meta_description), meta_keyword = VALUES(meta_keyword)");
		$mysqli->query("UPDATE category SET parent_id = 0, top = 1, `column` = 1, sort_order = '" . (int)$category['sort_order'] . "', status = 1, date_modified = NOW() WHERE category_id = '" . (int)$category_id . "'");
		$mysqli->query("INSERT IGNORE INTO category_to_store SET category_id = '" . (int)$category_id . "', store_id = 0");
		$mysqli->query("DELETE FROM category_path WHERE category_id = '" . (int)$category_id . "'");
		$mysqli->query("INSERT INTO category_path SET category_id = '" . (int)$category_id . "', path_id = '" . (int)$category_id . "', level = 0");
		$mysqli->query("DELETE FROM seo_url WHERE store_id = 0 AND language_id = '" . (int)$language_id . "' AND (`query` = 'category_id=" . (int)$category_id . "' OR keyword = '" . $slug . "')");
		$mysqli->query("INSERT INTO seo_url SET store_id = 0, language_id = '" . (int)$language_id . "', `query` = 'category_id=" . (int)$category_id . "', keyword = '" . $slug . "'");
	}

	$mysqli->commit();
	echo 'Jewelry categories are ready.' . PHP_EOL;
} catch (Throwable $exception) {
	$mysqli->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}
