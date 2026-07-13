<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adminRoot = $root . '/upload/admin';
$catalogRoot = $root . '/upload/catalog';

$checks = array(
	checkAdminRoute($adminRoot, 'catalog/product', 'Товары'),
	checkAdminRoute($adminRoot, 'catalog/category', 'Категории'),
	checkAdminRoute($adminRoot, 'catalog/information', 'Инфостраницы'),
	checkAdminRoute($adminRoot, 'sale/order', 'Заказы'),
	checkAdminRoute($adminRoot, 'extension/shipping/flat', 'Доставка: фиксированная стоимость'),
	checkAdminRoute($adminRoot, 'extension/shipping/cdek_official', 'Доставка: СДЭК'),
	checkAdminRoute($adminRoot, 'extension/shipping/russian_post', 'Доставка: Почта России'),
	checkAdminRoute($adminRoot, 'extension/payment/payment_service', 'Оплата: payment-service'),
	checkAdminRoute($adminRoot, 'marketing/coupon', 'Промокоды'),
	checkAdminRoute($adminRoot, 'design/banner', 'Баннеры'),
	checkProductFormStructure($adminRoot . '/view/template/catalog/product_form.twig'),
	checkProductController($adminRoot . '/controller/catalog/product.php'),
	checkProductFrontendAlt($catalogRoot . '/controller/product/product.php', $catalogRoot . '/view/theme/charm_by_sylora/template/product/product.twig'),
	checkShippingCheckout($catalogRoot . '/controller/checkout/shipping_method.php', $catalogRoot . '/view/theme/charm_by_sylora/template/checkout/shipping_method.twig'),
);

$failed = false;

foreach ($checks as $check) {
	$status = $check['ok'] ? 'OK' : 'FAIL';
	printf("[%s] %s: %s\n", $status, $check['name'], $check['message']);

	if (!$check['ok']) {
		$failed = true;
	}
}

exit($failed ? 1 : 0);

function checkAdminRoute(string $adminRoot, string $route, string $name): array {
	$parts = explode('/', $route);
	$baseName = array_pop($parts);
	$file = $baseName . '.php';
	$directory = implode('/', $parts);
	$controller = $adminRoot . '/controller/' . $directory . '/' . $file;
	$templateBase = str_replace('-', '_', $baseName);
	$templateCandidates = array(
		$adminRoot . '/view/template/' . $directory . '/' . $templateBase . '.twig',
		$adminRoot . '/view/template/' . $directory . '/' . $templateBase . '_form.twig',
		$adminRoot . '/view/template/' . $directory . '/' . $templateBase . '_list.twig',
	);

	if ($route === 'sale/order') {
		$templateCandidates[] = $adminRoot . '/view/template/sale/order_list.twig';
		$templateCandidates[] = $adminRoot . '/view/template/sale/order_info.twig';
	}

	if ($route === 'extension/shipping/cdek_official') {
		$templateCandidates[] = $adminRoot . '/view/template/extension/shipping/cdek_official/settings.twig';
	}

	$templateExists = false;

	foreach ($templateCandidates as $template) {
		if (is_file($template)) {
			$templateExists = true;
			break;
		}
	}

	return array(
		'name' => $name,
		'ok' => is_file($controller) && $templateExists,
		'message' => is_file($controller) && $templateExists ? 'есть controller и template для управления из админ-панели' : 'нет controller или template: ' . $route
	);
}

function checkProductFormStructure(string $path): array {
	$content = readFileContent($path);
	$needles = array(
		'product_description[{{ language.language_id }}][name]' => 'название',
		'product_description[{{ language.language_id }}][description]' => 'описание',
		'product_description[{{ language.language_id }}][meta_title]' => 'SEO Title',
		'product_description[{{ language.language_id }}][meta_description]' => 'SEO Description',
		'product_seo_url[{{ store.store_id }}][{{ language.language_id }}]' => 'SEO URL',
		'name="model"' => 'артикул/model',
		'name="sku"' => 'SKU',
		'name="price"' => 'цена',
		'product_special' => 'старая цена/акция через special',
		'name="quantity"' => 'наличие',
		'name="stock_status_id"' => 'статус под заказ/нет в наличии',
		'name="weight"' => 'вес',
		'product_category[]' => 'категории',
		'product_attribute' => 'материалы/цвет/размер/уход/alt через атрибуты',
		'name="image"' => 'основное фото',
		'product_image' => 'дополнительные фото',
	);
	$missing = array();

	foreach ($needles as $needle => $label) {
		if (!str_contains($content, $needle)) {
			$missing[] = $label;
		}
	}

	return array(
		'name' => 'Структура формы товара',
		'ok' => count($missing) === 0,
		'message' => count($missing) === 0 ? 'форма товара содержит все поля из 21.1' : 'нет полей: ' . implode(', ', $missing)
	);
}

function checkProductController(string $path): array {
	$content = readFileContent($path);
	$needles = array(
		'getProductAttributes',
		'getProductSpecials',
		'getProductImages',
		'product_seo_url',
		'product_category',
		'stock_status_id',
		'weight_class_id',
	);
	$missing = array();

	foreach ($needles as $needle) {
		if (!str_contains($content, $needle)) {
			$missing[] = $needle;
		}
	}

	return array(
		'name' => 'Сохранение структуры товара',
		'ok' => count($missing) === 0,
		'message' => count($missing) === 0 ? 'контроллер товара загружает управляемые категории, атрибуты, фото, акции и SEO URL' : 'нет обработки: ' . implode(', ', $missing)
	);
}

function checkProductFrontendAlt(string $controllerPath, string $templatePath): array {
	$controller = readFileContent($controllerPath);
	$template = readFileContent($templatePath);
	$ok = str_contains($controller, 'image_alt') && str_contains($controller, 'main_image_alt') && str_contains($template, 'alt="{{ main_image_alt }}"') && str_contains($template, 'alt="{{ image.alt }}"');

	return array(
		'name' => 'Alt изображений товара',
		'ok' => $ok,
		'message' => $ok ? 'alt основного фото управляется атрибутом, дополнительные фото имеют fallback alt' : 'нет управляемого/fallback alt в товаре'
	);
}

function checkShippingCheckout(string $controllerPath, string $templatePath): array {
	$controller = readFileContent($controllerPath);
	$template = readFileContent($templatePath);
	$ok = str_contains($controller, 'shipping_methods') && str_contains($controller, 'getQuote') && str_contains($template, 'shipping_method') && str_contains($template, 'radio');

	return array(
		'name' => 'Вывод методов доставки в checkout',
		'ok' => $ok,
		'message' => $ok ? 'checkout получает quotes модулей доставки и выводит radio-список методов' : 'нет корректного получения или вывода shipping methods'
	);
}

function readFileContent(string $path): string {
	$content = is_file($path) ? file_get_contents($path) : false;

	if (!is_string($content)) {
		return '';
	}

	return $content;
}
