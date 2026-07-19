<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$extensionRoot = $root . '/extensions/yandex-metrica-consent/upload';
$checkOnly = in_array('--check', $argv, true);

if (!is_dir($extensionRoot)) {
	fwrite(STDERR, "Extension checkout is missing: extensions/yandex-metrica-consent\n");
	exit(1);
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($extensionRoot, FilesystemIterator::SKIP_DOTS)
);
$hasDifference = false;

foreach ($iterator as $file) {
	if (!$file instanceof SplFileInfo || !$file->isFile()) {
		continue;
	}

	$source = $file->getPathname();
	$relative = substr($source, strlen($extensionRoot) + 1);
	$target = $root . '/upload/' . $relative;
	$sourceContent = file_get_contents($source);
	$targetContent = is_file($target) ? file_get_contents($target) : false;

	if (is_string($sourceContent) && $sourceContent === $targetContent) {
		continue;
	}

	$hasDifference = true;

	if ($checkOnly) {
		fwrite(STDERR, 'Outdated extension file: upload/' . $relative . PHP_EOL);
		continue;
	}

	$targetDirectory = dirname($target);

	if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
		throw new RuntimeException('Unable to create directory: ' . $targetDirectory);
	}

	if (!copy($source, $target)) {
		throw new RuntimeException('Unable to copy extension file: ' . $relative);
	}

	echo 'Updated upload/' . $relative . PHP_EOL;
}

if ($checkOnly && $hasDifference) {
	exit(1);
}
