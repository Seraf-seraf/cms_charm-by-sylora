<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$themeRoot = $root . '/upload/catalog/view/theme/charm_by_sylora';
$css = readFileContent($themeRoot . '/stylesheet/stylesheet.css');
$themeCss = extractThemeCss($css);
$templateRoot = $themeRoot . '/template';

$checks = array(
	checkNoNeedles($themeCss, array('#23a1d1', '#229ac8', '#1f90bb', 'linear-gradient(to bottom,#23a1d1'), 'Дефолтная синяя палитра OpenCart'),
	checkContains($themeCss, '.btn-default', 'Кнопки Bootstrap/OpenCart', 'дефолтные кнопки переопределены палитрой темы'),
	checkContains($themeCss, '.form-control:focus', 'Поля форм', 'focus-состояния используют брендовый стиль'),
	checkContains($themeCss, '.panel,', 'Панели и системные блоки', 'панели, well, thumbnail и dropdown приведены к теме'),
	checkTemplatesDoNotReferenceDefaultTheme($templateRoot),
	checkContains(readFileContent($templateRoot . '/extension/module/carousel.twig'), 'Брендовый carousel/swiper отключен', 'Carousel брендов', 'дефолтный carousel-модуль не выводится'),
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

function extractThemeCss(string $css): string {
	$marker = '/* Charm by Sylora theme */';
	$position = strpos($css, $marker);

	if (!is_int($position)) {
		return '';
	}

	return substr($css, $position);
}

function checkContains(string $content, string $needle, string $name, string $okMessage): array {
	return array(
		'name' => $name,
		'ok' => str_contains($content, $needle),
		'message' => str_contains($content, $needle) ? $okMessage : 'не найдено: ' . $needle
	);
}

function checkNoNeedles(string $content, array $needles, string $name): array {
	$found = array();

	foreach ($needles as $needle) {
		if (str_contains($content, $needle)) {
			$found[] = $needle;
		}
	}

	return array(
		'name' => $name,
		'ok' => count($found) === 0,
		'message' => count($found) === 0 ? 'в кастомном CSS темы не найдены дефолтные OpenCart-сигнатуры' : 'найдено: ' . implode(', ', $found)
	);
}

function checkTemplatesDoNotReferenceDefaultTheme(string $templateRoot): array {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templateRoot));
	$violations = array();

	foreach ($iterator as $file) {
		if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'twig') {
			continue;
		}

		$content = readFileContent($file->getPathname());

		if (str_contains($content, 'view/theme/default')) {
			$violations[] = str_replace($templateRoot . '/', '', $file->getPathname());
		}
	}

	return array(
		'name' => 'Ссылки на default theme',
		'ok' => count($violations) === 0,
		'message' => count($violations) === 0 ? 'активные Twig-шаблоны не ссылаются на view/theme/default' : implode(', ', $violations)
	);
}

function readFileContent(string $path): string {
	$content = is_file($path) ? file_get_contents($path) : false;

	if (!is_string($content)) {
		return '';
	}

	return $content;
}
