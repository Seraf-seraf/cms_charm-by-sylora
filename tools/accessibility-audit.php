<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$themeRoot = $root . '/upload/catalog/view/theme/charm_by_sylora';
$templateRoot = $themeRoot . '/template';
$css = readFileContent($themeRoot . '/stylesheet/stylesheet.css');

$checks = array(
	checkContrastTokens($css),
	checkContains($css, ':focus-visible', 'Видимый focus', 'есть общий focus-visible outline для интерактивных элементов'),
	checkContains(readFileContent($templateRoot . '/common/header.twig'), 'getNavFocusable', 'Клавиатура: мобильное меню', 'мобильное меню удерживает фокус при открытии'),
	checkContains(readFileContent($templateRoot . '/common/header.twig'), 'aria-expanded', 'ARIA: мобильное меню', 'кнопка меню синхронизирует aria-expanded'),
	checkContains(readFileContent($templateRoot . '/common/search.twig'), 'aria-label="{{ text_search }}"', 'ARIA: поиск', 'input и кнопка поиска имеют aria-label'),
	checkContains(readFileContent($templateRoot . '/common/cart.twig'), 'aria-label="{{ text_cart }}"', 'ARIA: мини-корзина', 'иконка мини-корзины имеет aria-label'),
	checkContains(readFileContent($templateRoot . '/common/cart.twig'), 'aria-label="{{ button_remove }}"', 'ARIA: удаление из корзины', 'иконки удаления имеют aria-label'),
	checkTemplateImages($templateRoot),
	checkContains(readFileContent($templateRoot . '/information/contact.twig'), 'aria-describedby="error-name"', 'Ошибки форм: имя', 'ошибка имени связана с полем'),
	checkContains(readFileContent($templateRoot . '/information/contact.twig'), 'aria-describedby="error-email"', 'Ошибки форм: email', 'ошибка email связана с полем'),
	checkContains(readFileContent($templateRoot . '/information/contact.twig'), 'aria-describedby="error-enquiry"', 'Ошибки форм: сообщение', 'ошибка сообщения связана с полем'),
	checkContains(readFileContent($templateRoot . '/information/contact.twig'), 'aria-describedby="error-privacy"', 'Ошибки форм: согласие', 'ошибка согласия связана с полем'),
	checkLabels(readFileContent($templateRoot . '/information/contact.twig'), 'Контактная форма'),
	checkLabels(readFileContent($templateRoot . '/product/category.twig'), 'Фильтры каталога'),
	checkContains(readFileContent($templateRoot . '/product/category.twig'), 'aria-haspopup="dialog"', 'ARIA: быстрый просмотр', 'кнопка быстрого просмотра объявляет dialog'),
	checkContains(readFileContent($templateRoot . '/product/category.twig'), 'aria-labelledby="quick-view-title-', 'Модальное окно: имя', 'dialog получает доступное имя через aria-labelledby'),
	checkContains(readFileContent($templateRoot . '/product/category.twig'), 'lastQuickViewTrigger.focus()', 'Модальное окно: возврат фокуса', 'после закрытия quick-view фокус возвращается на кнопку'),
	checkContains(readFileContent($templateRoot . '/product/category.twig'), 'event.key !== \'Tab\'', 'Модальное окно: focus trap', 'quick-view удерживает фокус клавишей Tab'),
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
	$ok = str_contains($content, $needle);

	return array(
		'name' => $name,
		'ok' => $ok,
		'message' => $ok ? $okMessage : 'не найдено: ' . $needle
	);
}

function checkContrastTokens(string $css): array {
	$expected = array(
		'--color-text: #2a2421;',
		'--color-muted: #6f625c;',
		'--color-earth: #785b44;',
		'--color-bg: #ffffff;',
		'--color-text: #f5efea;',
		'--color-muted: #c8b9b0;',
		'--color-bg: #171312;',
	);
	$missing = array();

	foreach ($expected as $token) {
		if (!str_contains($css, $token)) {
			$missing[] = $token;
		}
	}

	return array(
		'name' => 'Контраст WCAG AA',
		'ok' => count($missing) === 0,
		'message' => count($missing) === 0 ? 'основные текстовые токены светлой и темной темы заданы высококонтрастными парами' : 'нет токенов: ' . implode(', ', $missing)
	);
}

function checkTemplateImages(string $templateRoot): array {
	$files = array(
		$templateRoot . '/common/header.twig',
		$templateRoot . '/common/home.twig',
		$templateRoot . '/product/category.twig',
		$templateRoot . '/product/product.twig',
		$templateRoot . '/checkout/cart.twig',
		$templateRoot . '/common/cart.twig',
		$templateRoot . '/information/contact.twig',
	);
	$violations = array();

	foreach ($files as $file) {
		$content = readFileContent($file);
		preg_match_all('/<img\b[^>]*>/i', $content, $matches);

		foreach ($matches[0] as $img) {
			if (!preg_match('/\balt=/', $img)) {
				$violations[] = basename($file) . ': ' . trim($img);
			}
		}
	}

	return array(
		'name' => 'Alt изображений',
		'ok' => count($violations) === 0,
		'message' => count($violations) === 0 ? 'ключевые изображения имеют осмысленный или пустой декоративный alt' : implode('; ', array_slice($violations, 0, 3))
	);
}

function checkLabels(string $content, string $name): array {
	preg_match_all('/<(input|select|textarea)\b(?![^>]*type=["\'](?:hidden|submit|button)["\'])[^>]*>/i', $content, $matches);
	$violations = array();

	foreach ($matches[0] as $control) {
		if (!preg_match('/\bid=["\']([^"\']+)["\']/', $control, $idMatch)) {
			if (!str_contains($control, 'type="radio"') && !str_contains($control, 'type="checkbox"')) {
				$violations[] = trim($control);
			}

			continue;
		}

		if (!str_contains($content, 'for="' . $idMatch[1] . '"')) {
			$violations[] = trim($control);
		}
	}

	return array(
		'name' => 'Label: ' . $name,
		'ok' => count($violations) === 0,
		'message' => count($violations) === 0 ? 'видимые поля имеют label' : implode('; ', array_slice($violations, 0, 3))
	);
}

function readFileContent(string $path): string {
	$content = is_file($path) ? file_get_contents($path) : false;

	if (!is_string($content)) {
		return '';
	}

	return $content;
}
