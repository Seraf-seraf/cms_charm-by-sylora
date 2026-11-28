<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!class_exists('Controller')) {
	abstract class Controller {}
}

final class DatabaseSecretStorageTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testPaymentServiceAcceptsDatabaseValuesAndRejectsEnvironmentReferences(): void {
		if (!defined('DIR_SYSTEM')) {
			define('DIR_SYSTEM', $this->root . '/upload/system/');
		}

		require_once $this->root . '/upload/admin/controller/extension/payment/payment_service.php';
		$controller = (new ReflectionClass(ControllerExtensionPaymentPaymentService::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod($controller, 'isStoredSecret');

		self::assertTrue($method->invoke($controller, 'merchant-api-key', 1));
		self::assertTrue($method->invoke($controller, str_repeat('s', 32), 32));
		self::assertFalse($method->invoke($controller, 'env:PAYMENT_SERVICE_API_KEY', 1));
		self::assertFalse($method->invoke($controller, 'short', 32));
	}

	public function testSmartCaptchaAcceptsDatabaseValueAndRejectsEnvironmentReference(): void {
		require_once $this->root . '/upload/admin/controller/extension/captcha/smartcaptcha.php';
		$controller = (new ReflectionClass(ControllerExtensionCaptchaSmartcaptcha::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod($controller, 'isStoredSecret');

		self::assertTrue($method->invoke($controller, 'actual-server-key'));
		self::assertFalse($method->invoke($controller, 'env:YANDEX_SMARTCAPTCHA_SECRET'));
		self::assertFalse($method->invoke($controller, ''));
	}

	public function testActiveYandexCaptchaAcceptsDatabaseValueAndRejectsEnvironmentReference(): void {
		require_once $this->root . '/upload/admin/controller/extension/captcha/yandex.php';
		$controller = (new ReflectionClass(ControllerExtensionCaptchaYandex::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod($controller, 'isStoredSecret');

		self::assertTrue($method->invoke($controller, 'actual-server-key'));
		self::assertFalse($method->invoke($controller, 'env:YANDEX_SMARTCAPTCHA_SECRET'));
		self::assertFalse($method->invoke($controller, ''));
	}

	public function testCustomRuntimeReadsSettingsWithoutEnvironmentResolver(): void {
		$runtimeFiles = array(
			'upload/catalog/controller/extension/payment/payment_service.php',
			'upload/catalog/controller/extension/captcha/smartcaptcha.php',
			'upload/catalog/controller/extension/captcha/yandex.php',
			'upload/system/library/cdek_official/src/Transport/CdekApi.php',
		);

		foreach ($runtimeFiles as $relativePath) {
			$source = file_get_contents($this->root . '/' . $relativePath);

			self::assertIsString($source);
			self::assertStringNotContainsString('SyloraSecret', $source, $relativePath);
			self::assertStringNotContainsString('getenv(', $source, $relativePath);
		}

		self::assertFileDoesNotExist($this->root . '/upload/system/library/sylora_secret.php');
	}

	public function testInstallMigrationsDoNotCreateEnvironmentReferences(): void {
		$migrations = array(
			'database/migrations/2026_07_14_000010_security_email_defaults.php',
			'database/migrations/2026_07_14_000011_yandex_smartcaptcha.php',
		);

		foreach ($migrations as $relativePath) {
			$source = file_get_contents($this->root . '/' . $relativePath);

			self::assertIsString($source);
			self::assertStringNotContainsString('env:', $source, $relativePath);
		}
	}

	public function testAdminTemplatesDoNotRenderStoredSecrets(): void {
		$paymentController = file_get_contents($this->root . '/upload/admin/controller/extension/payment/payment_service.php');
		$captchaController = file_get_contents($this->root . '/upload/admin/controller/extension/captcha/smartcaptcha.php');
		$yandexController = file_get_contents($this->root . '/upload/admin/controller/extension/captcha/yandex.php');
		$paymentTemplate = file_get_contents($this->root . '/upload/admin/view/template/extension/payment/payment_service.twig');
		$captchaTemplate = file_get_contents($this->root . '/upload/admin/view/template/extension/captcha/smartcaptcha.twig');
		$yandexTemplate = file_get_contents($this->root . '/upload/admin/view/template/extension/captcha/yandex.twig');

		self::assertIsString($paymentController);
		self::assertIsString($captchaController);
		self::assertIsString($yandexController);
		self::assertIsString($paymentTemplate);
		self::assertIsString($captchaTemplate);
		self::assertIsString($yandexTemplate);
		self::assertStringContainsString("['payment_payment_service_api_key'] = '';", $paymentController);
		self::assertStringContainsString("['payment_payment_service_shared_secret'] = '';", $paymentController);
		self::assertStringContainsString("['captcha_smartcaptcha_secret'] = '';", $captchaController);
		self::assertStringContainsString("['captcha_yandex_secret'] = '';", $yandexController);
		self::assertStringContainsString('type="password" name="payment_payment_service_api_key"', $paymentTemplate);
		self::assertStringContainsString('type="password" name="captcha_smartcaptcha_secret"', $captchaTemplate);
		self::assertStringContainsString('type="password" name="captcha_yandex_secret"', $yandexTemplate);
		self::assertStringContainsString('value=""', $yandexTemplate);
	}

	public function testActiveYandexCaptchaUsesPostAndTlsVerification(): void {
		$controller = file_get_contents($this->root . '/upload/catalog/controller/extension/captcha/yandex.php');

		self::assertIsString($controller);
		self::assertStringContainsString('CURLOPT_POST, true', $controller);
		self::assertStringContainsString('CURLOPT_SSL_VERIFYHOST, 2', $controller);
		self::assertStringContainsString('CURLOPT_SSL_VERIFYPEER, true', $controller);
		self::assertStringNotContainsString('validate?', $controller);
		self::assertStringNotContainsString('CURLOPT_SSL_VERIFYHOST, false', $controller);
		self::assertStringNotContainsString('CURLOPT_SSL_VERIFYPEER, false', $controller);
	}
}
