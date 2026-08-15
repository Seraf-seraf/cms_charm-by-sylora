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
		if (!defined('DB_HOSTNAME') || !defined('DB_DATABASE') || !defined('DB_USERNAME') || !defined('DB_PREFIX')) {
			self::markTestSkipped('Локальная конфигурация OpenCart недоступна для чтения конфигурации из БД.');
		}

		$db = new mysqli(
			DB_HOSTNAME,
			DB_USERNAME,
			DB_PASSWORD,
			DB_DATABASE,
			(int)DB_PORT
		);

		$db->set_charset('utf8mb4');
		$query = "SELECT `value` FROM `{$db->real_escape_string(DB_PREFIX)}setting` WHERE `store_id` = 0 AND `key` = '" . $db->real_escape_string($settingKey) . "' LIMIT 1";
		$rows = $db->query($query);

		if (!$rows instanceof mysqli_result || $rows->num_rows === 0) {
			$db->close();
			return null;
		}

		$result = $rows->fetch_assoc();
		$rows->free();

		if (!isset($result['value']) || !is_string($result['value'])) {
			$db->close();
			return null;
		}

		$db->close();

		return (int)$result['value'] > 0 ? (int)$result['value'] : null;
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
