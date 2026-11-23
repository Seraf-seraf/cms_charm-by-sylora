<?php

declare(strict_types=1);

use Testcontainers\Container\GenericContainer;
use Testcontainers\Container\StartedGenericContainer;
use Docker\API\Exception\NotFoundException;
use Docker\API\Model\ContainerSummary;
use Testcontainers\Wait\WaitForContainer;
use Docker\API\Model\PortBinding;

require_once dirname(__DIR__, 4) . '/upload/system/storage/vendor/autoload.php';

final class LoopbackTestContainer extends GenericContainer {
	/**
	 * @return array<string, list<PortBinding>>
	 */
	protected function createPortBindings(): array {
		// Testcontainers defaults published ports to 0.0.0.0; test data must stay local.
		$portBindings = array();

		foreach ($this->exposedPorts as $port) {
			$portBinding = new PortBinding();
			$portBinding->setHostPort((string)$this->portGenerator->generatePort());
			$portBinding->setHostIp('127.0.0.1');
			$portBindings[$port] = array($portBinding);
		}

		return $portBindings;
	}

	public function removeOwnedContainer(): void {
		$containerId = isset($this->id) && $this->id !== '' ? $this->id : $this->findOwnedContainerId();

		if ($containerId === null) {
			return;
		}

		try {
			$this->dockerClient->containerDelete($containerId, array('force' => true, 'v' => false));
		} catch (NotFoundException) {
			// The owned test container has already been removed.
		}
	}

	private function findOwnedContainerId(): ?string {
		if ($this->name === null || $this->name === '' || $this->labels === null || $this->labels === array()) {
			throw new RuntimeException('Cannot safely locate a partially created test container without its name and labels.');
		}

		$labelFilters = array();
		foreach ($this->labels as $name => $value) {
			$labelFilters[] = $name . '=' . $value;
		}

		$filters = json_encode(array(
			'name' => array($this->name),
			'label' => $labelFilters,
		), JSON_THROW_ON_ERROR);
		$containers = $this->dockerClient->containerList(array('all' => true, 'filters' => $filters));
		$matches = array();

		foreach ($containers ?? array() as $container) {
			if (!$container instanceof ContainerSummary) {
				continue;
			}

			$names = $container->getNames() ?? array();
			if (!in_array($this->name, $names, true) && !in_array('/' . $this->name, $names, true)) {
				continue;
			}

			$labels = $container->getLabels();
			if (!is_array($labels) || $container->getImage() !== $this->image) {
				continue;
			}

			$labelsMatch = true;
			foreach ($this->labels as $name => $value) {
				if (($labels[$name] ?? null) !== $value) {
					$labelsMatch = false;
					break;
				}
			}

			$containerId = $container->getId();
			if ($labelsMatch && is_string($containerId) && $containerId !== '') {
				$matches[] = $containerId;
			}
		}

		if (count($matches) > 1) {
			throw new RuntimeException('Found multiple containers for one functional test run; refusing ambiguous cleanup.');
		}

		return $matches[0] ?? null;
	}
}

final class TestBootstrap {
	private const DATABASE_IMAGE = 'mariadb:10.11.19';
	private const DATABASE_NAME = 'charm_functional';
	private const DATABASE_PORT = 3306;
	private const HOME_COLLECTION_IMAGE = 'catalog/sylora/jewelry-collection.png';
	private const LANGUAGE_MIGRATION = '2026_07_06_000001_ru_ru_language.sql';
	private const DATABASE_START_TIMEOUT_SECONDS = 120;
	private const SERVER_START_TIMEOUT_SECONDS = 10;
	private const FIXED_TEST_METRICA_COUNTER = '110751771';
	private const MIN_NODE_WEBSOCKET_VERSION = '20.10.0';
	private const UNFLAGGED_NODE_WEBSOCKET_VERSION = '22.0.0';

	private static ?self $shared = null;
	private static ?RuntimeException $failure = null;

	private string $root;
	private string $nodeExecutable = '';
	private array $nodeArguments = array();
	private string $baseUrl = '';
	private string $url = '';
	private string $temporaryRoot = '';
	private string $serverLogPath = '';
	private ?mysqli $database = null;
	private ?GenericContainer $containerDefinition = null;
	private ?StartedGenericContainer $container = null;
	private mixed $serverProcess = null;
	private bool $stopped = false;

	private function __construct() {
		$this->root = dirname(__DIR__, 4);
	}

	public static function shared(): self {
		if (self::$failure instanceof RuntimeException) {
			throw new RuntimeException(self::$failure->getMessage(), 0, self::$failure);
		}

		if (self::$shared instanceof self) {
			return self::$shared;
		}

		$bootstrap = new self();
		self::$shared = $bootstrap;
		self::registerShutdownHandler();

		try {
			$bootstrap->start();
		} catch (Throwable $exception) {
			try {
				$bootstrap->stop();
			} catch (Throwable $cleanupException) {
				self::reportCleanupFailure('Cleanup after bootstrap failure failed', $cleanupException);
			}
			self::$shared = null;
			self::$failure = new RuntimeException('Не удалось подготовить изолированную среду функциональных тестов: ' . $exception->getMessage(), 0, $exception);
			throw self::$failure;
		}

		return $bootstrap;
	}

	private static function registerShutdownHandler(): void {
		register_shutdown_function(static function (): void {
			if (self::$shared instanceof self) {
				try {
					self::$shared->stop();
				} catch (Throwable $exception) {
					self::reportCleanupFailure('Shutdown cleanup failed', $exception);
					exit(1);
				}
			}
		});
	}

	private static function reportCleanupFailure(string $context, Throwable $exception): void {
		error_log('[sylora-functional-tests] ' . $context . ': ' . $exception->getMessage());
	}

	public function stop(): void {
		if ($this->stopped) {
			return;
		}

		$errors = array();
		try {
			if (is_resource($this->serverProcess)) {
				proc_terminate($this->serverProcess);
				proc_close($this->serverProcess);
				$this->serverProcess = null;
			}
		} catch (Throwable $exception) {
			$errors[] = $exception;
		}

		try {
			if ($this->database instanceof mysqli) {
				$this->database->close();
				$this->database = null;
			}
		} catch (Throwable $exception) {
			$errors[] = $exception;
		}

		try {
			$this->stopContainer();
		} catch (Throwable $exception) {
			$errors[] = $exception;
		}

		try {
			$this->removeTemporaryDirectory();
		} catch (Throwable $exception) {
			$errors[] = $exception;
		}

		if ($errors !== array()) {
			$messages = array_map(static fn (Throwable $exception): string => get_class($exception) . ': ' . $exception->getMessage(), $errors);
			throw new RuntimeException('Не удалось полностью очистить тестовую среду: ' . implode(' | ', $messages), 0, $errors[0]);
		}

		$this->stopped = true;
	}

	public function getRoot(): string {
		return $this->root;
	}

	public function getUrl(): string {
		return $this->url;
	}

	/**
	 * @param array<string, scalar> $query
	 */
	public function getRouteUrl(string $route, array $query = array()): string {
		$query = array_merge(array('route' => $route), $query);

		return $this->baseUrl . '/index.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}

	public function getNodeExecutable(): string {
		return $this->nodeExecutable;
	}

	/**
	 * @return list<string>
	 */
	public function getNodeArguments(): array {
		return $this->nodeArguments;
	}

	public function getSetsidExecutable(): string {
		return $this->findExecutable('setsid');
	}

	public function getChromeExecutable(): string {
		return $this->findChrome();
	}

	private function start(): void {
		$this->assertBrowserDependencies();
		$this->prepareTemporaryDirectories();
		$this->startDatabaseContainer();
		$this->database = $this->connectToDatabase();
		$this->assertDatabaseSqlMode();
		$this->configureTemporaryStore();
		$this->createDatabaseSchema();
		$this->seedDatabase();
		$this->runProjectMigrations();
		$this->configureSyntheticThemeSettings();
		$this->seedHeroSlideshow();
		$this->enableMetricaForTests();
		$this->startServer();

		if (!$this->waitForSite()) {
			throw new RuntimeException('Локальная витрина OpenCart не запустилась с тестовой конфигурацией.');
		}
	}

	private function assertBrowserDependencies(): void {
		$this->configureNodeRuntime();

		if (!extension_loaded('mysqli')) {
			throw new RuntimeException('Требуется расширение PHP mysqli.');
		}

		if (!extension_loaded('gd')) {
			throw new RuntimeException('Требуется расширение PHP gd для генерации синтетических изображений миграциями.');
		}

		if (!function_exists('posix_getpgid') || !function_exists('posix_kill')) {
			throw new RuntimeException('Для изоляции и ограничения browser-runner требуется PHP POSIX extension.');
		}

		if ($this->getSetsidExecutable() === '' || $this->findChrome() === '') {
			throw new RuntimeException('Для функциональных тестов требуются setsid и Chrome/Chromium.');
		}
	}

	private function configureNodeRuntime(): void {
		$configuredNode = getenv('SYLORA_NODE_BIN');
		$this->nodeExecutable = is_string($configuredNode) && $configuredNode !== ''
			? $configuredNode
			: $this->findExecutable('node');

		if ($this->nodeExecutable === '' || !is_file($this->nodeExecutable) || !is_executable($this->nodeExecutable)) {
			throw new RuntimeException('Для функциональных тестов требуется исполняемый Node.js; настройте SYLORA_NODE_BIN или PATH.');
		}

		$output = array();
		$exitCode = 0;
		exec(escapeshellarg($this->nodeExecutable) . ' --version 2>&1', $output, $exitCode);
		$versionOutput = isset($output[0]) ? trim((string)$output[0]) : '';

		if ($exitCode !== 0 || preg_match('/^v?(\d+\.\d+\.\d+)(?:[-+][a-zA-Z0-9.-]+)?$/', $versionOutput, $matches) !== 1) {
			throw new RuntimeException('Не удалось определить версию настроенного Node.js.');
		}

		$version = $matches[1];

		if (version_compare($version, self::UNFLAGGED_NODE_WEBSOCKET_VERSION, '>=')) {
			$this->nodeArguments = array();
			return;
		}

		if (version_compare($version, self::MIN_NODE_WEBSOCKET_VERSION, '>=')) {
			$this->nodeArguments = array('--experimental-websocket');
			return;
		}

		throw new RuntimeException(sprintf(
			'Browser-тестам требуется Node.js версии %s или новее; обнаружена %s.',
			self::MIN_NODE_WEBSOCKET_VERSION,
			$version
		));
	}

	private function prepareTemporaryDirectories(): void {
		$this->temporaryRoot = sys_get_temp_dir() . '/sylora-functional-tests-' . bin2hex(random_bytes(8));
		$this->serverLogPath = $this->temporaryRoot . '/php-server.log';

		foreach (array(
			$this->temporaryRoot,
			$this->temporaryRoot . '/public',
			$this->temporaryRoot . '/image',
			$this->temporaryRoot . '/image/catalog',
			$this->temporaryRoot . '/storage',
			$this->temporaryRoot . '/storage/cache',
			$this->temporaryRoot . '/storage/download',
			$this->temporaryRoot . '/storage/logs',
			$this->temporaryRoot . '/storage/modification',
			$this->temporaryRoot . '/storage/session',
			$this->temporaryRoot . '/storage/upload',
			$this->temporaryRoot . '/migrations',
		) as $directory) {
			if (!mkdir($directory, 0700) && !is_dir($directory)) {
				throw new RuntimeException('Не удалось создать временный каталог тестов.');
			}
		}

		$vendorSource = $this->root . '/upload/system/storage/vendor';
		$vendorTarget = $this->temporaryRoot . '/storage/vendor';

		if (!is_dir($vendorSource) || !symlink($vendorSource, $vendorTarget)) {
			throw new RuntimeException('Не удалось подключить установленные PHP-зависимости OpenCart.');
		}

		foreach (array('placeholder.png', 'no_image.png') as $image) {
			$source = $this->root . '/upload/image/' . $image;

			if (!is_file($source) || !copy($source, $this->temporaryRoot . '/image/' . $image)) {
				throw new RuntimeException('Не удалось подготовить нейтральные изображения тестового магазина.');
			}
		}

		foreach (array('cart.png', 'opencart-logo.png') as $image) {
			$source = $this->root . '/upload/image/catalog/' . $image;

			if (is_file($source)) {
				copy($source, $this->temporaryRoot . '/image/catalog/' . $image);
			}
		}

		$collectionImageSource = $this->root . '/upload/image/' . self::HOME_COLLECTION_IMAGE;
		$collectionImageDirectory = $this->temporaryRoot . '/image/' . dirname(self::HOME_COLLECTION_IMAGE);

		if (!mkdir($collectionImageDirectory, 0700, true) && !is_dir($collectionImageDirectory)) {
			throw new RuntimeException('Не удалось подготовить каталог статических изображений тестовой витрины.');
		}

		if (!is_file($collectionImageSource) || !copy($collectionImageSource, $this->temporaryRoot . '/image/' . self::HOME_COLLECTION_IMAGE)) {
			throw new RuntimeException('Не удалось подготовить изображение главной страницы в тестовой витрине.');
		}

		$publicRoot = $this->temporaryRoot . '/public';
		$links = array(
			$this->root . '/upload/index.php' => $publicRoot . '/index.php',
			$this->root . '/upload/catalog' => $publicRoot . '/catalog',
			$this->temporaryRoot . '/image' => $publicRoot . '/image',
		);

		foreach ($links as $source => $target) {
			if (!symlink($source, $target)) {
				throw new RuntimeException('Не удалось подготовить изолированный docroot для PHP-сервера.');
			}
		}

		$routerSource = __DIR__ . '/fixtures/php-server-router.php';

		if (!is_file($routerSource) || !copy($routerSource, $this->temporaryRoot . '/router.php')) {
			throw new RuntimeException('Не удалось подготовить временный router PHP-сервера.');
		}
	}

	private function startDatabaseContainer(): void {
		$runId = bin2hex(random_bytes(8));
		$databasePassword = bin2hex(random_bytes(24));
		$this->containerDefinition = (new LoopbackTestContainer(self::DATABASE_IMAGE))
			->withName('sylora-functional-' . $runId)
			->withLabels(array('com.sylora.test-run' => $runId))
			->withExposedPorts(self::DATABASE_PORT)
			->withTmpfs('/var/lib/mysql', 'rw,noexec,nosuid')
			->withEnvironment(array(
				'MARIADB_DATABASE' => self::DATABASE_NAME,
				'MARIADB_USER' => 'charm_test',
				'MARIADB_PASSWORD' => $databasePassword,
				'MARIADB_ROOT_PASSWORD' => bin2hex(random_bytes(24)),
			))
			->withWait(new WaitForContainer(30_000, 250));

		$this->container = $this->containerDefinition->start();
		$this->databaseHost = $this->container->getHost();
		$this->databasePort = $this->container->getMappedPort(self::DATABASE_PORT);
		$this->databasePassword = $databasePassword;
	}

	private string $databaseHost = '';
	private int $databasePort = 0;
	private string $databasePassword = '';
	private string $databaseSqlMode = '';

	private function connectToDatabase(): mysqli {
		$deadline = microtime(true) + self::DATABASE_START_TIMEOUT_SECONDS;
		$driver = new mysqli_driver();
		$previousReportMode = $driver->report_mode;
		mysqli_report(MYSQLI_REPORT_OFF);

		try {
			while (microtime(true) < $deadline) {
				$database = @new mysqli($this->databaseHost, 'charm_test', $this->databasePassword, self::DATABASE_NAME, $this->databasePort);

				if (!$database->connect_errno) {
					$database->set_charset('utf8mb4');
					return $database;
				}

				usleep(250_000);
			}
			throw new RuntimeException('MariaDB-контейнер запущен, но соединение с тестовой базой не установилось.');
		} finally {
			mysqli_report($previousReportMode);
		}
	}

	private function assertDatabaseSqlMode(): void {
		$database = $this->database;

		if (!$database instanceof mysqli) {
			throw new RuntimeException('Тестовая база не подключена.');
		}

		$result = $database->query('SELECT @@SESSION.sql_mode AS sql_mode');
		$row = $result->fetch_assoc();
		$sqlMode = isset($row['sql_mode']) && is_string($row['sql_mode']) ? explode(',', $row['sql_mode']) : array();

		if (array_intersect($sqlMode, array('STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES')) === array()) {
			throw new RuntimeException('MariaDB должна запускаться в штатном строгом sql_mode; тестовая среда не должна скрывать его от функциональных тестов.');
		}

		$this->databaseSqlMode = implode(',', $sqlMode);
	}

	private function configureTemporaryStore(): void {
		$serverPort = $this->reservePort();
		$this->baseUrl = 'http://127.0.0.1:' . $serverPort;
		$this->url = $this->getRouteUrl('common/home');

		$templatePath = __DIR__ . '/fixtures/test-config.php.template';
		$template = file_get_contents($templatePath);

		if (!is_string($template)) {
			throw new RuntimeException('Шаблон тестовой конфигурации OpenCart не найден.');
		}

		$values = array(
			'__TEST_HTTP_SERVER__' => var_export($this->baseUrl . '/', true),
			'__TEST_HTTPS_SERVER__' => var_export($this->baseUrl . '/', true),
			'__TEST_ROOT__' => var_export($this->root, true),
			'__TEST_IMAGE_DIR__' => var_export($this->temporaryRoot . '/image', true),
			'__TEST_STORAGE_DIR__' => var_export($this->temporaryRoot . '/storage', true),
			'__TEST_DB_HOST__' => var_export($this->databaseHost, true),
			'__TEST_DB_USERNAME__' => var_export('charm_test', true),
			'__TEST_DB_PASSWORD__' => var_export($this->databasePassword, true),
			'__TEST_DB_DATABASE__' => var_export(self::DATABASE_NAME, true),
			'__TEST_DB_PORT__' => (string)$this->databasePort,
		);

		foreach ($values as $placeholder => $value) {
			$expectedOccurrences = $placeholder === '__TEST_ROOT__' ? 2 : 1;

			if (substr_count($template, $placeholder) !== $expectedOccurrences) {
				throw new RuntimeException('Шаблон тестовой конфигурации содержит неверные placeholders.');
			}
			$template = str_replace($placeholder, $value, $template);
		}

		$configPath = $this->temporaryRoot . '/config.php';

		if (file_put_contents($configPath, $template, LOCK_EX) !== strlen($template)) {
			throw new RuntimeException('Не удалось сформировать отдельный тестовый конфиг OpenCart.');
		}

		require_once $configPath;
	}

	private function createDatabaseSchema(): void {
		$path = __DIR__ . '/fixtures/opencart-3.0.5.0-schema.sql';
		$sql = file_get_contents($path);

		if (!is_string($sql) || !$this->database instanceof mysqli) {
			throw new RuntimeException('Schema fixture OpenCart 3.0.5.0 недоступен.');
		}

		$this->executeSqlScript($this->database, $sql);
	}

	private function seedDatabase(): void {
		$database = $this->database;

		if (!$database instanceof mysqli) {
			throw new RuntimeException('Тестовая база не подключена.');
		}

		$database->query("INSERT INTO `language` (`language_id`, `name`, `code`, `locale`, `image`, `directory`, `sort_order`, `status`) VALUES (1, 'Русский', 'ru-ru', 'ru_RU.UTF-8,ru_RU,ru-ru,russian', 'ru.png', 'ru-ru', 1, 1)");
		$database->query("INSERT INTO `customer_group` (`customer_group_id`, `approval`, `sort_order`) VALUES (1, 0, 1)");
		$database->query("INSERT INTO `customer_group_description` (`customer_group_id`, `language_id`, `name`, `description`) VALUES (1, 1, 'Розничный покупатель', 'Synthetic functional-test customer group')");
		$database->query("INSERT INTO `currency` (`currency_id`, `title`, `code`, `symbol_left`, `symbol_right`, `decimal_place`, `value`, `status`, `date_modified`) VALUES (1, 'Российский рубль', 'RUB', '', ' ₽', 2, 1.00000000, 1, NOW())");
		$database->query("INSERT INTO `stock_status` (`stock_status_id`, `language_id`, `name`) VALUES (5, 1, 'Нет в наличии'), (7, 1, 'В наличии'), (8, 1, 'Предзаказ')");
		$database->query("INSERT INTO `length_class` (`length_class_id`, `value`) VALUES (1, 1.00000000)");
		$database->query("INSERT INTO `length_class_description` (`length_class_id`, `language_id`, `title`, `unit`) VALUES (1, 1, 'Сантиметр', 'см')");
		$database->query("INSERT INTO `weight_class` (`weight_class_id`, `value`) VALUES (1, 1.00000000)");
		$database->query("INSERT INTO `weight_class_description` (`weight_class_id`, `language_id`, `title`, `unit`) VALUES (1, 1, 'Килограмм', 'кг')");
		$database->query("INSERT INTO `country` (`country_id`, `name`, `iso_code_2`, `iso_code_3`, `address_format`, `postcode_required`, `status`) VALUES (176, 'Российская Федерация', 'RU', 'RUS', '', 0, 1)");
		$database->query("INSERT INTO `zone` (`zone_id`, `country_id`, `name`, `code`, `status`) VALUES (2769, 176, 'Москва', '77', 1)");
		$database->query("INSERT INTO `order_status` (`order_status_id`, `language_id`, `name`) VALUES (1, 1, 'Ожидает обработки')");
		$database->query("INSERT INTO `layout` (`layout_id`, `name`) VALUES (1, 'Synthetic test layout')");
		$database->query("INSERT INTO `information` (`information_id`, `bottom`, `sort_order`, `status`) VALUES (3, 0, 3, 1), (5, 0, 5, 1)");
		$database->query("INSERT INTO `information_description` (`information_id`, `language_id`, `title`, `description`, `meta_title`, `meta_description`, `meta_keyword`) VALUES (3, 1, 'Тестовая политика конфиденциальности', 'Synthetic privacy agreement content for isolated functional tests.', '', '', ''), (5, 1, 'Тестовые условия оформления заказа', 'Synthetic checkout agreement content for isolated functional tests.', '', '', '')");
		$database->query("INSERT INTO `information_to_store` (`information_id`, `store_id`) VALUES (3, 0), (5, 0)");

		$settings = array(
			'config_name' => 'Synthetic OpenCart Test Shop',
			'config_owner' => 'Test Automation',
			'config_email' => 'tests@example.invalid',
			'config_meta_title' => 'Synthetic OpenCart Test Shop',
			'config_meta_description' => 'Isolated functional-test store',
			'config_language' => 'ru-ru',
			'config_admin_language' => 'ru-ru',
			'config_currency' => 'RUB',
			'config_currency_auto' => '0',
			'config_customer_group_id' => '1',
			'config_customer_group_display' => '[1]',
			'config_customer_price' => '0',
			'config_theme' => 'charm_by_sylora',
			'config_country_id' => '176',
			'config_zone_id' => '2769',
			'config_layout_id' => '1',
			'config_length_class_id' => '1',
			'config_weight_class_id' => '1',
			'config_stock_status_id' => '7',
			'config_stock_display' => '0',
			'config_stock_checkout' => '0',
			'config_stock_warning' => '0',
			'config_tax' => '0',
			'config_review_status' => '0',
			'config_review_guest' => '0',
			'config_checkout_guest' => '1',
			'config_cart_weight' => '0',
			'config_image_popup_width' => '1000',
			'config_image_popup_height' => '1000',
			'config_image_thumb_width' => '500',
			'config_image_thumb_height' => '500',
			'config_image_additional_width' => '120',
			'config_image_additional_height' => '120',
			'config_image_related_width' => '250',
			'config_image_related_height' => '250',
			'config_image_compare_width' => '90',
			'config_image_compare_height' => '90',
			'config_image_wishlist_width' => '90',
			'config_image_wishlist_height' => '90',
			'config_image_cart_width' => '90',
			'config_image_cart_height' => '90',
			'config_image_location' => '',
			'config_seo_url' => '1',
			'config_maintenance' => '0',
			'config_encryption' => bin2hex(random_bytes(16)),
			'config_error_display' => '1',
			'config_error_log' => '1',
			'config_error_filename' => 'error.log',
			'config_log_status' => '1',
		);

		$statement = $database->prepare("INSERT INTO `setting` (`store_id`, `code`, `key`, `value`, `serialized`) VALUES (0, 'config', ?, ?, ?)");

		foreach ($settings as $key => $value) {
			$serialized = $key === 'config_customer_group_display' ? 1 : 0;
			$statement->bind_param('ssi', $key, $value, $serialized);
			$statement->execute();
		}

		$statement->close();
	}

	private function runProjectMigrations(): void {
		$migrationPaths = glob($this->root . '/database/migrations/*.{php,sql}', GLOB_BRACE);

		if ($migrationPaths === false || $migrationPaths === array()) {
			throw new RuntimeException('Миграции проекта не найдены для тестовой базы.');
		}

		sort($migrationPaths, SORT_STRING);

		foreach ($migrationPaths as $path) {
			$extension = pathinfo($path, PATHINFO_EXTENSION);

			if ($extension === 'sql') {
				$sql = file_get_contents($path);

				if (!is_string($sql) || !$this->database instanceof mysqli) {
					throw new RuntimeException('Не удалось загрузить SQL миграцию ' . basename($path) . '.');
				}

				if (basename($path) === self::LANGUAGE_MIGRATION) {
					$this->runLanguageMigrationWithLegacySchemaCompatibility($sql);
				} else {
					$this->executeSqlScript($this->database, $sql);
				}
				continue;
			}

			$this->runPhpMigration($path);
		}
	}

	private function runLanguageMigrationWithLegacySchemaCompatibility(string $sql): void {
		$database = $this->database;

		if (!$database instanceof mysqli || $this->databaseSqlMode === '') {
			throw new RuntimeException('Режим MariaDB для миграции языка не определён.');
		}

		$migrationSqlMode = array_values(array_diff(
			explode(',', $this->databaseSqlMode),
			array('STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES')
		));
		$migrationModeSql = $database->real_escape_string(implode(',', $migrationSqlMode));
		$database->query("SET SESSION sql_mode = '" . $migrationModeSql . "'");

		try {
			$this->executeSqlScript($database, $sql);
		} finally {
			$originalModeSql = $database->real_escape_string($this->databaseSqlMode);
			$database->query("SET SESSION sql_mode = '" . $originalModeSql . "'");
		}
	}

	private function runPhpMigration(string $path): void {
		$source = file_get_contents($path);
		$configRequire = "require_once __DIR__ . '/../../upload/config.php';";

		if (!is_string($source)) {
			throw new RuntimeException('Не удалось прочитать миграцию ' . basename($path) . '.');
		}

		if (!str_contains($source, $configRequire)) {
			throw new RuntimeException('Миграция ' . basename($path) . ' не использует ожидаемую точку загрузки конфигурации.');
		}

		$source = str_replace($configRequire, 'require_once ' . var_export($this->temporaryRoot . '/config.php', true) . ';', $source, $replaceCount);

		if ($replaceCount !== 1) {
			throw new RuntimeException('Не удалось безопасно подключить тестовый конфиг к ' . basename($path) . '.');
		}

		$temporaryPath = $this->temporaryRoot . '/migrations/' . basename($path);

		if (file_put_contents($temporaryPath, $source, LOCK_EX) !== strlen($source)) {
			throw new RuntimeException('Не удалось подготовить миграцию ' . basename($path) . ' в temp.');
		}

		$disabledFunctions = 'mail,curl_init,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_create,socket_connect';
		$command = array(
			PHP_BINARY,
			'-d', 'allow_url_fopen=0',
			'-d', 'disable_functions=' . $disabledFunctions,
			$temporaryPath,
		);
		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$pipes = array();
		$process = proc_open($command, $descriptors, $pipes, $this->root);

		if (!is_resource($process)) {
			throw new RuntimeException('Не удалось запустить ' . basename($path) . '.');
		}

		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		if ($exitCode !== 0) {
			throw new RuntimeException('Миграция ' . basename($path) . ' завершилась с ошибкой: ' . trim((string)$error . ' ' . (string)$output));
		}
	}

	private function enableMetricaForTests(): void {
		$database = $this->database;

		if (!$database instanceof mysqli) {
			throw new RuntimeException('Тестовая база не подключена.');
		}

		$statement = $database->prepare("UPDATE `setting` SET `value` = ? WHERE store_id = 0 AND `key` = ?");
		$counter = self::FIXED_TEST_METRICA_COUNTER;
		$status = '1';
		$key = 'analytics_yandex_metrica_counter';
		$statement->bind_param('ss', $counter, $key);
		$statement->execute();
		$key = 'analytics_yandex_metrica_status';
		$statement->bind_param('ss', $status, $key);
		$statement->execute();
		$statement->close();
	}

	private function seedHeroSlideshow(): void {
		$database = $this->database;

		if (!$database instanceof mysqli) {
			throw new RuntimeException('Тестовая база не подключена.');
		}

		$bannerName = 'Synthetic functional-test hero';
		$database->query("INSERT INTO `banner` (`name`, `status`) VALUES ('" . $database->real_escape_string($bannerName) . "', 1)");
		$bannerId = (int)$database->insert_id;
		$bannerImage = $database->prepare("INSERT INTO `banner_image` (`banner_id`, `language_id`, `title`, `link`, `image`, `sort_order`) VALUES (?, 1, ?, '', ?, ?)");
		$slides = array(
			array('Синтетический слайд 1', 'catalog/sylora/test-products/earrings-portrait.png', 0),
			array('Синтетический слайд 2', 'catalog/sylora/test-products/bracelet-wide.png', 1),
		);

		foreach ($slides as $slide) {
			if (!is_file($this->temporaryRoot . '/image/' . $slide[1])) {
				throw new RuntimeException('Не найдено синтетическое изображение для slideshow fixture.');
			}

			$bannerImage->bind_param('issi', $bannerId, $slide[0], $slide[1], $slide[2]);
			$bannerImage->execute();
		}

		$bannerImage->close();
		$setting = json_encode(array(
			'name' => $bannerName,
			'banner_id' => $bannerId,
			'width' => 840,
			'height' => 630,
			'status' => 1,
		), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
		$module = $database->prepare("INSERT INTO `module` (`name`, `code`, `setting`) VALUES (?, 'slideshow', ?)");
		$module->bind_param('ss', $bannerName, $setting);
		$module->execute();
		$moduleId = (int)$database->insert_id;
		$module->close();

		$homeLayoutId = 2;
		$homeLayoutName = 'Synthetic home test layout';
		$homeLayout = $database->prepare('INSERT INTO `layout` (`layout_id`, `name`) VALUES (?, ?)');
		$homeLayout->bind_param('is', $homeLayoutId, $homeLayoutName);
		$homeLayout->execute();
		$homeLayout->close();

		$homeRoute = 'common/home';
		$storeId = 0;
		$layoutRoute = $database->prepare('INSERT INTO `layout_route` (`layout_id`, `store_id`, `route`) VALUES (?, ?, ?)');
		$layoutRoute->bind_param('iis', $homeLayoutId, $storeId, $homeRoute);
		$layoutRoute->execute();
		$layoutRoute->close();

		$layoutCode = 'slideshow.' . $moduleId;
		$layout = $database->prepare("INSERT INTO `layout_module` (`layout_id`, `code`, `position`, `sort_order`) VALUES (?, ?, 'content_top', 0)");
		$layout->bind_param('is', $homeLayoutId, $layoutCode);
		$layout->execute();
		$layout->close();
	}

	private function configureSyntheticThemeSettings(): void {
		$database = $this->database;

		if (!$database instanceof mysqli) {
			throw new RuntimeException('Тестовая база не подключена.');
		}

		$limit = '3';
		$statement = $database->prepare("UPDATE `setting` SET `value` = ? WHERE `store_id` = 0 AND `code` = 'theme_charm_by_sylora' AND `key` = 'theme_charm_by_sylora_product_limit'");
		$statement->bind_param('s', $limit);
		$statement->execute();
		$updatedRows = $statement->affected_rows;
		$statement->close();

		if ($updatedRows !== 1) {
			throw new RuntimeException('Не удалось настроить размер страницы синтетического каталога.');
		}
	}

	private function startServer(): void {
		$host = '127.0.0.1';
		$port = (int)parse_url($this->baseUrl, PHP_URL_PORT);
		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('file', $this->serverLogPath, 'a'),
			2 => array('file', $this->serverLogPath, 'a'),
		);
		$disabledFunctions = 'mail,curl_init,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_create,socket_connect';
		$command = array(
			PHP_BINARY,
			'-d', 'allow_url_fopen=0',
			'-d', 'disable_functions=' . $disabledFunctions,
			'-d', 'auto_prepend_file=' . $this->temporaryRoot . '/config.php',
			'-d', 'session.save_path=' . $this->temporaryRoot . '/storage/session',
			'-S', $host . ':' . $port,
			'-t', $this->temporaryRoot . '/public',
			$this->temporaryRoot . '/router.php',
		);
		$pipes = array();
		$this->serverProcess = proc_open($command, $descriptors, $pipes, $this->temporaryRoot);

		if (!is_resource($this->serverProcess)) {
			throw new RuntimeException('Не удалось запустить изолированный PHP-сервер.');
		}

		if (isset($pipes[0]) && is_resource($pipes[0])) {
			fclose($pipes[0]);
		}
	}

	private function waitForSite(): bool {
		$deadline = microtime(true) + self::SERVER_START_TIMEOUT_SECONDS;

		while (microtime(true) < $deadline) {
			$context = stream_context_create(array('http' => array('timeout' => 1, 'ignore_errors' => true)));
			$response = @file_get_contents($this->url, false, $context);
			$statusLine = $http_response_header[0] ?? '';

			if (is_string($response) && $response !== '' && preg_match('/^HTTP\/\S+ 200\b/', $statusLine) === 1) {
				return true;
			}

			usleep(250_000);
		}

		return false;
	}

	private function reservePort(): int {
		$socket = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

		if (!is_resource($socket)) {
			throw new RuntimeException('Не удалось выбрать локальный порт для PHP-сервера тестов.');
		}

		$address = stream_socket_get_name($socket, false);
		fclose($socket);

		if (!is_string($address) || !str_contains($address, ':')) {
			throw new RuntimeException('Не удалось определить локальный порт для PHP-сервера тестов.');
		}

		return (int)substr(strrchr($address, ':'), 1);
	}

	private function stopContainer(): void {
		if ($this->container instanceof StartedGenericContainer) {
			$this->container->stop();
			$this->container = null;
			$this->containerDefinition = null;
			return;
		}

		if ($this->containerDefinition instanceof GenericContainer) {
			if ($this->containerDefinition instanceof LoopbackTestContainer) {
				$this->containerDefinition->removeOwnedContainer();
			}

			$this->containerDefinition = null;
		}
	}

	private function removeTemporaryDirectory(): void {
		$prefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sylora-functional-tests-';

		if ($this->temporaryRoot === '' || !str_starts_with($this->temporaryRoot, $prefix) || is_link($this->temporaryRoot) || !is_dir($this->temporaryRoot)) {
			return;
		}

		$this->removeDirectoryContents($this->temporaryRoot);

		if (!@rmdir($this->temporaryRoot)) {
			throw new RuntimeException('Не удалось удалить временный каталог функциональных тестов.');
		}

		$this->temporaryRoot = '';
	}

	private function removeDirectoryContents(string $directory): void {
		$entries = @scandir($directory);

		if (!is_array($entries)) {
			throw new RuntimeException('Не удалось прочитать временный каталог функциональных тестов.');
		}

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$path = $directory . DIRECTORY_SEPARATOR . $entry;

			if (is_dir($path) && !is_link($path)) {
				$this->removeDirectoryContents($path);

				if (!@rmdir($path)) {
					throw new RuntimeException('Не удалось удалить вложенный временный каталог функциональных тестов: ' . basename($path) . '.');
				}
			} else {
				if (!@unlink($path)) {
					throw new RuntimeException('Не удалось удалить временный файл функциональных тестов: ' . basename($path) . '.');
				}
			}
		}
	}

	private function executeSqlScript(mysqli $database, string $sql): void {
		if (!$database->multi_query($sql)) {
			throw new RuntimeException($database->error);
		}

		do {
			$result = $database->store_result();

			if ($result instanceof mysqli_result) {
				$result->free();
			}
		} while ($database->more_results() && $database->next_result());

		if ($database->errno) {
			throw new RuntimeException($database->error);
		}
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
