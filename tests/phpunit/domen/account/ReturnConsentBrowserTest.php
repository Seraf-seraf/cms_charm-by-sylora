<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/BrowserTestCase.php';

final class ReturnConsentBrowserTest extends BrowserTestCase {
	private const SYNTHETIC_INFORMATION_TITLE = 'Синтетические условия возврата';
	private const SYNTHETIC_INFORMATION_CONTENT = '<p>RETURN_CONSENT_SYNTHETIC_PAGE_CONTENT_7f624c</p>';
	private const SYNTHETIC_FIRSTNAME = 'Тест';
	private const SYNTHETIC_LASTNAME = 'Возврата';
	private const SYNTHETIC_TELEPHONE = '70000000000';
	private const SYNTHETIC_ORDER_ID = '987654321';

	private ?mysqli $database = null;
	private array $originalReturnSettings = array();
	private ?int $informationId = null;
	private ?int $settingId = null;
	private ?int $customerId = null;
	private ?int $returnReasonId = null;
	private array $syntheticReturnIds = array();
	private string $customerEmail = '';
	private string $customerPassword = '';
	private string $screenshotsDirectory = '';
	private array $countsBefore = array();
	private array $auditReport = array();

	protected function setUp(): void {
		parent::setUp();

		if (!defined('DB_DATABASE') || DB_DATABASE !== 'charm_functional' || !defined('HTTP_SERVER')) {
			throw new RuntimeException('Тест возврата требует одноразовую MariaDB TestBootstrap charm_functional и loopback storefront.');
		}

		$storefront = parse_url((string)HTTP_SERVER);
		if (!is_array($storefront) || !in_array($storefront['host'] ?? '', array('127.0.0.1', 'localhost'), true)) {
			throw new RuntimeException('Тест возврата разрешает запросы только к loopback storefront TestBootstrap.');
		}

		if (!defined('DIR_LOGS') || !str_starts_with((string)DIR_LOGS, sys_get_temp_dir() . '/sylora-functional-tests-')) {
			throw new RuntimeException('Логи тестового storefront должны находиться в одноразовом каталоге TestBootstrap под /tmp.');
		}

		if (!defined('DB_PREFIX') || preg_match('/^[A-Za-z0-9_]*$/', (string)DB_PREFIX) !== 1) {
			throw new RuntimeException('Префикс таблиц тестовой базы отсутствует или содержит неожиданные символы.');
		}

		mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
		$this->database = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
		$this->database->set_charset('utf8mb4');
		$this->originalReturnSettings = $this->readOriginalReturnSettings();
		$this->countsBefore = $this->readOrderReturnCounts();
		$this->screenshotsDirectory = sys_get_temp_dir() . '/sylora-return-consent-' . bin2hex(random_bytes(8));

		if (!mkdir($this->screenshotsDirectory, 0700) && !is_dir($this->screenshotsDirectory)) {
			throw new RuntimeException('Не удалось создать отдельный каталог screenshot в /tmp.');
		}

		$this->seedSyntheticReturnScenario();
	}

	protected function tearDown(): void {
		$cleanupError = null;

		try {
			$this->cleanupSyntheticReturnScenario();
		} catch (Throwable $exception) {
			$cleanupError = $exception;
		}

		try {
			$countsAfterCleanup = $this->database instanceof mysqli ? $this->readOrderReturnCounts() : array();
			$cleanupResiduals = $this->database instanceof mysqli ? $this->readSyntheticCleanupResiduals() : array();
			$settingsRestored = $this->database instanceof mysqli && $this->readOriginalReturnSettings() === $this->originalReturnSettings;
			$residualRowsRemain = array_sum($cleanupResiduals) > 0;

			if ($residualRowsRemain || !$settingsRestored) {
				$cleanupError ??= new RuntimeException('После cleanup остались синтетические строки или не восстановлен config_return_id.');
			}

			$this->auditReport['orderReturnCounts'] = array(
				'before' => $this->countsBefore,
				'afterUnchecked' => $this->auditReport['afterUnchecked'] ?? null,
				'afterChecked' => $this->auditReport['afterChecked'] ?? null,
				'afterCleanup' => $countsAfterCleanup,
			);
			$this->auditReport['cleanupResidualRows'] = $cleanupResiduals;
			$this->auditReport['returnSettingsRestored'] = $settingsRestored;
			$this->auditReport['cleanupSucceeded'] = $cleanupError === null;
			$this->auditReport['screenshotsDirectory'] = $this->screenshotsDirectory;

			if ($this->screenshotsDirectory !== '' && is_dir($this->screenshotsDirectory)) {
				$reportPath = $this->screenshotsDirectory . '/audit-report.json';
				file_put_contents($reportPath, json_encode($this->auditReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
				chmod($reportPath, 0600);
			}
		} catch (Throwable $exception) {
			$cleanupError ??= $exception;
		}

		if ($this->database instanceof mysqli) {
			$this->database->close();
			$this->database = null;
		}

		parent::tearDown();

		if ($cleanupError instanceof Throwable) {
			throw new RuntimeException('Очистка изолированного сценария возврата не завершилась: ' . $cleanupError->getMessage(), 0, $cleanupError);
		}
	}

	public function testReturnAgreementIsLinkedRequiredAndAcceptedOnlyAfterExplicitCheck(): void {
		$scenario = $this->buildBrowserScenario();
		$encodedScenario = rtrim(strtr(base64_encode(json_encode($scenario, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
		$runner = __DIR__ . '/support/return_consent_browser.mjs';

		$agreement = $this->runBrowserScenario($runner, 'audit:' . $encodedScenario);
		$this->auditReport['agreementBrowserResult'] = $agreement;
		self::assertSame(200, (int)($agreement['informationResponseStatus'] ?? 0));
		self::assertSame(self::SYNTHETIC_INFORMATION_CONTENT, trim((string)($agreement['informationResponseBody'] ?? '')));
		self::assertSame((string)$this->informationId, (string)($agreement['agreementInformationId'] ?? ''));
		self::assertTrue($agreement['checkboxExists'] ?? false);
		self::assertFalse($agreement['checkboxChecked'] ?? true);
		self::assertSame('1', (string)($agreement['checkboxValue'] ?? ''));
		self::assertTrue($agreement['linkInsideCheckboxLabel'] ?? false);
		self::assertSame(self::SYNTHETIC_INFORMATION_TITLE, (string)($agreement['agreementLinkText'] ?? ''));
		self::assertStringContainsString('Я прочитал(-а) и согласен с', (string)($agreement['agreementLabelText'] ?? ''));
		self::assertStringContainsString(self::SYNTHETIC_INFORMATION_TITLE, (string)($agreement['agreementLabelText'] ?? ''));
		self::assertTrue($agreement['uncheckedRejected'] ?? false);
		self::assertStringContainsString(self::SYNTHETIC_INFORMATION_TITLE, (string)($agreement['uncheckedWarning'] ?? ''));
		self::assertSame(0, (int)($agreement['blockedExternalRequestCount'] ?? -1));
		self::assertSame(array(320, 390, 1440), array_map('intval', array_keys($agreement['screenshots'] ?? array())));

		foreach ($agreement['screenshots'] as $screenshot) {
			self::assertFileExists((string)$screenshot);
		}

		$afterUnchecked = $this->readOrderReturnCounts();
		$this->auditReport['afterUnchecked'] = $afterUnchecked;
		self::assertSame($this->countsBefore['returns'], $afterUnchecked['returns'], 'POST без согласия не должен создавать return.');
		self::assertSame($this->countsBefore['orders'], $afterUnchecked['orders'], 'Проверка формы возврата не должна создавать order.');

		$checked = $this->runBrowserScenario($runner, 'checked:' . $encodedScenario);
		$this->auditReport['checkedBrowserResult'] = $checked;
		$afterChecked = $this->readOrderReturnCounts();
		$this->auditReport['afterChecked'] = $afterChecked;
		if ((int)($checked['checkedRedirectStatus'] ?? 0) !== 302) {
			$webErrorLogPath = DIR_LOGS . 'error.log';
			$webErrorLog = is_file($webErrorLogPath) ? file_get_contents($webErrorLogPath) : '';
			$this->auditReport['webErrorLogTail'] = is_string($webErrorLog) ? substr($webErrorLog, -4000) : '';
		}
		$redirectQuery = array();
		parse_str((string)parse_url((string)($checked['checkedRedirectLocation'] ?? ''), PHP_URL_QUERY), $redirectQuery);
		self::assertSame(302, (int)($checked['checkedRedirectStatus'] ?? 0), 'OpenCart должен принять POST и вернуть редирект на экран успеха.');
		self::assertSame('account/return/success', $redirectQuery['route'] ?? null);
		self::assertSame($this->countsBefore['returns'] + 1, $afterChecked['returns'], 'POST с явным agree должен создать ровно одну тестовую запись return.');
		self::assertSame($this->countsBefore['orders'], $afterChecked['orders'], 'Успешная форма возврата не должна создавать order.');

		$return = $this->readSyntheticReturn();
		$this->auditReport['syntheticReturn'] = $return;
		self::assertSame((string)$this->customerId, (string)($return['customer_id'] ?? ''));
		self::assertSame((string)self::SYNTHETIC_ORDER_ID, (string)($return['order_id'] ?? ''));
	}

	private function seedSyntheticReturnScenario(): void {
		$database = $this->getDatabase();
		$this->customerEmail = 'return-consent-' . bin2hex(random_bytes(5)) . '@example.invalid';
		$this->customerPassword = bin2hex(random_bytes(12));
		$salt = substr(bin2hex(random_bytes(5)), 0, 9);
		$passwordHash = sha1($salt . sha1($salt . sha1($this->customerPassword)));

		$information = $database->prepare('INSERT INTO `' . $this->tableName('information') . '` (`status`) VALUES (1)');
		$information->execute();
		$this->informationId = (int)$database->insert_id;
		$information->close();

		$description = $database->prepare('INSERT INTO `' . $this->tableName('information_description') . '` (`information_id`, `language_id`, `title`, `description`, `meta_title`, `meta_description`, `meta_keyword`) VALUES (?, 1, ?, ?, \'\', \'\', \'\')');
		$description->bind_param('iss', $this->informationId, $title, $content);
		$title = self::SYNTHETIC_INFORMATION_TITLE;
		$content = self::SYNTHETIC_INFORMATION_CONTENT;
		$description->execute();
		$description->close();

		$informationToStore = $database->prepare('INSERT INTO `' . $this->tableName('information_to_store') . '` (`information_id`, `store_id`) VALUES (?, 0)');
		$informationToStore->bind_param('i', $this->informationId);
		$informationToStore->execute();
		$informationToStore->close();

		$setting = $database->prepare('INSERT INTO `' . $this->tableName('setting') . '` (`store_id`, `code`, `key`, `value`, `serialized`) VALUES (0, \'config\', \'config_return_id\', ?, 0)');
		$informationId = (string)$this->informationId;
		$setting->bind_param('s', $informationId);
		$setting->execute();
		$this->settingId = (int)$database->insert_id;
		$setting->close();

		$customer = $database->prepare('INSERT INTO `' . $this->tableName('customer') . '` (`customer_group_id`, `store_id`, `language_id`, `firstname`, `lastname`, `email`, `telephone`, `fax`, `password`, `salt`, `custom_field`, `ip`, `status`, `safe`, `token`, `code`, `date_added`) VALUES (1, 0, 1, ?, ?, ?, ?, \'\', ?, ?, \'\', \'\', 1, 0, \'\', \'\', NOW())');
		$firstname = self::SYNTHETIC_FIRSTNAME;
		$lastname = self::SYNTHETIC_LASTNAME;
		$telephone = self::SYNTHETIC_TELEPHONE;
		$customer->bind_param('ssssss', $firstname, $lastname, $this->customerEmail, $telephone, $passwordHash, $salt);
		$customer->execute();
		$this->customerId = (int)$database->insert_id;
		$customer->close();

		$returnReason = $database->prepare('INSERT INTO `' . $this->tableName('return_reason') . '` (`language_id`, `name`) VALUES (1, ?)');
		$reason = 'Синтетическая причина возврата';
		$returnReason->bind_param('s', $reason);
		$returnReason->execute();
		$this->returnReasonId = (int)$database->insert_id;
		$returnReason->close();
	}

	private function cleanupSyntheticReturnScenario(): void {
		if (!$this->database instanceof mysqli) {
			return;
		}

		$database = $this->database;
		if ($this->customerId !== null) {
			$returnIds = $database->prepare('SELECT `return_id` FROM `' . $this->tableName('return') . '` WHERE `customer_id` = ?');
			$returnIds->bind_param('i', $this->customerId);
			$returnIds->execute();
			$result = $returnIds->get_result();
			$ids = array_map(static fn (array $row): int => (int)$row['return_id'], $result->fetch_all(MYSQLI_ASSOC));
			$this->syntheticReturnIds = $ids;
			$result->free();
			$returnIds->close();

			if ($ids !== array()) {
				$history = $database->prepare('DELETE FROM `' . $this->tableName('return_history') . '` WHERE `return_id` = ?');
				foreach ($ids as $returnId) {
					$history->bind_param('i', $returnId);
					$history->execute();
				}
				$history->close();
			}

			foreach (array('return', 'customer_ip', 'customer_online', 'customer_activity', 'customer_history', 'customer_approval', 'customer_reward', 'customer_transaction') as $table) {
				$delete = $database->prepare('DELETE FROM `' . $this->tableName($table) . '` WHERE `customer_id` = ?');
				$delete->bind_param('i', $this->customerId);
				$delete->execute();
				$delete->close();
			}

			$loginAttempts = $database->prepare('DELETE FROM `' . $this->tableName('customer_login') . '` WHERE `email` = ?');
			$loginAttempts->bind_param('s', $this->customerEmail);
			$loginAttempts->execute();
			$loginAttempts->close();

			$sessionPattern = '%"customer_id":' . $this->customerId . '%';
			$sessions = $database->prepare('DELETE FROM `' . $this->tableName('session') . '` WHERE `data` LIKE ?');
			$sessions->bind_param('s', $sessionPattern);
			$sessions->execute();
			$sessions->close();

			$customer = $database->prepare('DELETE FROM `' . $this->tableName('customer') . '` WHERE `customer_id` = ?');
			$customer->bind_param('i', $this->customerId);
			$customer->execute();
			$customer->close();
		}

		if ($this->returnReasonId !== null) {
			$reason = $database->prepare('DELETE FROM `' . $this->tableName('return_reason') . '` WHERE `return_reason_id` = ? AND `language_id` = 1');
			$reason->bind_param('i', $this->returnReasonId);
			$reason->execute();
			$reason->close();
		}

		if ($this->informationId !== null) {
			foreach (array('information_description', 'information_to_layout', 'information_to_store', 'information') as $table) {
				$delete = $database->prepare('DELETE FROM `' . $this->tableName($table) . '` WHERE `information_id` = ?');
				$delete->bind_param('i', $this->informationId);
				$delete->execute();
				$delete->close();
			}
		}

		if ($this->settingId !== null) {
			$setting = $database->prepare('DELETE FROM `' . $this->tableName('setting') . '` WHERE `setting_id` = ?');
			$setting->bind_param('i', $this->settingId);
			$setting->execute();
			$setting->close();
		}

		$this->restoreOriginalReturnSettings();
	}

	private function restoreOriginalReturnSettings(): void {
		$database = $this->getDatabase();
		$delete = $database->prepare('DELETE FROM `' . $this->tableName('setting') . '` WHERE `store_id` = 0 AND `code` = \'config\' AND `key` = \'config_return_id\'');
		$delete->execute();
		$delete->close();

		foreach ($this->originalReturnSettings as $row) {
			$insert = $database->prepare('INSERT INTO `' . $this->tableName('setting') . '` (`setting_id`, `store_id`, `code`, `key`, `value`, `serialized`) VALUES (?, ?, ?, ?, ?, ?)');
			$insert->bind_param('iisssi', $row['setting_id'], $row['store_id'], $row['code'], $row['key'], $row['value'], $row['serialized']);
			$insert->execute();
			$insert->close();
		}
	}

	private function readOriginalReturnSettings(): array {
		$statement = $this->getDatabase()->prepare('SELECT `setting_id`, `store_id`, `code`, `key`, `value`, `serialized` FROM `' . $this->tableName('setting') . '` WHERE `store_id` = 0 AND `code` = \'config\' AND `key` = \'config_return_id\' ORDER BY `setting_id`');
		$statement->execute();
		$result = $statement->get_result();
		$rows = $result->fetch_all(MYSQLI_ASSOC);
		$result->free();
		$statement->close();

		return $rows;
	}

	private function readOrderReturnCounts(): array {
		return array(
			'orders' => $this->countRows('order'),
			'returns' => $this->countRows('return'),
		);
	}

	private function readSyntheticReturn(): array {
		$statement = $this->getDatabase()->prepare('SELECT `customer_id`, `order_id`, `return_action_id` FROM `' . $this->tableName('return') . '` WHERE `customer_id` = ?');
		$statement->bind_param('i', $this->customerId);
		$statement->execute();
		$result = $statement->get_result();
		$row = $result->fetch_assoc() ?: array();
		$result->free();
		$statement->close();

		return $row;
	}

	private function readSyntheticCleanupResiduals(): array {
		$residuals = array();

		if ($this->customerId !== null) {
			foreach (array(
				'customer' => 'customer_id',
				'customer_ip' => 'customer_id',
				'customer_online' => 'customer_id',
				'customer_activity' => 'customer_id',
				'customer_history' => 'customer_id',
				'customer_approval' => 'customer_id',
				'customer_reward' => 'customer_id',
				'customer_transaction' => 'customer_id',
				'return' => 'customer_id',
			) as $table => $column) {
				$residuals[$table] = $this->countRowsByValue($table, $column, 'i', $this->customerId);
			}

			$residuals['customer_login'] = $this->countRowsByValue('customer_login', 'email', 's', $this->customerEmail);
			$sessionPattern = '%"customer_id":' . $this->customerId . '%';
			$residuals['session'] = $this->countRowsByValue('session', 'data', 's', $sessionPattern, 'LIKE');
		}

		if ($this->returnReasonId !== null) {
			$residuals['return_reason'] = $this->countRowsByValue('return_reason', 'return_reason_id', 'i', $this->returnReasonId);
		}

		if ($this->syntheticReturnIds !== array()) {
			$returnIds = implode(',', array_map('intval', $this->syntheticReturnIds));
			$result = $this->getDatabase()->query('SELECT COUNT(*) AS `row_count` FROM `' . $this->tableName('return_history') . '` WHERE `return_id` IN (' . $returnIds . ')');
			$row = $result->fetch_assoc();
			$result->free();
			$residuals['return_history'] = (int)($row['row_count'] ?? 0);
		}

		if ($this->informationId !== null) {
			foreach (array('information', 'information_description', 'information_to_store', 'information_to_layout') as $table) {
				$residuals[$table] = $this->countRowsByValue($table, 'information_id', 'i', $this->informationId);
			}
		}

		if ($this->settingId !== null) {
			$residuals['setting'] = $this->countRowsByValue('setting', 'setting_id', 'i', $this->settingId);
		}

		return $residuals;
	}

	private function buildBrowserScenario(): array {
		return array(
			'informationId' => $this->informationId,
			'informationTitle' => self::SYNTHETIC_INFORMATION_TITLE,
			'informationContent' => self::SYNTHETIC_INFORMATION_CONTENT,
			'customerEmail' => $this->customerEmail,
			'customerPassword' => $this->customerPassword,
			'customerFirstname' => self::SYNTHETIC_FIRSTNAME,
			'customerLastname' => self::SYNTHETIC_LASTNAME,
			'customerTelephone' => self::SYNTHETIC_TELEPHONE,
			'orderId' => self::SYNTHETIC_ORDER_ID,
			'returnReasonId' => $this->returnReasonId,
			'screenshotsDirectory' => $this->screenshotsDirectory,
		);
	}

	private function getDatabase(): mysqli {
		if (!$this->database instanceof mysqli) {
			throw new RuntimeException('Соединение с одноразовой MariaDB тестов отсутствует.');
		}

		return $this->database;
	}

	private function tableName(string $table): string {
		return DB_PREFIX . $table;
	}

	private function countRows(string $table): int {
		$result = $this->getDatabase()->query('SELECT COUNT(*) AS `row_count` FROM `' . $this->tableName($table) . '`');
		$row = $result->fetch_assoc();
		$result->free();

		return (int)($row['row_count'] ?? 0);
	}

	private function countRowsByValue(string $table, string $column, string $type, int|string $value, string $operator = '='): int {
		if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1 || !in_array($operator, array('=', 'LIKE'), true) || !in_array($type, array('i', 's'), true)) {
			throw new RuntimeException('Запрещённый идентификатор в проверке cleanup.');
		}

		$statement = $this->getDatabase()->prepare('SELECT COUNT(*) AS `row_count` FROM `' . $this->tableName($table) . '` WHERE `' . $column . '` ' . $operator . ' ?');
		$statement->bind_param($type, $value);
		$statement->execute();
		$result = $statement->get_result();
		$row = $result->fetch_assoc();
		$result->free();
		$statement->close();

		return (int)($row['row_count'] ?? 0);
	}
}
