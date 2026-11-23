<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestBootstrap.php';

abstract class BrowserTestCase extends TestCase {
	private const DEFAULT_RUNNER_TIMEOUT_SECONDS = 90;
	private const MIN_RUNNER_TIMEOUT_SECONDS = 1;
	private const MAX_RUNNER_TIMEOUT_SECONDS = 600;
	private const TERMINATION_GRACE_SECONDS = 2;
	private const PROCESS_GROUP_START_TIMEOUT_SECONDS = 2;
	private const PROCESS_POLL_INTERVAL_MICROSECONDS = 100_000;
	private const PROCESS_OUTPUT_CHUNK_BYTES = 8192;
	private const SESSION_LAUNCHER_SCRIPT = 'printf "%s\\n" "$$" >&3 || exit 125; IFS= read -r startup_ack || exit 125; [ "$startup_ack" = "start" ] || exit 125; exec "$@"';
	private const SESSION_LAUNCHER_NAME = 'sylora-browser-runner';

	private static ?TestBootstrap $bootstrap = null;
	/**
	 * @var list<resource>
	 */
	private static array $unreapedProcessResources = array();

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$bootstrap = TestBootstrap::shared();
	}

	public static function tearDownAfterClass(): void {
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();
		self::$bootstrap = TestBootstrap::shared();
	}

	/**
	 * @param array<string, scalar> $query
	 */
	final protected function getRouteUrl(string $route, array $query = array()): string {
		return self::getBootstrap()->getRouteUrl($route, $query);
	}

	/**
	 * @return array<string, mixed>
	 */
	final protected function runBrowserScenario(string $runner, string $scenario): array {
		$bootstrap = self::getBootstrap();
		$timeoutSeconds = self::getRunnerTimeoutSeconds();
		$command = array(
			$bootstrap->getSetsidExecutable(),
			'/bin/sh',
			'-c',
			self::SESSION_LAUNCHER_SCRIPT,
			self::SESSION_LAUNCHER_NAME,
			$bootstrap->getNodeExecutable(),
			...$bootstrap->getNodeArguments(),
			$runner,
			$bootstrap->getUrl(),
			$scenario,
			$bootstrap->getChromeExecutable(),
		);
		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
			3 => array('pipe', 'w'),
		);
		$pipes = array();
		$process = proc_open($command, $descriptors, $pipes, $bootstrap->getRoot());

		if (!is_resource($process)) {
			throw new RuntimeException('Не удалось запустить browser-runner для сценария ' . $scenario . '.');
		}

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		stream_set_blocking($pipes[3], false);

		$processStatus = proc_get_status($process);
		$sessionHandshake = self::readSessionHandshake($process, $pipes[3], $processStatus);
		$processId = $sessionHandshake['pid'] ?? (int)$processStatus['pid'];
		$processGroupId = is_int($sessionHandshake['pid'] ?? null) ? posix_getpgid($sessionHandshake['pid']) : false;
		$sessionId = is_int($sessionHandshake['pid'] ?? null) ? posix_getsid($sessionHandshake['pid']) : false;
		$runnerSessionIsIsolated = is_int($sessionHandshake['pid'] ?? null)
			&& $processGroupId === $processId
			&& $sessionId === $processId
			&& $sessionId !== posix_getsid(getmypid());
		$output = '';
		$error = '';

		if (!$runnerSessionIsIsolated) {
			fclose($pipes[0]);
			$cleanupError = self::terminateUnisolatedLauncher($process, $processStatus, $pipes, $output, $error);
			throw new RuntimeException(sprintf("Не удалось подтвердить изолированную session browser-runner за %d сек. (pid=%d, pgid=%s, sid=%s).%s\nstdout:\n%s\nstderr:\n%s", self::PROCESS_GROUP_START_TIMEOUT_SECONDS, $processId, is_int($processGroupId) ? (string)$processGroupId : 'unknown', is_int($sessionId) ? (string)$sessionId : 'unknown', $cleanupError === '' ? '' : ' Cleanup: ' . $cleanupError, $output, $error));
		}

		if (fwrite($pipes[0], "start\n") !== 6) {
			fclose($pipes[0]);
			$processClosed = false;
			$cleanupError = self::terminateProcessGroup($process, $processGroupId, $processClosed, $pipes, $output, $error);
			self::closeProcessPipes($pipes);
			$exitCode = null;
			$closeCode = null;
			if (!self::reapProcessIfExited($process, $processClosed, $exitCode, $closeCode)) {
				self::retainUnreapedProcess($process);
				$cleanupError .= ($cleanupError === '' ? '' : '; ') . 'direct process still running after SIGKILL; proc_close skipped';
			}
			throw new RuntimeException('Не удалось подтвердить запуск browser-runner.' . ($cleanupError === '' ? '' : ' Cleanup: ' . $cleanupError));
		}
		fclose($pipes[0]);
		fclose($pipes[3]);

		$exitCode = null;
		$closeCode = null;
		$processClosed = false;
		$deadline = microtime(true) + $timeoutSeconds;
		$timedOut = false;
		$processRunning = true;

		while (true) {
			self::drainProcessOutput($pipes, $output, $error, self::PROCESS_POLL_INTERVAL_MICROSECONDS);

			if (!$processClosed) {
				$processStatus = proc_get_status($process);
				$processRunning = self::processIsActive($processStatus);

				if (!$processRunning) {
					if (is_int($processStatus['exitcode']) && $processStatus['exitcode'] >= 0) {
						$exitCode = $processStatus['exitcode'];
					}
					$closeCode = proc_close($process);
					$processClosed = true;
				}
			}

			if (!$processRunning && self::arePipesClosed($pipes) && !self::isProcessGroupRunning($processGroupId)) {
				break;
			}

			if (microtime(true) >= $deadline) {
				$timedOut = true;
				break;
			}
		}

		if ($timedOut) {
			$cleanupError = self::terminateProcessGroup($process, $processGroupId, $processClosed, $pipes, $output, $error);
			if (!self::reapProcessIfExited($process, $processClosed, $exitCode, $closeCode)) {
				self::retainUnreapedProcess($process);
				$cleanupError .= ($cleanupError === '' ? '' : '; ') . 'direct process still running after SIGKILL; proc_close skipped';
			}
			self::closeProcessPipes($pipes);

			$diagnostic = sprintf(
				"Browser-runner превысил deadline %d сек. (runner=%s, scenario=%s, pid=%d, pgid=%d).\nstdout:\n%s\nstderr:\n%s",
				$timeoutSeconds,
				basename($runner),
				$scenario,
				$processId,
				$processGroupId,
				$output,
				$error
			);

			if ($cleanupError !== '') {
				$diagnostic .= "\nprocess cleanup: " . $cleanupError;
			}

			throw new RuntimeException($diagnostic);
		}

		if (!$processClosed) {
			$exitCode = self::readExitCode($process, $exitCode);
		}
		self::closeProcessPipes($pipes);
		if (!$processClosed) {
			$closeCode = proc_close($process);
		}
		$exitCode ??= $closeCode;

		if ($exitCode !== 0) {
			throw new RuntimeException(sprintf("Browser-runner завершился с кодом %d (runner=%s, scenario=%s).\nstdout:\n%s\nstderr:\n%s", $exitCode, basename($runner), $scenario, $output, $error));
		}

		try {
			$result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new RuntimeException("Browser-runner вернул некорректный JSON.\nstdout:\n" . $output . "\nstderr:\n" . $error, 0, $exception);
		}

		if (!is_array($result)) {
			throw new RuntimeException('Browser-runner вернул некорректный результат.');
		}

		return $result;
	}

	private static function getRunnerTimeoutSeconds(): int {
		$value = getenv('SYLORA_BROWSER_RUNNER_TIMEOUT_SECONDS');

		if ($value === false || $value === '') {
			return self::DEFAULT_RUNNER_TIMEOUT_SECONDS;
		}

		$timeoutSeconds = filter_var($value, FILTER_VALIDATE_INT);

		if (!is_int($timeoutSeconds) || $timeoutSeconds < self::MIN_RUNNER_TIMEOUT_SECONDS || $timeoutSeconds > self::MAX_RUNNER_TIMEOUT_SECONDS) {
			throw new RuntimeException(sprintf('SYLORA_BROWSER_RUNNER_TIMEOUT_SECONDS должен быть целым числом от %d до %d.', self::MIN_RUNNER_TIMEOUT_SECONDS, self::MAX_RUNNER_TIMEOUT_SECONDS));
		}

		return $timeoutSeconds;
	}

	/**
	 * @param resource $handshakePipe
	 * @param array<string, mixed> $processStatus
	 * @return array{pid: int}|null
	 */
	private static function readSessionHandshake(mixed $process, mixed $handshakePipe, array &$processStatus): ?array {
		$deadline = microtime(true) + self::PROCESS_GROUP_START_TIMEOUT_SECONDS;
		$buffer = '';

		while (microtime(true) < $deadline && self::processIsActive($processStatus)) {
			$chunk = fread($handshakePipe, 64);
			if (is_string($chunk) && $chunk !== '') {
				$buffer .= $chunk;
				$newlinePosition = strpos($buffer, "\n");

				if ($newlinePosition !== false) {
					$processId = filter_var(trim(substr($buffer, 0, $newlinePosition)), FILTER_VALIDATE_INT);

					return is_int($processId) && $processId > 0 ? array('pid' => $processId) : null;
				}
			}

			if (feof($handshakePipe)) {
				return null;
			}

			usleep(10_000);
			$processStatus = proc_get_status($process);
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $processStatus
	 * @param array<int, resource> $pipes
	 */
	private static function terminateUnisolatedLauncher(mixed $process, array &$processStatus, array &$pipes, string &$output, string &$error): string {
		foreach (array(0, 3) as $pipeIndex) {
			if (isset($pipes[$pipeIndex]) && is_resource($pipes[$pipeIndex])) {
				fclose($pipes[$pipeIndex]);
			}
		}

		if (self::processIsActive($processStatus)) {
			if ($processStatus['stopped']) {
				proc_terminate($process, SIGCONT);
			}
			proc_terminate($process, SIGTERM);
		}

		$exited = self::waitForDirectProcessExit($process, $processStatus, $pipes, $output, $error, self::TERMINATION_GRACE_SECONDS);
		$cleanupError = '';

		if (!$exited) {
			proc_terminate($process, SIGKILL);
			$exited = self::waitForDirectProcessExit($process, $processStatus, $pipes, $output, $error, self::TERMINATION_GRACE_SECONDS);
		}

		self::closeProcessPipes($pipes);

		if (!$exited) {
			self::retainUnreapedProcess($process);
			$cleanupError = 'direct launcher still running after SIGKILL; proc_close skipped to avoid an unbounded wait';
		}

		return $cleanupError;
	}

	/**
	 * @param array<string, mixed> $processStatus
	 * @param array<int, resource> $pipes
	 */
	private static function waitForDirectProcessExit(mixed $process, array &$processStatus, array &$pipes, string &$output, string &$error, int $timeoutSeconds): bool {
		$deadline = microtime(true) + $timeoutSeconds;

		while (self::processIsActive($processStatus) && microtime(true) < $deadline) {
			self::drainProcessOutput($pipes, $output, $error, self::PROCESS_POLL_INTERVAL_MICROSECONDS);
			$processStatus = proc_get_status($process);
		}

		if (self::processIsActive($processStatus)) {
			return false;
		}

		proc_close($process);
		return true;
	}

	private static function retainUnreapedProcess(mixed $process): void {
		self::$unreapedProcessResources[] = $process;
	}

	/**
	 * @param array<int, resource> $pipes
	 */
	private static function drainProcessOutput(array $pipes, string &$output, string &$error, int $waitMicroseconds): void {
		$readPipes = array();

		foreach (array(1, 2) as $pipeIndex) {
			if (isset($pipes[$pipeIndex]) && is_resource($pipes[$pipeIndex]) && !feof($pipes[$pipeIndex])) {
				$readPipes[] = $pipes[$pipeIndex];
			}
		}

		if ($readPipes === array()) {
			usleep($waitMicroseconds);
			return;
		}

		$writePipes = null;
		$exceptPipes = null;
		$ready = stream_select($readPipes, $writePipes, $exceptPipes, 0, $waitMicroseconds);

		if (!is_int($ready) || $ready < 1) {
			return;
		}

		foreach ($readPipes as $pipe) {
			$chunk = fread($pipe, self::PROCESS_OUTPUT_CHUNK_BYTES);

			if (!is_string($chunk) || $chunk === '') {
				continue;
			}

			if ($pipe === $pipes[1]) {
				$output .= $chunk;
			} else {
				$error .= $chunk;
			}
		}
	}

	/**
	 * @param array<int, resource> $pipes
	 */
	private static function arePipesClosed(array $pipes): bool {
		foreach (array(1, 2) as $pipeIndex) {
			if (isset($pipes[$pipeIndex]) && is_resource($pipes[$pipeIndex]) && !feof($pipes[$pipeIndex])) {
				return false;
			}
		}

		return true;
	}

	private static function isProcessGroupRunning(int $processGroupId): bool {
		return $processGroupId > 0 && posix_kill(-$processGroupId, 0);
	}

	/**
	 * @param array<string, mixed> $status
	 */
	private static function processIsActive(array $status): bool {
		return (bool)$status['running'] || (bool)$status['stopped'];
	}

	/**
	 * @param array<int, resource> $pipes
	 */
	private static function terminateProcessGroup(mixed $process, int $processGroupId, bool &$processClosed, array &$pipes, string &$output, string &$error): string {
		$cleanupErrors = array();

		if (self::isProcessGroupRunning($processGroupId)) {
			posix_kill(-$processGroupId, SIGCONT);
		}

		if ($processGroupId > 0 && !posix_kill(-$processGroupId, SIGTERM) && self::isProcessGroupRunning($processGroupId)) {
			$cleanupErrors[] = 'SIGTERM не доставлен группе ' . $processGroupId;
		}

		self::waitForProcessGroupExit($process, $processGroupId, $processClosed, $pipes, $output, $error, self::TERMINATION_GRACE_SECONDS);

		if (self::isProcessGroupRunning($processGroupId)) {
			if (!posix_kill(-$processGroupId, SIGKILL)) {
				$cleanupErrors[] = 'SIGKILL не доставлен группе ' . $processGroupId;
			}
			self::waitForProcessGroupExit($process, $processGroupId, $processClosed, $pipes, $output, $error, self::TERMINATION_GRACE_SECONDS);
		}

		if (self::isProcessGroupRunning($processGroupId)) {
			$cleanupErrors[] = 'process group ' . $processGroupId . ' осталась активна после SIGKILL';
		}

		return implode('; ', $cleanupErrors);
	}

	/**
	 * @param array<int, resource> $pipes
	 */
	private static function waitForProcessGroupExit(mixed $process, int $processGroupId, bool &$processClosed, array &$pipes, string &$output, string &$error, int $timeoutSeconds): void {
		$deadline = microtime(true) + $timeoutSeconds;

		while (microtime(true) < $deadline && self::isProcessGroupRunning($processGroupId)) {
			self::drainProcessOutput($pipes, $output, $error, self::PROCESS_POLL_INTERVAL_MICROSECONDS);
			if (!$processClosed) {
				$status = proc_get_status($process);

				if (!self::processIsActive($status)) {
					proc_close($process);
					$processClosed = true;

					if (self::arePipesClosed($pipes)) {
						usleep(self::PROCESS_POLL_INTERVAL_MICROSECONDS);
					}
				}
			}
		}
	}

	/**
	 * @param array<int, resource> $pipes
	 */
	private static function closeProcessPipes(array &$pipes): void {
		foreach ($pipes as $pipe) {
			if (is_resource($pipe)) {
				fclose($pipe);
			}
		}
	}

	private static function readExitCode($process, ?int $exitCode): ?int {
		$status = proc_get_status($process);

		if (!self::processIsActive($status) && is_int($status['exitcode']) && $status['exitcode'] >= 0) {
			return $status['exitcode'];
		}

		return $exitCode;
	}

	private static function reapProcessIfExited(mixed $process, bool &$processClosed, ?int &$exitCode, ?int &$closeCode): bool {
		if ($processClosed) {
			return true;
		}

		$status = proc_get_status($process);

		if (self::processIsActive($status)) {
			return false;
		}

		if (is_int($status['exitcode']) && $status['exitcode'] >= 0) {
			$exitCode = $status['exitcode'];
		}

		$closeCode = proc_close($process);
		$processClosed = true;

		return true;
	}

	private static function getBootstrap(): TestBootstrap {
		$bootstrap = self::$bootstrap;

		if (!$bootstrap instanceof TestBootstrap) {
			throw new RuntimeException('Bootstrap тестов не запущен.');
		}

		return $bootstrap;
	}
}
