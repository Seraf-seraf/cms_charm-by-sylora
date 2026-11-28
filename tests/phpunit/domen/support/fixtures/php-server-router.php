<?php

declare(strict_types=1);

$documentRoot = __DIR__ . '/public';
$indexPath = $documentRoot . '/index.php';

if (!is_file($indexPath)) {
	http_response_code(500);
	return true;
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);

if (!is_string($requestPath) || $requestPath === '') {
	$requestPath = '/';
}

$requestPath = rawurldecode($requestPath);

if (
	!str_starts_with($requestPath, '/')
	|| str_contains($requestPath, "\0")
	|| preg_match('~(?:^|/)\.\.(?:/|$)~', $requestPath) === 1
) {
	http_response_code(404);
	return true;
}

if ($requestPath === '/' || $requestPath === '/index.php') {
	return false;
}

if ($requestPath === '/catalog' || str_starts_with($requestPath, '/catalog/')) {
	return false;
}

if ($requestPath === '/image' || str_starts_with($requestPath, '/image/')) {
	return false;
}

$resourcePath = $documentRoot . $requestPath;

if (is_file($resourcePath) || is_dir($resourcePath)) {
	return false;
}

$routePath = trim($requestPath, '/');

if ($routePath === '') {
	return false;
}

if (!isset($_GET['route']) && !isset($_GET['_route_'])) {
	$_GET['_route_'] = $routePath;
}

require $indexPath;

return true;
