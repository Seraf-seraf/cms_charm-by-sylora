<?php
// Aligns active product-card image caches and refreshes local showcase states.

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$mysqli->set_charset('utf8mb4');

$image_settings = array(
	'theme_charm_by_sylora_image_product_width' => array('legacy' => array('228'), 'value' => '480'),
	'theme_charm_by_sylora_image_product_height' => array('legacy' => array('228'), 'value' => '600'),
	'theme_charm_by_sylora_image_related_width' => array('legacy' => array('80'), 'value' => '480'),
	'theme_charm_by_sylora_image_related_height' => array('legacy' => array('80'), 'value' => '600'),
);

$showcase_products = array(
	'SYLORA-TEST-001' => array('stock_status_id' => 7, 'hover_image' => 'catalog/sylora/test-products/earrings-detail.png'),
	'SYLORA-TEST-002' => array('stock_status_id' => 7, 'hover_image' => 'catalog/sylora/test-products/bracelet-detail.png'),
	'SYLORA-TEST-003' => array('stock_status_id' => 8, 'hover_image' => 'catalog/sylora/test-products/pendant-detail.png'),
	'SYLORA-TEST-004' => array('stock_status_id' => 7, 'hover_image' => 'catalog/sylora/test-products/necklace-detail.png'),
	'SYLORA-TEST-005' => array('stock_status_id' => 7, 'hover_image' => 'catalog/sylora/test-products/set-detail.png'),
	'SYLORA-TEST-006' => array('stock_status_id' => 5, 'hover_image' => 'catalog/sylora/test-products/gift-detail.png'),
);

$mysqli->begin_transaction();

try {
	foreach ($image_settings as $key => $setting) {
		$escaped_key = $mysqli->real_escape_string($key);
		$result = $mysqli->query("SELECT value FROM setting WHERE store_id = 0 AND code = 'theme_charm_by_sylora' AND `key` = '" . $escaped_key . "' LIMIT 1");

		if (!$result->num_rows) {
			$mysqli->query("INSERT INTO setting SET store_id = 0, code = 'theme_charm_by_sylora', `key` = '" . $escaped_key . "', value = '" . $mysqli->real_escape_string($setting['value']) . "', serialized = 0");
			continue;
		}

		$current_value = (string)$result->fetch_assoc()['value'];

		if (in_array($current_value, $setting['legacy'], true)) {
			$mysqli->query("UPDATE setting SET value = '" . $mysqli->real_escape_string($setting['value']) . "' WHERE store_id = 0 AND code = 'theme_charm_by_sylora' AND `key` = '" . $escaped_key . "'");
		}
	}

	foreach ($showcase_products as $model => $presentation) {
		$escaped_model = $mysqli->real_escape_string($model);
		$result = $mysqli->query("SELECT product_id FROM product WHERE model = '" . $escaped_model . "' LIMIT 1");

		if (!$result->num_rows) {
			continue;
		}

		$product_id = (int)$result->fetch_assoc()['product_id'];
		$quantity = in_array((int)$presentation['stock_status_id'], array(5, 8), true) ? 0 : null;
		$quantity_sql = $quantity === null ? '' : ", quantity = '" . $quantity . "'";

		$mysqli->query("UPDATE product SET stock_status_id = '" . (int)$presentation['stock_status_id'] . "'" . $quantity_sql . " WHERE product_id = '" . $product_id . "'");
		$mysqli->query("DELETE FROM product_image WHERE product_id = '" . $product_id . "'");
		$mysqli->query("INSERT INTO product_image SET product_id = '" . $product_id . "', image = '" . $mysqli->real_escape_string($presentation['hover_image']) . "', sort_order = 1");
	}

	$mysqli->commit();
	echo 'Product card presentation defaults are updated.' . PHP_EOL;
} catch (Throwable $exception) {
	$mysqli->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}
