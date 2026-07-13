<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$themeRoot = $root . '/upload/catalog/view/theme/charm_by_sylora';
$header = readFileContent($themeRoot . '/template/common/header.twig');
$commonJs = readFileContent($root . '/upload/catalog/view/javascript/common.js');
$css = readFileContent($themeRoot . '/stylesheet/stylesheet.css');
$targets = array('Chrome current', 'Safari current', 'Firefox current', 'Edge current', 'iOS Safari current', 'Android Chrome current');

$checks = array(
	checkTargets($targets),
	checkContains($header, 'function getStoredTheme()', 'Safari/iOS localStorage read', 'чтение localStorage защищено try/catch'),
	checkContains($header, 'function setStoredTheme(theme)', 'Safari/iOS localStorage write', 'запись localStorage защищена try/catch'),
	checkNoModernSyntax($header . "\n" . $commonJs),
	checkContains($css, '@media (max-width: 414px)', 'Мобильные браузеры', 'есть отдельные правила для узких iOS/Android экранов'),
	checkContains($css, ':root {', 'CSS variables', 'палитра задана через поддерживаемые современными браузерами CSS-переменные'),
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

function checkTargets(array $targets): array {
	return array(
		'name' => 'Целевые браузеры',
		'ok' => count($targets) === 6,
		'message' => implode(', ', $targets)
	);
}

function checkContains(string $content, string $needle, string $name, string $okMessage): array {
	return array(
		'name' => $name,
		'ok' => str_contains($content, $needle),
		'message' => str_contains($content, $needle) ? $okMessage : 'не найдено: ' . $needle
	);
}

function checkNoModernSyntax(string $javascript): array {
	$forbidden = array('?.', '??', '=>', 'async function', 'for await');
	$found = array();

	foreach ($forbidden as $needle) {
		if (str_contains($javascript, $needle)) {
			$found[] = $needle;
		}
	}

	return array(
		'name' => 'JS-синтаксис темы',
		'ok' => count($found) === 0,
		'message' => count($found) === 0 ? 'используется консервативный синтаксис без транспиляции' : 'найдено: ' . implode(', ', $found)
	);
}

function readFileContent(string $path): string {
	$content = is_file($path) ? file_get_contents($path) : false;

	if (!is_string($content)) {
		return '';
	}

	return $content;
}
