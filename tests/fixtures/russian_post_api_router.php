<?php
$body = json_decode(file_get_contents('php://input'), true);
$headers = function_exists('getallheaders') ? getallheaders() : array();

if ($_SERVER['REQUEST_URI'] !== '/1.0/tariff' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(404);
	exit;
}

if (($headers['Authorization'] ?? '') !== 'AccessToken test-token' || ($headers['X-User-Authorization'] ?? '') !== 'Basic ' . base64_encode('test-login:test-password')) {
	http_response_code(401);
	echo json_encode(array('error' => 'invalid auth'));
	exit;
}

if (($body['index-from'] ?? '') !== '644000' || ($body['index-to'] ?? '') !== '101000' || ($body['mass'] ?? 0) !== 1000 || ($body['declared-value'] ?? 0) !== 150000) {
	http_response_code(422);
	echo json_encode(array('error' => 'invalid request'));
	exit;
}

header('Content-Type: application/json');
echo json_encode(array('total-rate' => 34567, 'delivery-time' => array('min-days' => 3, 'max-days' => 5)));
