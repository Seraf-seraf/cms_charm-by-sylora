<?php
// Adds managed product attributes and SEO URLs for local product page checks.

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

$attributes = array(
	'Материалы' => 1,
	'Размер' => 2,
	'Цвет' => 3,
	'Уход' => 4,
	'Доставка и возврат' => 5,
	'Alt основного фото' => 6
);

$products = array(
	'SYLORA-TEST-001' => array(
		'slug' => 'earrings-pink-dawn',
		'Материалы' => 'стеклянные бусины, металлическая фурнитура',
		'Размер' => 'длина около 4 см',
		'Цвет' => 'розовый, зеленый, золотистый',
		'Уход' => 'Храните серьги отдельно в сухом месте. Снимайте перед душем, сном и тренировками, избегайте контакта с парфюмом и бытовой химией.',
		'Доставка и возврат' => 'Доставка по России рассчитывается при оформлении заказа. Возврат возможен для изделий без следов носки и повреждений упаковки.',
		'Alt основного фото' => 'Серьги Розовый рассвет ручной работы Charm by Sylora'
	),
	'SYLORA-TEST-002' => array(
		'slug' => 'bracelet-forest-thread',
		'Материалы' => 'натуральные бусины, текстильный шнур, металлические детали',
		'Размер' => 'регулируемая посадка 16-20 см',
		'Цвет' => 'коричневый, зеленый',
		'Уход' => 'Не затягивайте браслет рывком и не храните его под прямым солнцем. Для очистки используйте мягкую сухую ткань.',
		'Доставка и возврат' => 'Если нужен другой размер, укажите это в комментарии к заказу. Сроки изготовления и отправки уточняются после оформления.',
		'Alt основного фото' => 'Браслет Лесная нить с натуральными бусинами'
	),
	'SYLORA-TEST-003' => array(
		'slug' => 'pendant-quiet-garden',
		'Материалы' => 'стекло, металлическая фурнитура',
		'Размер' => 'подвеска около 3 см',
		'Цвет' => 'розовый, зеленый',
		'Уход' => 'Храните подвеску в отдельном мешочке и протирайте сухой тканью после носки. Не используйте абразивные средства.',
		'Доставка и возврат' => 'Товар может быть недоступен и повторяться под заказ. Условия и сроки согласуются перед оплатой.',
		'Alt основного фото' => 'Подвеска Тихий сад в розово-зеленых оттенках'
	),
	'SYLORA-TEST-004' => array(
		'slug' => 'necklace-honey-branch',
		'Материалы' => 'металлическая фурнитура, натуральные бусины',
		'Размер' => 'длина цепочки около 45 см',
		'Цвет' => 'золотистый, коричневый',
		'Уход' => 'Снимайте колье перед сном и храните цепочку расправленной, чтобы избежать заломов и спутывания.',
		'Доставка и возврат' => 'Доставка доступна по России. Индивидуальные изменения длины согласуются до отправки заказа.',
		'Alt основного фото' => 'Колье Медовая ветвь ручной работы'
	),
	'SYLORA-TEST-005' => array(
		'slug' => 'set-dusty-rose',
		'Материалы' => 'стеклянные бусины, металлическая фурнитура',
		'Размер' => 'комплект: серьги и браслет, размер браслета регулируется',
		'Цвет' => 'пыльно-розовый, коричневый',
		'Уход' => 'Храните элементы комплекта отдельно друг от друга. Не допускайте длительного контакта с водой и косметикой.',
		'Доставка и возврат' => 'Комплект отправляется в подарочной упаковке. Возврат возможен только полным комплектом.',
		'Alt основного фото' => 'Комплект украшений Пыльная роза'
	),
	'SYLORA-TEST-006' => array(
		'slug' => 'gift-jewelry-set',
		'Материалы' => 'натуральные бусины, текстильный шнур, декоративная упаковка',
		'Размер' => 'набор из нескольких украшений',
		'Цвет' => 'черный, розовый',
		'Уход' => 'Храните набор в упаковке или отдельном органайзере. Берегите украшения от влаги, ударов и резких перепадов температуры.',
		'Доставка и возврат' => 'Подарочный набор комплектуется перед отправкой. Состав набора и сроки можно уточнить до оплаты.',
		'Alt основного фото' => 'Подарочный набор украшений Charm by Sylora'
	)
);

$mysqli->begin_transaction();

try {
	$attribute_group_id = upsertAttributeGroup($mysqli, $language_id, 'Детали украшения', 1);
	$attribute_ids = array();

	foreach ($attributes as $name => $sort_order) {
		$attribute_ids[$name] = upsertAttribute($mysqli, $language_id, $attribute_group_id, $name, $sort_order);
	}

	foreach ($products as $model => $product_attributes) {
		$product_id = getProductId($mysqli, $model);

		foreach ($attributes as $name => $sort_order) {
			$text = isset($product_attributes[$name]) ? $product_attributes[$name] : '';
			$escaped_text = $mysqli->real_escape_string($text);

			$mysqli->query("INSERT INTO product_attribute SET product_id = '" . (int)$product_id . "', attribute_id = '" . (int)$attribute_ids[$name] . "', language_id = '" . (int)$language_id . "', text = '" . $escaped_text . "' ON DUPLICATE KEY UPDATE text = VALUES(text)");
		}

		$slug = $mysqli->real_escape_string($product_attributes['slug']);
		$mysqli->query("DELETE FROM seo_url WHERE store_id = 0 AND language_id = '" . (int)$language_id . "' AND (`query` = 'product_id=" . (int)$product_id . "' OR keyword = '" . $slug . "')");
		$mysqli->query("INSERT INTO seo_url SET store_id = 0, language_id = '" . (int)$language_id . "', `query` = 'product_id=" . (int)$product_id . "', keyword = '" . $slug . "'");
	}

	$mysqli->commit();
	echo 'Product page test data is ready.' . PHP_EOL;
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

function getProductId(mysqli $mysqli, string $model): int {
	$escaped_model = $mysqli->real_escape_string($model);
	$result = $mysqli->query("SELECT product_id FROM product WHERE model = '" . $escaped_model . "' LIMIT 1");

	if ($result->num_rows) {
		return (int)$result->fetch_assoc()['product_id'];
	}

	throw new RuntimeException("Product '" . $model . "' was not found. Run 2026_07_07_000001_home_showcase_test_data.php first.");
}
