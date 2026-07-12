<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checkOnly = in_array('--check', $argv, true);

$assets = [
	[
		'type' => 'css',
		'source' => $root . '/upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css',
		'target' => $root . '/upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.min.css',
	],
	[
		'type' => 'js',
		'source' => $root . '/upload/catalog/view/javascript/common.js',
		'target' => $root . '/upload/catalog/view/javascript/common.min.js',
	],
];

$hasError = false;

foreach ($assets as $asset) {
	if (!isset($asset['type'], $asset['source'], $asset['target'])) {
		fwrite(STDERR, "Invalid asset configuration.\n");
		exit(1);
	}

	if (!is_file($asset['source'])) {
		fwrite(STDERR, sprintf("Source asset does not exist: %s\n", $asset['source']));
		$hasError = true;
		continue;
	}

	$source = file_get_contents($asset['source']);

	if (!is_string($source)) {
		fwrite(STDERR, sprintf("Unable to read source asset: %s\n", $asset['source']));
		$hasError = true;
		continue;
	}

	$minified = $asset['type'] === 'css' ? minifyCss($source) : minifyJs($source);

	if ($checkOnly) {
		$current = is_file($asset['target']) ? file_get_contents($asset['target']) : false;

		if ($current !== $minified) {
			fwrite(STDERR, sprintf("Built asset is outdated: %s\n", $asset['target']));
			$hasError = true;
		}

		continue;
	}

	if (file_put_contents($asset['target'], $minified) === false) {
		fwrite(STDERR, sprintf("Unable to write built asset: %s\n", $asset['target']));
		$hasError = true;
		continue;
	}

	printf("Built %s\n", getRelativePath($root, $asset['target']));
}

if ($hasError) {
	exit(1);
}

function minifyCss(string $css): string {
	$css = preg_replace('~/\*.*?\*/~s', '', $css);
	$css = preg_replace('/\s+/', ' ', (string)$css);
	$css = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', (string)$css);
	$css = str_replace([';}', ' 0px', ':0px'], ['}', ' 0', ':0'], (string)$css);

	return trim($css) . "\n";
}

function minifyJs(string $js): string {
	$js = preg_replace('~/\*.*?\*/~s', '', $js);
	$lines = preg_split('/\R/', (string)$js);
	$minifiedLines = [];

	if (!is_array($lines)) {
		return trim($js) . "\n";
	}

	foreach ($lines as $line) {
		$line = trim($line);

		if ($line === '' || str_starts_with($line, '//')) {
			continue;
		}

		$minifiedLines[] = $line;
	}

	$js = implode("\n", $minifiedLines);
	$js = preg_replace('/[ \t]+/', ' ', $js);
	$js = preg_replace('/ ?([{}();,:=+\-*<>]) ?/', '$1', (string)$js);

	return trim((string)$js) . "\n";
}

function getRelativePath(string $root, string $path): string {
	$relative = str_replace($root . '/', '', $path);

	return $relative === $path ? $path : $relative;
}
