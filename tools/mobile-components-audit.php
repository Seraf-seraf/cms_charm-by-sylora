<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$themeRoot = $root . '/upload/catalog/view/theme/charm_by_sylora';
$templateRoot = $themeRoot . '/template';
$css = readFileContent($themeRoot . '/stylesheet/stylesheet.css');

$checks = array(
	checkContains(readFileContent($templateRoot . '/common/header.twig'), 'mobile-nav-toggle', 'Мобильное меню', 'есть кнопка мобильного меню'),
	checkContains($css, '.site-nav.is-open', 'Меню на мобильных', 'меню открывается как мобильная панель'),
	checkContains(readFileContent($templateRoot . '/product/category.twig'), 'catalog-filters', 'Фильтры каталога', 'фильтры доступны через details/summary'),
	checkContains($css, '.catalog-filters__grid', 'Фильтры на мобильных', 'сетка фильтров адаптируется'),
	checkContains(readFileContent($templateRoot . '/product/category.twig'), 'catalog-grid', 'Каталог', 'каталог выводится адаптивной сеткой'),
	checkContains(readFileContent($templateRoot . '/product/category.twig'), 'quick-view', 'Модальные окна карточек', 'быстрый просмотр доступен через dialog'),
	checkContains($css, '.quick-view__grid', 'Модальные окна на мобильных', 'модальное окно имеет адаптивную сетку'),
	checkContains(readFileContent($templateRoot . '/checkout/cart.twig'), 'cart-layout', 'Корзина', 'страница корзины использует адаптивный layout'),
	checkContains(readFileContent($templateRoot . '/common/cart.twig'), 'mini-cart', 'Мини-корзина', 'мини-корзина ограничена шириной viewport'),
	checkContains(readFileContent($templateRoot . '/checkout/checkout.twig'), 'checkout-accordion', 'Checkout', 'checkout оформлен как адаптивный accordion'),
	checkContains($css, '.checkout-accordion .form-control', 'Формы checkout', 'inputs checkout стилизованы для мобильных'),
	checkContains($css, '.btn-primary', 'Кнопки', 'основные кнопки имеют theme override'),
	checkContains($css, 'overflow-wrap: anywhere;', 'Длинный текст', 'длинный текст переносится'),
	checkTemplateImages($templateRoot),
	checkSearchAccessibility($templateRoot . '/common/search.twig'),
	checkMiniCartAccessibility($templateRoot . '/common/cart.twig'),
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

function checkContains(string $content, string $needle, string $name, string $okMessage): array {
	return array(
		'name' => $name,
		'ok' => str_contains($content, $needle),
		'message' => str_contains($content, $needle) ? $okMessage : 'не найдено: ' . $needle
	);
}

function checkTemplateImages(string $templateRoot): array {
	$files = array(
		$templateRoot . '/common/home.twig',
		$templateRoot . '/product/category.twig',
		$templateRoot . '/product/product.twig',
		$templateRoot . '/checkout/cart.twig',
		$templateRoot . '/common/cart.twig',
	);
	$violations = array();

	foreach ($files as $file) {
		$content = readFileContent($file);
		preg_match_all('/<img\b[^>]*>/i', $content, $matches);

		foreach ($matches[0] as $img) {
			if (!preg_match('/\balt=/', $img) || !preg_match('/\bwidth=/', $img) || !preg_match('/\bheight=/', $img)) {
				$violations[] = basename($file) . ': ' . trim($img);
			}
		}
	}

	return array(
		'name' => 'Изображения',
		'ok' => count($violations) === 0,
		'message' => count($violations) === 0 ? 'в ключевых мобильных шаблонах есть alt/width/height' : implode('; ', array_slice($violations, 0, 3))
	);
}

function checkSearchAccessibility(string $path): array {
	$content = readFileContent($path);
	$ok = str_contains($content, 'aria-label="{{ text_search }}"') && str_contains($content, 'class="btn btn-default btn-lg" aria-label="{{ text_search }}"');

	return array(
		'name' => 'Поиск в шапке',
		'ok' => $ok,
		'message' => $ok ? 'input и кнопка поиска имеют aria-label' : 'нет aria-label у input или кнопки поиска'
	);
}

function checkMiniCartAccessibility(string $path): array {
	$content = readFileContent($path);
	$ok = str_contains($content, 'aria-label="{{ text_cart }}"') && substr_count($content, 'aria-label="{{ button_remove }}"') >= 2;

	return array(
		'name' => 'Кнопки мини-корзины',
		'ok' => $ok,
		'message' => $ok ? 'иконки корзины и удаления имеют aria-label' : 'нет aria-label у одной из иконок мини-корзины'
	);
}

function readFileContent(string $path): string {
	$content = is_file($path) ? file_get_contents($path) : false;

	if (!is_string($content)) {
		return '';
	}

	return $content;
}
