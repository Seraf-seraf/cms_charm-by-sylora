<?php

declare(strict_types=1);

final class TestBootstrap {
	private string $root;
	private string $url = '';
	private string $unavailableReason = '';
	private ?mysqli $database = null;
	private string $originalCounter = '';
	private string $originalStatus = '';
	private mixed $serverProcess = null;

	public function start(): void {
		$this->root = dirname(__DIR__, 4);
		$config = $this->root . '/upload/config.php';

		if (!is_file($config)) {
			$this->unavailableReason = 'Локальный upload/config.php отсутствует.';
			return;
		}

		require_once $config;

		if (!defined('HTTP_SERVER') || !defined('DB_HOSTNAME')) {
			$this->unavailableReason = 'Локальная конфигурация OpenCart неполна.';
			return;
		}

		if ($this->findExecutable('node') === '' || $this->findChrome() === '') {
			$this->unavailableReason = 'Для browser-тестов требуются Node.js и Chrome/Chromium.';
			return;
		}

		$serverUrl = getenv('SYLORA_DOMAIN_TEST_URL');

		if (!is_string($serverUrl) || $serverUrl === '') {
			$serverUrl = (string)HTTP_SERVER;
		}

		$url = parse_url($serverUrl);

		if (!is_array($url)) {
			$this->unavailableReason = 'HTTP_SERVER содержит некорректный URL.';
			return;
		}

		$hostValue = $url['host'] ?? null;
		$portValue = $url['port'] ?? null;
		$host = is_string($hostValue) ? $hostValue : '';
		$port = is_int($portValue) ? $portValue : 80;

		if (!in_array($host, array('127.0.0.1', 'localhost'), true) || $port < 1) {
			$this->unavailableReason = 'Browser-тесты разрешено запускать только для локального HTTP_SERVER.';
			return;
		}

		$this->url = rtrim($serverUrl, '/') . '/index.php?route=common/home';

		try {
			$this->database = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
			$this->database->set_charset('utf8mb4');
			$this->enableMetricaForTests();
		} catch (Throwable $exception) {
			$this->unavailableReason = 'MySQL недоступен: ' . $exception->getMessage();
			return;
		}

		if (!$this->isPortOpen($host, $port)) {
			$this->startServer($host, $port);
		}

		if (!$this->waitForSite()) {
			$this->unavailableReason = 'Локальная витрина OpenCart не запустилась.';
		}
	}

	public function stop(): void {
		$database = $this->database;

		if ($database instanceof mysqli) {
			$this->restoreSetting('analytics_yandex_metrica_counter', $this->originalCounter);
			$this->restoreSetting('analytics_yandex_metrica_status', $this->originalStatus);

			$database->close();
		}

		if (is_resource($this->serverProcess)) {
			proc_terminate($this->serverProcess);
			proc_close($this->serverProcess);
		}
	}

	public function getUnavailableReason(): string {
		return $this->unavailableReason;
	}

	public function getRoot(): string {
		return $this->root;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function getNodeExecutable(): string {
		return $this->findExecutable('node');
	}

	public function getChromeExecutable(): string {
		return $this->findChrome();
	}

	private function enableMetricaForTests(): void {
		$database = $this->database;

		if (!$database instanceof mysqli) {
			throw new RuntimeException('Соединение с MySQL не установлено.');
		}

		$settings = array(
			'analytics_yandex_metrica_counter' => '110751771',
			'analytics_yandex_metrica_status' => '1',
		);

		foreach ($settings as $key => $testValue) {
			$result = $database->query(
				"SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE store_id = 0 AND `key` = '" .
				$database->real_escape_string($key) . "' LIMIT 1"
			);

			if (!$result instanceof mysqli_result || $result->num_rows !== 1) {
				throw new RuntimeException('Настройка ' . $key . ' не найдена.');
			}

			$row = $result->fetch_assoc();

			if (!is_array($row) || !isset($row['value']) || !is_string($row['value'])) {
				throw new RuntimeException('Настройка ' . $key . ' имеет некорректное значение.');
			}

			if ($key === 'analytics_yandex_metrica_counter') {
				$this->originalCounter = $row['value'];
			} else {
				$this->originalStatus = $row['value'];
			}

			$database->query(
				"UPDATE `" . DB_PREFIX . "setting` SET `value` = '" . $database->real_escape_string($testValue) .
				"' WHERE store_id = 0 AND `key` = '" . $database->real_escape_string($key) . "'"
			);
		}
	}

	private function restoreSetting(string $key, string $value): void {
		$database = $this->database;

		if (!$database instanceof mysqli) {
			return;
		}

		$database->query(
			"UPDATE `" . DB_PREFIX . "setting` SET `value` = '" . $database->real_escape_string($value) .
			"' WHERE store_id = 0 AND `key` = '" . $database->real_escape_string($key) . "'"
		);
	}

	private function startServer(string $host, int $port): void {
		$log = sys_get_temp_dir() . '/sylora-domain-php-server.log';
		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('file', $log, 'a'),
			2 => array('file', $log, 'a'),
		);
		$pipes = array();
		$this->serverProcess = proc_open(
			array(PHP_BINARY, '-S', $host . ':' . $port, '-t', $this->root . '/upload'),
			$descriptors,
			$pipes,
			$this->root
		);

		if (!is_resource($this->serverProcess)) {
			throw new RuntimeException('Не удалось запустить встроенный PHP-сервер.');
		}

		if (isset($pipes[0]) && is_resource($pipes[0])) {
			fclose($pipes[0]);
		}
	}

	private function waitForSite(): bool {
		for ($attempt = 0; $attempt < 40; $attempt++) {
			$context = stream_context_create(array('http' => array('timeout' => 1, 'ignore_errors' => true)));
			$response = @file_get_contents($this->url, false, $context);

			if (is_string($response) && $response !== '') {
				return true;
			}

			usleep(250000);
		}

		return false;
	}

	private function isPortOpen(string $host, int $port): bool {
		$socket = @fsockopen($host, $port, $errorCode, $errorMessage, 0.2);

		if (!is_resource($socket)) {
			return false;
		}

		fclose($socket);
		return true;
	}

	private function findChrome(): string {
		$configured = getenv('SYLORA_CHROME_BIN');

		if (is_string($configured) && is_executable($configured)) {
			return $configured;
		}

		foreach (array('google-chrome', 'chromium', 'chromium-browser') as $candidate) {
			$path = $this->findExecutable($candidate);

			if ($path !== '') {
				return $path;
			}
		}

		return '';
	}

	private function findExecutable(string $command): string {
		$output = array();
		$exitCode = 0;
		exec('command -v ' . escapeshellarg($command), $output, $exitCode);

		return $exitCode === 0 && isset($output[0]) ? trim((string)$output[0]) : '';
	}
}
