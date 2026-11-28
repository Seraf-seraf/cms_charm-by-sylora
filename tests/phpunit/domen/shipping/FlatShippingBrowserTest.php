<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/BrowserTestCase.php';

final class FlatShippingBrowserTest extends BrowserTestCase {
	private const RUNNER = __DIR__ . '/support/flat_shipping_browser.mjs';
	private const SHIPPING_SETTINGS = array(
		'shipping_flat_status' => '1',
		'shipping_flat_cost' => '350.00',
		'shipping_flat_tax_class_id' => '0',
		'shipping_flat_geo_zone_id' => '0',
		'shipping_flat_sort_order' => '1',
	);

	private ?mysqli $database = null;
	private string $tablePrefix = '';
	private ?int $createdExtensionId = null;
	private array $createdSettingIds = array();
	private int $orderCountBefore = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->assertTemporaryDatabaseConfiguration();

		$databasePort = (int)DB_PORT;
		$this->database = new mysqli(
			DB_HOSTNAME,
			DB_USERNAME,
			DB_PASSWORD,
			DB_DATABASE,
			$databasePort,
		);
		$this->database->set_charset('utf8mb4');
		$this->tablePrefix = (string)DB_PREFIX;
		$this->orderCountBefore = $this->countOrders();
	}

	protected function tearDown(): void {
		if ($this->database instanceof mysqli) {
			$this->database->close();
			$this->database = null;
		}

		parent::tearDown();
	}

	public function testGuestCanSelectFlatShippingWithoutCreatingAnOrder(): void {
		try {
			$this->seedFlatShipping();
			$result = $this->runBrowserScenario(self::RUNNER, 'flat-shipping');

			self::assertSame('flat-shipping', $result['scenario'] ?? null);
			self::assertSame('Серьги Розовый рассвет', $result['product']['name'] ?? null);
			self::assertSame('SYLORA-TEST-001', $result['product']['model'] ?? null);
			self::assertSame('1', $result['product']['quantity'] ?? null);
			self::assertTrue($result['product']['visible'] ?? false);
			self::assertTrue($result['guestAddressSave']['saved'] ?? false);
			self::assertSame(200, $result['guestAddressSave']['status'] ?? null);
			self::assertSame('176', $result['guestAddressSave']['fields']['country_id'] ?? null);
			self::assertSame('2769', $result['guestAddressSave']['fields']['zone_id'] ?? null);
			self::assertSame('1', $result['guestAddressSave']['fields']['shipping_address'] ?? null);
			self::assertSame(
				array(
					'firstname' => 'Тест',
					'lastname' => 'Покупатель',
					'email' => 'flat-shipping@example.invalid',
					'telephone' => '+7 900 000-00-01',
					'address1' => 'Тестовая улица, 1',
					'address2' => '',
					'postcode' => '',
					'city' => 'Москва',
					'countryId' => '176',
					'shippingAddress' => true,
				),
				$result['guestAddressState'] ?? null,
			);

			self::assertSame('flat.flat', $result['shippingMethod']['code'] ?? null);
			self::assertSame('Фиксированный тариф', $result['shippingMethod']['groupTitle'] ?? null);
			self::assertSame('Фиксированная стоимость доставки', $result['shippingMethod']['quoteTitle'] ?? null);
			self::assertSame('350.00 ₽', $result['shippingMethod']['priceText'] ?? null);
			self::assertTrue($result['shippingMethod']['radioVisible'] ?? false);
			self::assertTrue($result['shippingMethod']['selectedBeforeSave'] ?? false);
			self::assertSame(200, $result['shippingMethodSave']['status'] ?? null);
			self::assertTrue($result['shippingMethodSave']['saved'] ?? false);
			self::assertSame('flat.flat', $result['shippingMethodAfterSave']['selectedCode'] ?? null);
			self::assertTrue($result['shippingMethodAfterSave']['radioChecked'] ?? false);
			$shippingScreenshots = $result['shippingScreenshots'] ?? array();
			self::assertSame(array(320, 390, 1440), array_column($shippingScreenshots, 'widthPx'));

			foreach ($shippingScreenshots as $screenshot) {
				$width = $screenshot['widthPx'] ?? 0;

				self::assertSame($width, $screenshot['imageWidth'] ?? null);
				self::assertSame($width, $screenshot['viewportWidth'] ?? null);
				self::assertSame('flat.flat', $screenshot['selectedCode'] ?? null);
				self::assertSame('Фиксированная стоимость доставки', $screenshot['shippingMethodTitle'] ?? null);
				self::assertSame('350.00 ₽', $screenshot['priceText'] ?? null);
				self::assertTrue($screenshot['shippingPanelExpanded'] ?? false);
				self::assertFalse($screenshot['buyerPanelExpanded'] ?? true);
				self::assertLessThanOrEqual($width, $screenshot['documentWidth'] ?? PHP_INT_MAX);
				self::assertLessThanOrEqual($width, $screenshot['bodyWidth'] ?? PHP_INT_MAX);
				self::assertStringContainsString('stylesheet.min.css', $screenshot['stylesheetHref'] ?? '');
				self::assertTrue($screenshot['compactFooter'] ?? false);
				self::assertSame('Charm by Sylora', $screenshot['footerTitle'] ?? null);
				self::assertSame('Разделы сайта и контакты', $screenshot['footerNavigationText'] ?? null);
				self::assertGreaterThan(0, $screenshot['footerColumnCount'] ?? 0);
				self::assertIsString($screenshot['path'] ?? null);
				self::assertFileExists($screenshot['path']);
			}

			$cartScreenshots = $result['cartScreenshots'] ?? array();
			self::assertSame(array(320, 390, 1440), array_column($cartScreenshots, 'widthPx'));

			foreach ($cartScreenshots as $screenshot) {
				$width = $screenshot['widthPx'] ?? 0;

				self::assertSame($width, $screenshot['imageWidth'] ?? null);
				self::assertSame($width, $screenshot['viewportWidth'] ?? null);
				self::assertSame('Корзина', $screenshot['cartTitle'] ?? null);
				self::assertGreaterThan(0, $screenshot['cartItemsCount'] ?? 0);
				self::assertLessThanOrEqual($width, $screenshot['documentWidth'] ?? PHP_INT_MAX);
				self::assertLessThanOrEqual($width, $screenshot['bodyWidth'] ?? PHP_INT_MAX);
				self::assertTrue($screenshot['compactFooter'] ?? false);
				self::assertSame('Charm by Sylora', $screenshot['footerTitle'] ?? null);
				self::assertSame('Разделы сайта и контакты', $screenshot['footerNavigationText'] ?? null);
				self::assertGreaterThan(0, $screenshot['footerColumnCount'] ?? 0);
				self::assertStringContainsString('stylesheet.min.css', $screenshot['stylesheetHref'] ?? '');
				self::assertIsString($screenshot['path'] ?? null);
				self::assertFileExists($screenshot['path']);
			}

			self::assertSame(array(), $result['externalResponses'] ?? null);
			self::assertSame(array(), $result['unblockedExternalRequests'] ?? null);
			self::assertSame(array(), $result['forbiddenRoutes'] ?? null);
			self::assertSame(
				array(
					'checkout/cart/add',
					'checkout/cart',
					'checkout/guest/save',
					'checkout/guest',
					'checkout/shipping_method',
					'checkout/shipping_method/save',
					'checkout/shipping_method',
				),
				$result['requiredRouteSequence'] ?? null,
			);
			self::assertTrue($result['cleanup']['browserProcessExited'] ?? false);
			self::assertTrue($result['cleanup']['profileRemoved'] ?? false);
			self::assertSame($this->orderCountBefore, $this->countOrders());
		} finally {
			try {
				$this->cleanupSeededFlatShipping();
				self::assertSame(0, $this->countRowsForCreatedSeedIds());
			} finally {
				self::assertSame($this->orderCountBefore, $this->countOrders());
			}
		}
	}

	private function assertTemporaryDatabaseConfiguration(): void {
		$requiredConstants = array(
			'HTTP_SERVER',
			'DIR_LOGS',
			'DB_HOSTNAME',
			'DB_USERNAME',
			'DB_PASSWORD',
			'DB_DATABASE',
			'DB_PORT',
			'DB_PREFIX',
		);

		foreach ($requiredConstants as $constant) {
			if (!defined($constant)) {
				throw new RuntimeException('TestBootstrap не определил обязательную константу ' . $constant . '.');
			}
		}

		$storefront = parse_url((string)HTTP_SERVER);
		$logsDirectory = rtrim((string)DIR_LOGS, DIRECTORY_SEPARATOR);
		$temporaryRootPrefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sylora-functional-tests-';
		$logsPattern = '~^' . preg_quote($temporaryRootPrefix, '~') . '[a-f0-9]{16}/storage/logs$~D';
		$databasePort = filter_var(DB_PORT, FILTER_VALIDATE_INT);
		$storefrontPort = is_array($storefront) ? filter_var($storefront['port'] ?? null, FILTER_VALIDATE_INT) : false;
		$databasePrefix = (string)DB_PREFIX;

		if (
			DB_DATABASE !== 'charm_functional'
			|| DB_USERNAME !== 'charm_test'
			|| DB_HOSTNAME !== '127.0.0.1'
			|| preg_match(
				'/^[a-f0-9]{48}$/D',
				(string)DB_PASSWORD,
			) !== 1
			|| !is_array($storefront)
			|| ($storefront['scheme'] ?? '') !== 'http'
			|| ($storefront['host'] ?? '') !== '127.0.0.1'
			|| ($storefront['path'] ?? '') !== '/'
			|| !is_int($storefrontPort)
			|| $storefrontPort < 1024
			|| $storefrontPort > 65535
			|| isset($storefront['user'])
			|| isset($storefront['pass'])
			|| preg_match($logsPattern, $logsDirectory) !== 1
			|| is_link($logsDirectory)
			|| realpath($logsDirectory) !== $logsDirectory
			|| !is_int($databasePort)
			|| $databasePort < 1024
			|| $databasePort > 65535
			|| preg_match('/^[A-Za-z0-9_]*$/D', $databasePrefix) !== 1
		) {
			throw new RuntimeException('Flat shipping browser test разрешает подключение только к одноразовой БД TestBootstrap и loopback storefront.');
		}
	}

	private function seedFlatShipping(): void {
		$database = $this->getDatabase();
		$shippingType = 'shipping';
		$flatCode = 'flat';

		$extensionCount = $database->prepare('SELECT COUNT(*) FROM `' . $this->tablePrefix . 'extension` WHERE `type` = ? AND `code` = ?');
		$extensionCount->bind_param(
			'ss',
			$shippingType,
			$flatCode,
		);
		$extensionCount->execute();
		$extensionCount->bind_result($existingExtensions);
		$extensionCount->fetch();
		$extensionCount->close();

		$settingsCode = 'shipping_flat';
		$settingsCount = $database->prepare('SELECT COUNT(*) FROM `' . $this->tablePrefix . 'setting` WHERE `code` = ?');
		$settingsCount->bind_param('s', $settingsCode);
		$settingsCount->execute();
		$settingsCount->bind_result($existingSettings);
		$settingsCount->fetch();
		$settingsCount->close();

		if ((int)$existingExtensions !== 0 || (int)$existingSettings !== 0) {
			throw new RuntimeException('Изолированная БД уже содержит extension flat или его настройки; тест не будет перезаписывать чужие строки.');
		}

		$insertExtension = $database->prepare('INSERT INTO `' . $this->tablePrefix . 'extension` (`type`, `code`) VALUES (?, ?)');
		$insertExtension->bind_param(
			'ss',
			$shippingType,
			$flatCode,
		);
		$insertExtension->execute();
		$this->createdExtensionId = (int)$database->insert_id;
		$insertExtension->close();

		if ($this->createdExtensionId < 1) {
			throw new RuntimeException('Не удалось записать точный id временного extension flat.');
		}

		$storeId = 0;
		$serialized = 0;
		$insertSetting = $database->prepare('INSERT INTO `' . $this->tablePrefix . 'setting` (`store_id`, `code`, `key`, `value`, `serialized`) VALUES (?, ?, ?, ?, ?)');

		foreach (self::SHIPPING_SETTINGS as $key => $value) {
			$insertSetting->bind_param(
				'isssi',
				$storeId,
				$settingsCode,
				$key,
				$value,
				$serialized,
			);
			$insertSetting->execute();
			$settingId = (int)$database->insert_id;

			if ($settingId < 1) {
				$insertSetting->close();
				throw new RuntimeException('Не удалось получить id временной настройки ' . $key . '.');
			}

			$this->createdSettingIds[$settingId] = $key;
		}

		$insertSetting->close();
	}

	private function cleanupSeededFlatShipping(): void {
		$database = $this->getDatabase();
		$cleanupErrors = array();

		foreach ($this->createdSettingIds as $settingId => $key) {
			try {
				$storeId = 0;
				$settingsCode = 'shipping_flat';
				$deleteSetting = $database->prepare('DELETE FROM `' . $this->tablePrefix . 'setting` WHERE `setting_id` = ? AND `store_id` = ? AND `code` = ? AND `key` = ?');
				$deleteSetting->bind_param(
					'iiss',
					$settingId,
					$storeId,
					$settingsCode,
					$key,
				);
				$deleteSetting->execute();
				$deleteSetting->close();
			} catch (Throwable $exception) {
				$cleanupErrors[] = $exception->getMessage();
			}
		}

		if ($this->createdExtensionId !== null) {
			try {
				$extensionType = 'shipping';
				$extensionCode = 'flat';
				$deleteExtension = $database->prepare('DELETE FROM `' . $this->tablePrefix . 'extension` WHERE `extension_id` = ? AND `type` = ? AND `code` = ?');
				$deleteExtension->bind_param(
					'iss',
					$this->createdExtensionId,
					$extensionType,
					$extensionCode,
				);
				$deleteExtension->execute();
				$deleteExtension->close();
			} catch (Throwable $exception) {
				$cleanupErrors[] = $exception->getMessage();
			}
		}

		if ($cleanupErrors !== array()) {
			throw new RuntimeException('Не удалось удалить только созданные строки flat: ' . implode(' | ', $cleanupErrors));
		}
	}

	private function countRowsForCreatedSeedIds(): int {
		$database = $this->getDatabase();
		$count = 0;

		foreach (array_keys($this->createdSettingIds) as $settingId) {
			$statement = $database->prepare('SELECT COUNT(*) FROM `' . $this->tablePrefix . 'setting` WHERE `setting_id` = ?');
			$statement->bind_param(
				'i',
				$settingId,
			);
			$statement->execute();
			$statement->bind_result($rows);
			$statement->fetch();
			$count += (int)$rows;
			$statement->close();
		}

		if ($this->createdExtensionId !== null) {
			$statement = $database->prepare('SELECT COUNT(*) FROM `' . $this->tablePrefix . 'extension` WHERE `extension_id` = ?');
			$statement->bind_param(
				'i',
				$this->createdExtensionId,
			);
			$statement->execute();
			$statement->bind_result($rows);
			$statement->fetch();
			$count += (int)$rows;
			$statement->close();
		}

		return $count;
	}

	private function countOrders(): int {
		$database = $this->getDatabase();
		$result = $database->query('SELECT COUNT(*) AS `order_count` FROM `' . $this->tablePrefix . 'order`');
		$row = $result->fetch_assoc();
		$result->free();

		return (int)($row['order_count'] ?? 0);
	}

	private function getDatabase(): mysqli {
		if (!$this->database instanceof mysqli) {
			throw new RuntimeException('Соединение с временной тестовой БД отсутствует.');
		}

		return $this->database;
	}
}
