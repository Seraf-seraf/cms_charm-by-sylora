<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CookieConsentBrowserResult {
	public function __construct(
		public readonly bool $bannerHidden,
		public readonly bool $dataLayerExists,
		public readonly string $consent,
		public readonly int $metricaRequestCount,
		public readonly int $ymCookieCount,
		public readonly int $metricaScriptCount,
		public readonly int $metricaRequestAfterRefusalCount
	) {
	}
}

final class CookieConsentTest extends TestCase {
	private static string $root;
	private static string $url = '';
	private static string $unavailableReason = '';
	private static ?mysqli $database = null;
	private static string $originalCounter = '';
	private static string $originalStatus = '';
	private static mixed $serverProcess = null;

	public static function setUpBeforeClass(): void {
		self::$root = dirname(__DIR__, 4);
		$config = self::$root . '/upload/config.php';

		if (!is_file($config)) {
			self::$unavailableReason = 'Локальный upload/config.php отсутствует.';
			return;
		}

		require_once $config;

		if (!defined('HTTP_SERVER') || !defined('DB_HOSTNAME')) {
			self::$unavailableReason = 'Локальная конфигурация OpenCart неполна.';
			return;
		}

		if (self::findExecutable('node') === '' || self::findChrome() === '') {
			self::$unavailableReason = 'Для browser-тестов требуются Node.js и Chrome/Chromium.';
			return;
		}

		$serverUrl = getenv('SYLORA_DOMAIN_TEST_URL');

		if (!is_string($serverUrl) || $serverUrl === '') {
			$serverUrl = (string)HTTP_SERVER;
		}

		$url = parse_url($serverUrl);

		if (!is_array($url)) {
			self::$unavailableReason = 'HTTP_SERVER содержит некорректный URL.';
			return;
		}

		$hostValue = $url['host'] ?? null;
		$portValue = $url['port'] ?? null;
		$host = is_string($hostValue) ? $hostValue : '';
		$port = is_int($portValue) ? $portValue : 80;

		if (!in_array($host, array('127.0.0.1', 'localhost'), true) || $port < 1) {
			self::$unavailableReason = 'Browser-тесты разрешено запускать только для локального HTTP_SERVER.';
			return;
		}

		self::$url = rtrim($serverUrl, '/') . '/index.php?route=common/home';

		try {
			self::$database = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
			self::$database->set_charset('utf8mb4');
			self::enableMetricaForTests();
		} catch (Throwable $exception) {
			self::$unavailableReason = 'MySQL недоступен: ' . $exception->getMessage();
			return;
		}

		if (!self::isPortOpen($host, $port)) {
			self::startServer($host, $port);
		}

		if (!self::waitForSite()) {
			self::$unavailableReason = 'Локальная витрина OpenCart не запустилась.';
		}
	}

	public static function tearDownAfterClass(): void {
		$database = self::$database;

		if ($database instanceof mysqli) {
			self::restoreSetting('analytics_yandex_metrica_counter', self::$originalCounter);
			self::restoreSetting('analytics_yandex_metrica_status', self::$originalStatus);

			$database->close();
		}

		if (is_resource(self::$serverProcess)) {
			proc_terminate(self::$serverProcess);
			proc_close(self::$serverProcess);
		}
	}

	protected function setUp(): void {
		if (self::$unavailableReason !== '') {
			self::markTestSkipped(self::$unavailableReason);
		}
	}

	public function testMetricaIsNotRequestedBeforeConsent(): void {
		$result = $this->runBrowserScenario('initial');

		self::assertFalse($result->bannerHidden);
		self::assertFalse($result->dataLayerExists);
		self::assertSame(0, $result->metricaRequestCount);
		self::assertSame(0, $result->ymCookieCount);
		self::assertSame('', $result->consent);
	}

	public function testMetricaLoadsAfterExplicitConsent(): void {
		$result = $this->runBrowserScenario('accept');

		self::assertTrue($result->bannerHidden);
		self::assertTrue($result->dataLayerExists);
		self::assertSame('v1:analytics', $result->consent);
		self::assertGreaterThan(0, $result->metricaRequestCount);
		self::assertGreaterThan(0, $result->metricaScriptCount);
	}

	public function testRefusalRemovesMetricaCookiesAndPersists(): void {
		$result = $this->runBrowserScenario('refuse');

		self::assertTrue($result->bannerHidden);
		self::assertSame('v1:essential', $result->consent);
		self::assertSame(0, $result->ymCookieCount);
		self::assertSame(0, $result->metricaRequestAfterRefusalCount);
	}

	private static function enableMetricaForTests(): void {
		$database = self::$database;

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
				self::$originalCounter = $row['value'];
			} else {
				self::$originalStatus = $row['value'];
			}

			$database->query(
				"UPDATE `" . DB_PREFIX . "setting` SET `value` = '" . $database->real_escape_string($testValue) .
				"' WHERE store_id = 0 AND `key` = '" . $database->real_escape_string($key) . "'"
			);
		}
	}

	private static function restoreSetting(string $key, string $value): void {
		$database = self::$database;

		if (!$database instanceof mysqli) {
			return;
		}

		$database->query(
			"UPDATE `" . DB_PREFIX . "setting` SET `value` = '" . $database->real_escape_string($value) .
			"' WHERE store_id = 0 AND `key` = '" . $database->real_escape_string($key) . "'"
		);
	}

	private static function startServer(string $host, int $port): void {
		$log = sys_get_temp_dir() . '/sylora-domain-php-server.log';
		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('file', $log, 'a'),
			2 => array('file', $log, 'a'),
		);
		$pipes = array();
		self::$serverProcess = proc_open(
			array(PHP_BINARY, '-S', $host . ':' . $port, '-t', self::$root . '/upload'),
			$descriptors,
			$pipes,
			self::$root
		);

		if (!is_resource(self::$serverProcess)) {
			throw new RuntimeException('Не удалось запустить встроенный PHP-сервер.');
		}

		if (isset($pipes[0]) && is_resource($pipes[0])) {
			fclose($pipes[0]);
		}
	}

	private static function waitForSite(): bool {
		for ($attempt = 0; $attempt < 40; $attempt++) {
			$context = stream_context_create(array('http' => array('timeout' => 1, 'ignore_errors' => true)));
			$response = @file_get_contents(self::$url, false, $context);

			if (is_string($response) && str_contains($response, 'analytics-cookie-banner')) {
				return true;
			}

			usleep(250000);
		}

		return false;
	}

	private static function isPortOpen(string $host, int $port): bool {
		$socket = @fsockopen($host, $port, $errorCode, $errorMessage, 0.2);

		if (!is_resource($socket)) {
			return false;
		}

		fclose($socket);
		return true;
	}

	private function runBrowserScenario(string $scenario): CookieConsentBrowserResult {
		$runner = __DIR__ . '/support/cookie_consent_browser.mjs';
		$command = array(
			self::findExecutable('node'),
			'--experimental-websocket',
			$runner,
			self::$url,
			$scenario,
			self::findChrome(),
		);
		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$pipes = array();
		$process = proc_open($command, $descriptors, $pipes, self::$root);

		self::assertIsResource($process, 'Не удалось запустить browser-runner.');
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		self::assertSame(0, $exitCode, trim((string)$error));
		$result = json_decode((string)$output, true, 512, JSON_THROW_ON_ERROR);

		if (!is_array($result)) {
			throw new RuntimeException('Browser-runner вернул некорректный результат.');
		}

		return new CookieConsentBrowserResult(
			self::readBool($result, 'bannerHidden'),
			self::readBool($result, 'dataLayerExists'),
			self::readString($result, 'consent'),
			self::countStringList($result, 'metricaRequests'),
			self::countStringList($result, 'ymCookies'),
			self::countStringList($result, 'metricaScripts'),
			self::countStringList($result, 'metricaRequestsAfterRefusal')
		);
	}

	private static function readBool(mixed $result, string $key): bool {
		if (!is_array($result) || !isset($result[$key]) || !is_bool($result[$key])) {
			throw new RuntimeException('Поле ' . $key . ' отсутствует в результате browser-runner.');
		}

		return $result[$key];
	}

	private static function readString(mixed $result, string $key): string {
		if (!is_array($result) || !isset($result[$key]) || !is_string($result[$key])) {
			throw new RuntimeException('Поле ' . $key . ' отсутствует в результате browser-runner.');
		}

		return $result[$key];
	}

	private static function countStringList(mixed $result, string $key): int {
		if (!is_array($result) || !isset($result[$key]) || !is_array($result[$key])) {
			throw new RuntimeException('Поле ' . $key . ' отсутствует в результате browser-runner.');
		}

		foreach ($result[$key] as $value) {
			if (!is_string($value)) {
				throw new RuntimeException('Поле ' . $key . ' содержит некорректное значение.');
			}
		}

		return count($result[$key]);
	}

	private static function findChrome(): string {
		$configured = getenv('SYLORA_CHROME_BIN');

		if (is_string($configured) && is_executable($configured)) {
			return $configured;
		}

		foreach (array('google-chrome', 'chromium', 'chromium-browser') as $candidate) {
			$path = self::findExecutable($candidate);

			if ($path !== '') {
				return $path;
			}
		}

		return '';
	}

	private static function findExecutable(string $command): string {
		$output = array();
		$exitCode = 0;
		exec('command -v ' . escapeshellarg($command), $output, $exitCode);

		return $exitCode === 0 && isset($output[0]) ? trim((string)$output[0]) : '';
	}
}
