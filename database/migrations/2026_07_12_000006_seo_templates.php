<?php
// Adds configurable fallback templates used when entity SEO fields are empty.

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$mysqli->set_charset('utf8mb4');

$templates = array(
	'config_seo_category_title_template' => '{name} ручной работы — купить в {store}',
	'config_seo_category_description_template' => 'Выберите {name} ручной работы: авторские изделия, аккуратная упаковка, онлайн-оплата и доставка по России.',
	'config_seo_product_title_template' => '{name} — авторское украшение в {store}',
	'config_seo_product_description_template' => '{name}: авторское украшение ручной работы. Информация о материалах, цене, упаковке, оплате и доставке.',
	'config_seo_information_title_template' => '{name} — {store}',
	'config_seo_information_description_template' => '{name}: актуальная информация магазина {store}.',
	'config_seo_manufacturer_title_template' => '{name} — украшения в {store}',
	'config_seo_manufacturer_description_template' => 'Украшения {name} в каталоге {store}: цены, наличие, онлайн-оплата и доставка по России.',
	'config_seo_special_title_template' => 'Украшения со скидкой — {store}',
	'config_seo_special_description_template' => 'Специальные предложения и украшения ручной работы со скидкой в магазине {store}.',
	'config_seo_contact_title_template' => 'Контакты — {store}',
	'config_seo_contact_description_template' => 'Контакты {store}: телефон, email, форма связи и информация по вопросам заказа, оплаты и доставки.'
);

foreach ($templates as $key => $value) {
	$escaped_key = $mysqli->real_escape_string($key);
	$escaped_value = $mysqli->real_escape_string($value);
	$result = $mysqli->query("SELECT setting_id FROM setting WHERE store_id = 0 AND `code` = 'config' AND `key` = '" . $escaped_key . "' LIMIT 1");

	if (!$result->num_rows) {
		$mysqli->query("INSERT INTO setting SET store_id = 0, `code` = 'config', `key` = '" . $escaped_key . "', `value` = '" . $escaped_value . "', serialized = 0");
	}
}

echo 'SEO fallback templates are configured.' . PHP_EOL;
