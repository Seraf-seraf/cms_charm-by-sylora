<?php

declare(strict_types=1);

const CSP_REPORT_MAX_BODY_BYTES = 16384;
const CSP_REPORT_LIMIT_PER_MINUTE = 60;

require_once __DIR__ . '/config.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	header('Allow: POST');
	http_response_code(405);
	exit;
}

$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));

if (!in_array($contentType, array('application/csp-report', 'application/reports+json'), true)) {
	http_response_code(415);
	exit;
}

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > CSP_REPORT_MAX_BODY_BYTES) {
	http_response_code(413);
	exit;
}

if (!cspReportRateLimit(DIR_CACHE . 'csp-report-rate.json')) {
	header('Retry-After: 60');
	http_response_code(429);
	exit;
}

$rawBody = file_get_contents('php://input', false, null, 0, CSP_REPORT_MAX_BODY_BYTES + 1);

if (!is_string($rawBody) || strlen($rawBody) > CSP_REPORT_MAX_BODY_BYTES) {
	http_response_code(413);
	exit;
}

$payload = json_decode($rawBody, true);
$reports = cspReportItems($payload, $contentType);

foreach ($reports as $report) {
	$entry = cspSanitizeReport($report);

	if ($entry !== array()) {
		file_put_contents(DIR_LOGS . 'csp.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
	}
}

http_response_code(204);

function cspReportRateLimit(string $stateFile): bool {
	$handle = fopen($stateFile, 'c+');

	if ($handle === false || !flock($handle, LOCK_EX)) {
		if (is_resource($handle)) {
			fclose($handle);
		}

		return false;
	}

	$state = json_decode((string)stream_get_contents($handle), true);
	$minute = (int)floor(time() / 60);
	$count = is_array($state) && ($state['minute'] ?? null) === $minute ? (int)($state['count'] ?? 0) : 0;
	$allowed = $count < CSP_REPORT_LIMIT_PER_MINUTE;

	if ($allowed) {
		$count++;
	}

	ftruncate($handle, 0);
	rewind($handle);
	fwrite($handle, json_encode(array('minute' => $minute, 'count' => $count)));
	fflush($handle);
	flock($handle, LOCK_UN);
	fclose($handle);

	return $allowed;
}

function cspReportItems($payload, string $contentType): array {
	if (!is_array($payload)) {
		return array();
	}

	if ($contentType === 'application/csp-report') {
		$report = $payload['csp-report'] ?? null;

		return is_array($report) ? array($report) : array();
	}

	$reports = array();

	foreach ($payload as $item) {
		if (!is_array($item) || ($item['type'] ?? '') !== 'csp-violation' || !is_array($item['body'] ?? null)) {
			continue;
		}

		$reports[] = $item['body'];
	}

	return $reports;
}

function cspSanitizeReport(array $report): array {
	$directive = cspToken($report['effective-directive'] ?? $report['effectiveDirective'] ?? $report['violated-directive'] ?? '');

	if ($directive === '') {
		return array();
	}

	return array(
		'time' => gmdate('c'),
		'directive' => $directive,
		'blocked' => cspSource($report['blocked-uri'] ?? $report['blockedURL'] ?? ''),
		'document' => cspSource($report['document-uri'] ?? $report['documentURL'] ?? ''),
		'source' => cspSource($report['source-file'] ?? $report['sourceFile'] ?? ''),
		'status' => max(0, min(599, (int)($report['status-code'] ?? $report['statusCode'] ?? 0))),
		'disposition' => cspToken($report['disposition'] ?? '')
	);
}

function cspToken($value): string {
	return is_string($value) && preg_match('/^[a-z0-9-]{0,64}$/i', $value) ? strtolower($value) : '';
}

function cspSource($value): string {
	if (!is_string($value) || $value === '') {
		return '';
	}

	if (in_array($value, array('inline', 'eval', 'self', 'data', 'blob'), true)) {
		return $value;
	}

	$parts = parse_url($value);

	if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
		return '';
	}

	$scheme = strtolower((string)$parts['scheme']);

	if (!in_array($scheme, array('http', 'https', 'ws', 'wss'), true)) {
		return '';
	}

	$origin = $scheme . '://' . strtolower((string)$parts['host']);

	if (isset($parts['port'])) {
		$origin .= ':' . (int)$parts['port'];
	}

	return $origin;
}
