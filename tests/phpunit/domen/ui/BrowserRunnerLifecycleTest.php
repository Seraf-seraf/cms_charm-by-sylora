<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/BrowserTestCase.php';

final class BrowserRunnerLifecycleTest extends BrowserTestCase {
	private const RUNNER = __DIR__ . '/support/browser_runner_lifecycle.mjs';

	public function testFastRunnerReturnsJson(): void {
		$result = $this->runBrowserScenario(self::RUNNER, 'fast');

		self::assertSame(array('ok' => true), $result);
	}

	public function testRunnerReturnsJsonWhileDrainingLargeStdoutAndStderr(): void {
		$result = $this->runBrowserScenario(self::RUNNER, 'success');

		self::assertTrue($result['ok'] ?? false);
		self::assertSame(128 * 1024, strlen((string)($result['payload'] ?? '')));
	}

	public function testFailedSessionStartupIsBoundedAndDoesNotBlock(): void {
		$launcherDirectory = sys_get_temp_dir() . '/sylora-browser-launcher-' . bin2hex(random_bytes(8));
		mkdir($launcherDirectory, 0700);
		$launcherPath = $launcherDirectory . '/setsid';
		$launcher = "#!/bin/sh\ntrap '' TERM\nwhile :; do :; done\n";
		self::assertSame(strlen($launcher), file_put_contents($launcherPath, $launcher));
		chmod($launcherPath, 0700);
		$previousPath = getenv('PATH');
		putenv('PATH=' . $launcherDirectory . PATH_SEPARATOR . (is_string($previousPath) ? $previousPath : ''));
		$startedAt = microtime(true);
		$exception = null;

		try {
			$this->runBrowserScenario(self::RUNNER, 'fast');
		} catch (RuntimeException $caught) {
			$exception = $caught;
		} finally {
			self::restoreEnvironment('PATH', $previousPath);
			unlink($launcherPath);
			rmdir($launcherDirectory);
		}

		if (!$exception instanceof RuntimeException) {
			self::fail('Отказ launcher должен завершиться диагностируемой ошибкой старта.');
		}

		self::assertStringContainsString('Не удалось подтвердить изолированную session', $exception->getMessage());
		self::assertLessThan(7.0, microtime(true) - $startedAt, 'Отказ старта не должен приводить к неограниченному proc_close.');
		self::assertMatchesRegularExpression('/pid=(\d+)/', $exception->getMessage());
		preg_match('/pid=(\d+)/', $exception->getMessage(), $matches);
		self::assertFalse(posix_kill((int)$matches[1], 0), 'Launcher должен быть завершён после отказа handshake.');
	}

	public function testTimeoutKillsRunnerAndItsChildProcesses(): void {
		$markerPath = sys_get_temp_dir() . '/sylora-browser-runner-' . bin2hex(random_bytes(8)) . '.marker';
		$previousTimeout = getenv('SYLORA_BROWSER_RUNNER_TIMEOUT_SECONDS');
		$previousMarker = getenv('SYLORA_BROWSER_TIMEOUT_MARKER');
		putenv('SYLORA_BROWSER_RUNNER_TIMEOUT_SECONDS=1');
		putenv('SYLORA_BROWSER_TIMEOUT_MARKER=' . $markerPath);
		$exception = null;

		try {
			$this->runBrowserScenario(self::RUNNER, 'timeout');
		} catch (RuntimeException $caught) {
			$exception = $caught;
		} finally {
			self::restoreEnvironment('SYLORA_BROWSER_RUNNER_TIMEOUT_SECONDS', $previousTimeout);
			self::restoreEnvironment('SYLORA_BROWSER_TIMEOUT_MARKER', $previousMarker);
		}

		if (!$exception instanceof RuntimeException) {
			self::fail('Browser-runner должен завершиться диагностируемой ошибкой timeout.');
		}

		self::assertStringContainsString('превысил deadline 1 сек.', $exception->getMessage());
		self::assertStringContainsString('spawned-child=', $exception->getMessage());
		self::assertStringNotContainsString('process group', $exception->getMessage(), 'Timeout должен завершить все ресурсы созданной process group.');
		try {
			usleep(4_000_000);
			self::assertFileDoesNotExist($markerPath, 'Дочерний процесс browser-runner продолжил работу после timeout.');
		} finally {
			if (is_file($markerPath)) {
				unlink($markerPath);
			}
		}
	}

	private static function restoreEnvironment(string $name, false|string $value): void {
		if ($value === false) {
			putenv($name);
			return;
		}

		putenv($name . '=' . $value);
	}
}
