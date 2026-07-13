<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$cssPath = $root . '/upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css';
$css = readFileContent($cssPath);
$themeCss = extractThemeCss($css);

$checks = array(
	checkNoKeyframes($themeCss),
	checkTransitionDurations($themeCss),
	checkReducedMotion($themeCss),
	checkReducedMotionCoversTransitions($themeCss),
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

function checkNoKeyframes(string $css): array {
	$hasKeyframes = (bool)preg_match('/@(?:-[a-z]+-)?keyframes\b/i', $css);
	$hasAnimation = (bool)preg_match('/\banimation(?:-[a-z-]+)?\s*:/i', removeReducedMotionBlock($css));

	return array(
		'name' => 'Декоративные анимации',
		'ok' => !$hasKeyframes && !$hasAnimation,
		'message' => !$hasKeyframes && !$hasAnimation ? 'в теме нет бесконечных keyframes/animation для витрины' : 'найдены keyframes или animation вне reduced-motion'
	);
}

function checkTransitionDurations(string $css): array {
	$violations = array();
	preg_match_all('/transition(?:-[a-z-]+)?\s*:\s*([^;}]+)/i', removeReducedMotionBlock($css), $matches);

	foreach ($matches[1] as $declaration) {
		preg_match_all('/(?<![\d.])(\d*\.?\d+)(ms|s)\b/i', $declaration, $durationMatches, PREG_SET_ORDER);

		foreach ($durationMatches as $durationMatch) {
			$milliseconds = strtolower($durationMatch[2]) === 's' ? (float)$durationMatch[1] * 1000 : (float)$durationMatch[1];

			if ($milliseconds < 150 || $milliseconds > 250) {
				$violations[] = trim($declaration);
				break;
			}
		}
	}

	return array(
		'name' => 'Длительность transitions',
		'ok' => count($violations) === 0,
		'message' => count($violations) === 0 ? 'все интерфейсные transitions находятся в диапазоне 150-250 ms' : 'вне диапазона: ' . implode('; ', array_slice($violations, 0, 5))
	);
}

function checkReducedMotion(string $css): array {
	$hasMedia = str_contains($css, '@media (prefers-reduced-motion: reduce)');
	$hasTransitionNone = str_contains($css, 'transition: none;');

	return array(
		'name' => 'prefers-reduced-motion',
		'ok' => $hasMedia && $hasTransitionNone,
		'message' => $hasMedia && $hasTransitionNone ? 'есть отдельный reduced-motion блок с отключением transitions' : 'нет полного reduced-motion блока'
	);
}

function checkReducedMotionCoversTransitions(string $css): array {
	$reducedMotion = extractReducedMotionBlock($css);
	$requiredSelectors = array(
		'.sylora-product-preview__image img',
		'.catalog-filters__summary::after',
		'.catalog-card__image img',
		'.content-card',
	);
	$missing = array();

	foreach ($requiredSelectors as $selector) {
		if (!str_contains($reducedMotion, $selector)) {
			$missing[] = $selector;
		}
	}

	return array(
		'name' => 'Покрытие reduced-motion',
		'ok' => count($missing) === 0,
		'message' => count($missing) === 0 ? 'все текущие transition-селекторы темы покрыты reduced-motion' : 'не покрыты: ' . implode(', ', $missing)
	);
}

function removeReducedMotionBlock(string $css): string {
	return preg_replace('/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{(?:[^{}]|\{[^{}]*\})*\}/is', '', $css) ?? $css;
}

function extractReducedMotionBlock(string $css): string {
	if (!preg_match('/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{((?:[^{}]|\{[^{}]*\})*)\}/is', $css, $matches)) {
		return '';
	}

	return $matches[1];
}

function readFileContent(string $path): string {
	$content = is_file($path) ? file_get_contents($path) : false;

	if (!is_string($content)) {
		return '';
	}

	return $content;
}
