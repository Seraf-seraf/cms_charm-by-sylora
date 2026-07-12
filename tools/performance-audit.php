<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$baseUrl = rtrim((string)getenv('PERFORMANCE_BASE_URL'), '/');
$checks = [];

$checks[] = checkFileSize($root . '/upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.min.css', 120 * 1024, 'Минифицированный CSS темы');
$checks[] = checkFileSize($root . '/upload/catalog/view/javascript/common.min.js', 80 * 1024, 'Минифицированный common.js');
$checks[] = checkTemplateImages($root . '/upload/catalog/view/theme/charm_by_sylora/template');
$checks[] = checkModernImageSources($root . '/upload/catalog/model/tool/image.php', $root . '/upload/catalog/view/theme/charm_by_sylora/template');

if ($baseUrl !== '') {
	$checks[] = checkPageSize($baseUrl . '/', 160 * 1024, 'Главная страница');
	$checks[] = checkPageSize($baseUrl . '/index.php?route=product/search', 180 * 1024, 'Страница каталога/поиска');
	$checks[] = checkPageSize($baseUrl . '/index.php?route=product/product&product_id=1', 220 * 1024, 'Страница товара');
} else {
	$checks[] = array(
		'name' => 'HTML-размер страниц',
		'ok' => true,
		'message' => 'Пропущено: задайте PERFORMANCE_BASE_URL для проверки рабочей витрины.'
	);
}

$failed = false;

foreach ($checks as $check) {
	$status = $check['ok'] ? 'OK' : 'FAIL';
	printf("[%s] %s: %s\n", $status, $check['name'], $check['message']);

	if (!$check['ok']) {
		$failed = true;
	}
}

exit($failed ? 1 : 0);

function checkFileSize(string $path, int $limit, string $name): array {
	if (!is_file($path)) {
		return array(
			'name' => $name,
			'ok' => false,
			'message' => 'файл не найден'
		);
	}

	$size = filesize($path);

	if (!is_int($size)) {
		return array(
			'name' => $name,
			'ok' => false,
			'message' => 'не удалось получить размер файла'
		);
	}

	return array(
		'name' => $name,
		'ok' => $size <= $limit,
		'message' => formatBytes($size) . ' / лимит ' . formatBytes($limit)
	);
}

function checkTemplateImages(string $templateRoot): array {
	$files = getTwigFiles($templateRoot);
	$violations = [];

	foreach ($files as $file) {
		$relative = str_replace($templateRoot . '/', '', $file);

		if (str_starts_with($relative, 'mail/')) {
			continue;
		}

		$content = file_get_contents($file);

		if (!is_string($content)) {
			$violations[] = $relative . ': файл не читается';
			continue;
		}

		preg_match_all('/<img\b[^>]*>/i', $content, $matches);

		foreach ($matches[0] as $img) {
			if (str_contains($img, 'mc.yandex.ru/watch')) {
				continue;
			}

			if (!preg_match('/\bwidth=/', $img) || !preg_match('/\bheight=/', $img)) {
				$violations[] = $relative . ': ' . trim($img);
			}
		}
	}

	return array(
		'name' => 'Стабильные размеры изображений',
		'ok' => count($violations) === 0,
		'message' => count($violations) === 0 ? 'у runtime-изображений заданы width/height' : implode('; ', array_slice($violations, 0, 5))
	);
}

function checkModernImageSources(string $modelPath, string $templateRoot): array {
	$model = is_file($modelPath) ? file_get_contents($modelPath) : '';
	$home = file_get_contents($templateRoot . '/common/home.twig');
	$category = file_get_contents($templateRoot . '/product/category.twig');
	$product = file_get_contents($templateRoot . '/product/product.twig');

	$ok = is_string($model)
		&& is_string($home)
		&& is_string($category)
		&& is_string($product)
		&& str_contains($model, 'resizeWithSources')
		&& str_contains($model, 'imagewebp')
		&& str_contains($model, 'imageavif')
		&& str_contains($home, '<picture>')
		&& str_contains($category, '<picture>')
		&& str_contains($product, '<picture>');

	return array(
		'name' => 'WebP/AVIF и fallback',
		'ok' => $ok,
		'message' => $ok ? 'resizeWithSources и picture fallback подключены' : 'не найден один из обязательных элементов modern image pipeline'
	);
}

function checkPageSize(string $url, int $limit, string $name): array {
	$context = stream_context_create(array(
		'http' => array(
			'timeout' => 15,
			'ignore_errors' => true
		)
	));
	$content = file_get_contents($url, false, $context);

	if (!is_string($content)) {
		return array(
			'name' => $name,
			'ok' => false,
			'message' => 'страница недоступна: ' . $url
		);
	}

	$size = strlen($content);

	return array(
		'name' => $name,
		'ok' => $size <= $limit,
		'message' => formatBytes($size) . ' / лимит ' . formatBytes($limit)
	);
}

function getTwigFiles(string $directory): array {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
	$files = [];

	foreach ($iterator as $file) {
		if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'twig') {
			continue;
		}

		$files[] = $file->getPathname();
	}

	sort($files);

	return $files;
}

function formatBytes(int $bytes): string {
	return sprintf('%.1f KB', $bytes / 1024);
}
