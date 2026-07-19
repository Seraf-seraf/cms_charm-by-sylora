<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestBootstrap.php';

abstract class BrowserTestCase extends TestCase {
	private static ?TestBootstrap $bootstrap = null;

	public static function setUpBeforeClass(): void {
		self::$bootstrap = new TestBootstrap();
		self::$bootstrap->start();
	}

	public static function tearDownAfterClass(): void {
		$bootstrap = self::$bootstrap;

		if ($bootstrap instanceof TestBootstrap) {
			$bootstrap->stop();
		}
	}

	protected function setUp(): void {
		$bootstrap = self::getBootstrap();

		if ($bootstrap->getUnavailableReason() !== '') {
			self::markTestSkipped($bootstrap->getUnavailableReason());
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	final protected function runBrowserScenario(string $runner, string $scenario): array {
		$bootstrap = self::getBootstrap();
		$command = array(
			$bootstrap->getNodeExecutable(),
			'--experimental-websocket',
			$runner,
			$bootstrap->getUrl(),
			$scenario,
			$bootstrap->getChromeExecutable(),
		);
		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$pipes = array();
		$process = proc_open($command, $descriptors, $pipes, $bootstrap->getRoot());

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

		return $result;
	}

	private static function getBootstrap(): TestBootstrap {
		$bootstrap = self::$bootstrap;

		if (!$bootstrap instanceof TestBootstrap) {
			throw new RuntimeException('Bootstrap тестов не запущен.');
		}

		return $bootstrap;
	}
}
