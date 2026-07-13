<?php

declare(strict_types=1);

$templates = array(
	'order_add' => __DIR__ . '/../upload/catalog/view/theme/charm_by_sylora/template/mail/order_add.twig',
	'order_alert' => __DIR__ . '/../upload/catalog/view/theme/charm_by_sylora/template/mail/order_alert.twig',
	'order_edit' => __DIR__ . '/../upload/catalog/view/theme/charm_by_sylora/template/mail/order_edit.twig',
);

$requiredMarkers = array(
	'{{ order_id }}',
	'{{ payment_method }}',
	'{{ shipping_method }}',
	'{% for product in products %}',
	'{% for total in totals %}',
);

foreach ($templates as $name => $path) {
	$content = readFileContent($path);

	foreach ($requiredMarkers as $marker) {
		assertContains($content, $marker, $name . ' contains ' . $marker);
	}
}

$orderController = readFileContent(__DIR__ . '/../upload/catalog/controller/mail/order.php');
assertContains($orderController, "\$data['payment_method'] = \$order_info['payment_method'];", 'Order emails pass payment method');
assertContains($orderController, "\$data['shipping_method'] = \$order_info['shipping_method'];", 'Order emails pass shipping method');
assertContains($orderController, "getOrderProducts(\$order_info['order_id'])", 'Status update email loads products');
assertContains($orderController, "getOrderTotals(\$order_info['order_id'])", 'Status update email loads totals');

$contactController = readFileContent(__DIR__ . '/../upload/catalog/controller/information/contact.php');
assertContains($contactController, 'Новое сообщение с сайта Charm by Sylora', 'Contact email has Russian subject body');
assertContains($contactController, 'Сообщение:', 'Contact email includes message body');

echo "Email notification tests passed.\n";

function readFileContent(string $path): string {
	$content = file_get_contents($path);

	if (!is_string($content)) {
		throw new RuntimeException('Cannot read file: ' . $path);
	}

	return $content;
}

function assertContains(string $haystack, string $needle, string $message): void {
	if (strpos($haystack, $needle) === false) {
		throw new RuntimeException($message);
	}
}
