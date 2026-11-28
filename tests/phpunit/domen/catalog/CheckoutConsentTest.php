<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/BrowserTestCase.php';

final class CheckoutConsentTest extends BrowserTestCase {
	private function runConsentScenario(string $scenario, string $argument = ''): array {
		$scenarioArg = $scenario;

		if ($argument !== '') {
			$scenarioArg .= ':' . $argument;
		}

		$result = $this->runBrowserScenario(
			dirname(__DIR__) . '/catalog/support/checkout_consent_browser.mjs',
			$scenarioArg
		);

		self::assertIsArray($result);
		return $result;
	}

	private function getSettingInformationId(string $settingKey): ?int {
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
		$temporaryRootPrefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sylora-functional-tests-';
		$logsDirectory = rtrim((string)DIR_LOGS, DIRECTORY_SEPARATOR);
		$logsPattern = '~^' . preg_quote($temporaryRootPrefix, '~') . '[a-f0-9]{16}/storage/logs$~D';
		$databasePort = filter_var(DB_PORT, FILTER_VALIDATE_INT);
		$databasePrefix = (string)DB_PREFIX;

		if (
			DB_DATABASE !== 'charm_functional'
			|| DB_USERNAME !== 'charm_test'
			|| DB_HOSTNAME !== '127.0.0.1'
			|| !is_array($storefront)
			|| ($storefront['host'] ?? '') !== '127.0.0.1'
			|| ($storefront['scheme'] ?? '') !== 'http'
			|| preg_match($logsPattern, $logsDirectory) !== 1
			|| is_link($logsDirectory)
			|| realpath($logsDirectory) !== $logsDirectory
			|| !is_int($databasePort)
			|| $databasePort < 1024
			|| $databasePort > 65535
			|| preg_match('/^[A-Za-z0-9_]*$/', $databasePrefix) !== 1
		) {
			throw new RuntimeException('Checkout consent tests разрешают доступ только к loopback storefront и одноразовой БД TestBootstrap.');
		}

		$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, $databasePort);

		try {
			$db->set_charset('utf8mb4');
			$statement = $db->prepare('SELECT `value` FROM `' . $databasePrefix . "setting` WHERE `store_id` = 0 AND `key` = ? LIMIT 1");
			$statement->bind_param('s', $settingKey);
			$statement->execute();
			$statement->bind_result($value);

			if (!$statement->fetch() || !is_string($value)) {
				$statement->close();
				return null;
			}

			$statement->close();
			$informationId = (int)$value;

			return $informationId > 0 ? $informationId : null;
		} finally {
			$db->close();
		}
	}

	public function testRegistrationPageShowsAgreementCheckbox(): void {
		$expectedId = $this->getSettingInformationId('config_account_id');
		self::assertNotEmpty($expectedId);

		$state = $this->runConsentScenario('account_page');
		self::assertTrue($state['agreeInputExists']);
		self::assertSame((string)$expectedId, (string)$state['agreementInformationId']);
		self::assertStringContainsString('information/information/agree', (string)$state['agreementHref']);
	}

	public function testCheckoutRegisterStepShowsAgreementCheckbox(): void {
		$expectedId = $this->getSettingInformationId('config_account_id');
		self::assertNotEmpty($expectedId);

		$state = $this->runConsentScenario('checkout_register_fragment');
		self::assertTrue($state['agreeInputExists']);
		self::assertSame((string)$expectedId, (string)$state['agreementInformationId']);
	}

	public function testCheckoutPaymentMethodStepShowsCheckoutAgreementCheckbox(): void {
		$expectedId = $this->getSettingInformationId('config_checkout_id');
		self::assertNotEmpty($expectedId);

		$state = $this->runConsentScenario('checkout_payment_method_fragment');
		self::assertTrue($state['agreeInputExists']);
		self::assertSame((string)$expectedId, (string)$state['agreementInformationId']);
	}

	public function testRegisterEndpointRejectsMissingAgreementWithoutJavascript(): void {
		$state = $this->runConsentScenario('register_without_agreement');

		self::assertSame(200, (int)$state['status']);
		self::assertNotEmpty((string)$state['responseBody']);
		self::assertNotEmpty((string)$state['alertDangerText']);
		self::assertStringContainsString('соглас', mb_strtolower((string)$state['alertDangerText'], 'UTF-8'));
	}

	public function testInformationAgreeReturnsContentForConfiguredAgreement(): void {
		$informationId = $this->getSettingInformationId('config_account_id');
		self::assertNotEmpty($informationId);

		$state = $this->runConsentScenario('information_agree_success', (string)$informationId);
		self::assertSame(200, (int)$state['status']);
		self::assertNotSame('', trim((string)$state['text']));
	}

	public function testInformationAgreeWithoutIdIsEmpty(): void {
		$state = $this->runConsentScenario('information_agree_missing');
		self::assertSame(200, (int)$state['status']);
		self::assertSame('', trim((string)$state['text']));
	}

	public function testInformationAgreeInvalidIdIsEmpty(): void {
		$state = $this->runConsentScenario('information_agree_wrong');
		self::assertSame(200, (int)$state['status']);
		self::assertSame('', trim((string)$state['text']));
	}
}
