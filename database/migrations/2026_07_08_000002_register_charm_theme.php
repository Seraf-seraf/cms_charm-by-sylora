<?php
// Registers the Charm by Sylora theme extension without selecting it as the active store theme.

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$mysqli->set_charset('utf8mb4');

$settings = array(
	'theme_charm_by_sylora_directory' => 'charm_by_sylora',
	'theme_charm_by_sylora_status' => '1',
	'theme_charm_by_sylora_product_limit' => '15',
	'theme_charm_by_sylora_product_description_length' => '100',
	'theme_charm_by_sylora_image_category_width' => '80',
	'theme_charm_by_sylora_image_category_height' => '80',
	'theme_charm_by_sylora_image_thumb_width' => '228',
	'theme_charm_by_sylora_image_thumb_height' => '228',
	'theme_charm_by_sylora_image_popup_width' => '500',
	'theme_charm_by_sylora_image_popup_height' => '500',
	'theme_charm_by_sylora_image_product_width' => '480',
	'theme_charm_by_sylora_image_product_height' => '600',
	'theme_charm_by_sylora_image_additional_width' => '74',
	'theme_charm_by_sylora_image_additional_height' => '74',
	'theme_charm_by_sylora_image_related_width' => '480',
	'theme_charm_by_sylora_image_related_height' => '600',
	'theme_charm_by_sylora_image_compare_width' => '90',
	'theme_charm_by_sylora_image_compare_height' => '90',
	'theme_charm_by_sylora_image_wishlist_width' => '47',
	'theme_charm_by_sylora_image_wishlist_height' => '47',
	'theme_charm_by_sylora_image_cart_width' => '47',
	'theme_charm_by_sylora_image_cart_height' => '47',
	'theme_charm_by_sylora_image_location_width' => '268',
	'theme_charm_by_sylora_image_location_height' => '50'
);

$mysqli->begin_transaction();

try {
	$mysqli->query("DELETE FROM `extension` WHERE `type` = 'theme' AND `code` = 'charm_by_sylora'");
	$mysqli->query("INSERT INTO `extension` SET `type` = 'theme', `code` = 'charm_by_sylora'");

	foreach ($settings as $key => $value) {
		$escaped_key = $mysqli->real_escape_string($key);
		$escaped_value = $mysqli->real_escape_string($value);

		$mysqli->query("DELETE FROM `setting` WHERE store_id = 0 AND `code` = 'theme_charm_by_sylora' AND `key` = '" . $escaped_key . "'");
		$mysqli->query("INSERT INTO `setting` SET store_id = 0, `code` = 'theme_charm_by_sylora', `key` = '" . $escaped_key . "', `value` = '" . $escaped_value . "', serialized = 0");
	}

	$mysqli->commit();
	echo 'Charm by Sylora theme extension is registered.' . PHP_EOL;
} catch (Throwable $exception) {
	$mysqli->rollback();
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}
