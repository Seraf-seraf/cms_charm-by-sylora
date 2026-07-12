<?php
$root = dirname(__DIR__);
$files = array(
	'admin_controller' => $root . '/upload/admin/controller/extension/analytics/yandex_metrica.php',
	'catalog_controller' => $root . '/upload/catalog/controller/extension/analytics/yandex_metrica.php',
	'template' => $root . '/upload/catalog/view/theme/charm_by_sylora/template/extension/analytics/yandex_metrica.twig',
	'migration' => $root . '/database/migrations/2026_07_12_000008_yandex_metrica.php'
);

foreach ($files as $name => $file) {
	if (!is_file($file)) {
		fwrite(STDERR, 'Missing Yandex Metrica ' . $name . PHP_EOL);
		exit(1);
	}
}

$template = file_get_contents($files['template']);
foreach (array('phone_click', 'email_click', 'messenger_click', 'add_to_cart', 'checkout_start', 'order_success', 'contact_submit', 'window.dataLayer', 'ecommerce') as $marker) {
	if (strpos($template, $marker) === false) {
		fwrite(STDERR, 'Missing analytics marker: ' . $marker . PHP_EOL);
		exit(1);
	}
}

$success = file_get_contents($root . '/upload/catalog/controller/checkout/success.php');
if (strpos($success, "'analytics_purchase'") === false || strpos($success, "'purchase'") === false) {
	fwrite(STDERR, 'Purchase ecommerce payload is missing.' . PHP_EOL);
	exit(1);
}

$settings = file_get_contents($files['admin_controller']);
if (strpos($settings, "preg_match('/^\\d{5,12}$/") === false) {
	fwrite(STDERR, 'Counter ID validation is missing.' . PHP_EOL);
	exit(1);
}

echo 'Yandex Metrica tests passed.' . PHP_EOL;
