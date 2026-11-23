<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestBootstrap.php';

final class TestBootstrapCleanupTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testShutdownCleanupFailureMakesPhpunitSubprocessFail(): void {
		$temporaryRoot = sys_get_temp_dir() . '/sylora-shutdown-cleanup-' . bin2hex(random_bytes(8));

		if (!mkdir($temporaryRoot, 0700) && !is_dir($temporaryRoot)) {
			self::fail('Не удалось создать временный каталог для cleanup subprocess.');
		}

		$probePath = $temporaryRoot . '/ShutdownCleanupFailureProbeTest.php';
		$probe = $this->createProbeSource();

		try {
			if (file_put_contents($probePath, $probe, LOCK_EX) !== strlen($probe)) {
				self::fail('Не удалось записать PHPunit cleanup probe.');
			}

			$this->assertPhpunitSubprocessFailsAfterCleanupError($probePath);
		} finally {
			if (is_file($probePath) && !unlink($probePath)) {
				throw new RuntimeException('Не удалось удалить временный PHPunit cleanup probe.');
			}

			if (is_dir($temporaryRoot) && !rmdir($temporaryRoot)) {
				throw new RuntimeException('Не удалось удалить временный каталог cleanup subprocess.');
			}
		}
	}

	public function testPartialContainerStartCleanupUsesTheMatchingTestRunLabel(): void {
		$runId = '4f7c6d8e9a0b1c2d';
		$containerName = 'sylora-functional-' . $runId;
		$containerId = 'a1b2c3d4e5f60718293a4b5c6d7e8f90123456789abcdef0123456789abcdef0';
		$containerSummary = (new Docker\API\Model\ContainerSummary())
			->setId($containerId)
			->setNames(array('/' . $containerName))
			->setLabels(array('com.sylora.test-run' => $runId))
			->setImage('mariadb:10.11.19');
		$dockerClient = $this->getMockBuilder(Docker\Docker::class)
			->disableOriginalConstructor()
			->onlyMethods(array('containerList', 'containerDelete'))
			->getMock();

		$dockerClient->expects(self::once())
			->method('containerList')
			->with(self::callback(static function (array $query) use ($containerName, $runId): bool {
				$filters = json_decode((string)($query['filters'] ?? ''), true);

				return ($query['all'] ?? false) === true
					&& in_array($containerName, $filters['name'] ?? array(), true)
					&& in_array('com.sylora.test-run=' . $runId, $filters['label'] ?? array(), true);
			}))
			->willReturn(array($containerSummary));
		$dockerClient->expects(self::once())
			->method('containerDelete')
			->with($containerId, array('force' => true, 'v' => false));

		$containerReflection = new ReflectionClass(LoopbackTestContainer::class);
		$container = $containerReflection->newInstanceWithoutConstructor();
		(new ReflectionProperty(Testcontainers\Container\GenericContainer::class, 'dockerClient'))->setValue($container, $dockerClient);
		(new ReflectionProperty(Testcontainers\Container\GenericContainer::class, 'image'))->setValue($container, 'mariadb:10.11.19');
		(new ReflectionProperty(Testcontainers\Container\GenericContainer::class, 'name'))->setValue($container, $containerName);
		(new ReflectionProperty(Testcontainers\Container\GenericContainer::class, 'labels'))->setValue($container, array('com.sylora.test-run' => $runId));

		$bootstrapReflection = new ReflectionClass(TestBootstrap::class);
		$bootstrap = $bootstrapReflection->newInstanceWithoutConstructor();
		$bootstrapReflection->getProperty('containerDefinition')->setValue($bootstrap, $container);
		$bootstrapReflection->getMethod('stopContainer')->invoke($bootstrap);

		self::assertNull($bootstrapReflection->getProperty('containerDefinition')->getValue($bootstrap));
	}

	private function assertPhpunitSubprocessFailsAfterCleanupError(string $probePath): void {
		$command = array(
			PHP_BINARY,
			$this->root . '/upload/system/storage/vendor/bin/phpunit',
			'--no-configuration',
			$probePath,
		);
		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$pipes = array();
		$process = proc_open($command, $descriptors, $pipes, $this->root);

		if (!is_resource($process)) {
			self::fail('Не удалось запустить отдельный PHPunit subprocess для проверки cleanup.');
		}

		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);
		$combinedOutput = (string)$output . (string)$error;

		self::assertSame(1, $exitCode, "PHPunit должен завершиться с ненулевым кодом после ошибки cleanup.\n" . $combinedOutput);
		self::assertStringContainsString('Shutdown cleanup failed', $combinedOutput);
		self::assertStringContainsString('injected shutdown cleanup failure', $combinedOutput);
		self::assertStringContainsString('OK (1 test', $combinedOutput, 'Probe PHPUnit должен успешно завершить тест до shutdown cleanup.');
	}

	private function createProbeSource(): string {
		$bootstrapPath = var_export($this->root . '/tests/phpunit/domen/support/TestBootstrap.php', true);

		return str_replace('__TEST_BOOTSTRAP_PATH__', $bootstrapPath, <<<'PHP'
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __TEST_BOOTSTRAP_PATH__;

final class InjectedCleanupFailureConnection extends mysqli {
	public function __construct() {}

	public function close(): bool {
		throw new RuntimeException('injected shutdown cleanup failure');
	}
}

final class ShutdownCleanupFailureProbeTest extends TestCase {
	public function testProbeReachesSuccessfulPhpunitResultBeforeCleanup(): void {
		$bootstrapReflection = new ReflectionClass(TestBootstrap::class);
		$bootstrap = $bootstrapReflection->newInstanceWithoutConstructor();
		$bootstrapReflection->getProperty('database')->setValue($bootstrap, new InjectedCleanupFailureConnection());
		$bootstrapReflection->getProperty('shared')->setValue(null, $bootstrap);
		$bootstrapReflection->getMethod('registerShutdownHandler')->invoke(null);

		self::assertTrue(true);
	}
}
PHP
		);
	}
}
