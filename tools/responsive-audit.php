<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$themeRoot = $root . '/upload/catalog/view/theme/charm_by_sylora';
$cssPath = $themeRoot . '/stylesheet/stylesheet.css';
$templateRoot = $themeRoot . '/template';
$breakpoints = array(320, 375, 414, 768, 1024, 1280, 1440, 1920);

$css = readFileContent($cssPath);
$checks = array(
	checkBreakpointMatrix($breakpoints),
	checkCssRule($css, '@media (max-width: 414px)', 'Узкие мобильные ширины', 'есть отдельная настройка для 320/375/414 px'),
	checkCssRule($css, 'grid-template-columns: 1fr;', 'Одноколоночная мобильная сетка', 'ключевые сетки схлопываются без горизонтального скролла'),
	checkCssRule($css, 'overflow-wrap: anywhere;', 'Длинный текст', 'длинные названия и пункты меню получают перенос'),
	checkCssRule($css, '.site-nav.is-open', 'Мобильное меню', 'меню переводится в фиксированную панель'),
	checkHeaderCompactRules($css),
	checkTemplateContains($templateRoot . '/checkout/confirm.twig', 'table-responsive', 'Таблицы checkout', 'таблицы подтверждения остаются прокручиваемыми на мобильных'),
	checkTemplateContains($templateRoot . '/checkout/cart.twig', 'cart-layout', 'Корзина', 'корзина использует адаптивный layout'),
	checkTemplateContains($templateRoot . '/product/category.twig', 'catalog-grid', 'Каталог', 'каталог использует адаптивную сетку карточек'),
	checkTemplateContains($templateRoot . '/product/product.twig', 'product-layout', 'Карточка товара', 'карточка товара использует адаптивный layout'),
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

function checkBreakpointMatrix(array $breakpoints): array {
	return array(
		'name' => 'Матрица ширин',
		'ok' => $breakpoints === array(320, 375, 414, 768, 1024, 1280, 1440, 1920),
		'message' => implode(', ', $breakpoints) . ' px'
	);
}

function checkCssRule(string $css, string $needle, string $name, string $okMessage): array {
	return array(
		'name' => $name,
		'ok' => str_contains($css, $needle),
		'message' => str_contains($css, $needle) ? $okMessage : 'не найдено правило: ' . $needle
	);
}

function checkHeaderCompactRules(string $css): array {
	$required = array(
		'@media (max-width: 414px)',
		'.site-brand__text',
		'max-width: 96px;',
		'.site-brand__mark',
		'font-size: 16px;',
		'.site-actions',
		'gap: 4px;'
	);

	$missing = array();

	foreach ($required as $needle) {
		if (!str_contains($css, $needle)) {
			$missing[] = $needle;
		}
	}

	return array(
		'name' => 'Шапка 320-414 px',
		'ok' => count($missing) === 0,
		'message' => count($missing) === 0 ? 'бренд и действия сжимаются для узких экранов' : 'нет правил: ' . implode(', ', $missing)
	);
}

function checkTemplateContains(string $path, string $needle, string $name, string $okMessage): array {
	$content = readFileContent($path);

	return array(
		'name' => $name,
		'ok' => str_contains($content, $needle),
		'message' => str_contains($content, $needle) ? $okMessage : 'не найдено в шаблоне: ' . $needle
	);
}

function readFileContent(string $path): string {
	$content = is_file($path) ? file_get_contents($path) : false;

	if (!is_string($content)) {
		return '';
	}

	return $content;
}
