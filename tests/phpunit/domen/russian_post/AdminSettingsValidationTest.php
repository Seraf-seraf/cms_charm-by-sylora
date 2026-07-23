<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Controller {
	public function __construct(private readonly array $services) {
	}

	public function __get(string $name): object {
		return $this->services[$name];
	}
}

require_once dirname(__DIR__, 4) . '/upload/admin/controller/extension/shipping/russian_post.php';

final class TestableRussianPostController extends ControllerExtensionShippingRussianPost {
	public function validateSettings(): bool {
		return $this->validate();
	}
}

final class AdminSettingsValidationTest extends TestCase {
	public function testRawApiCredentialsAreAccepted(): void {
		$request = new stdClass();
		$request->post = [
			'shipping_russian_post_status' => '1',
			'shipping_russian_post_origin_postcode' => '644000',
			'shipping_russian_post_token' => 'access-token',
			'shipping_russian_post_login' => 'api-login',
			'shipping_russian_post_password' => 'api-password',
		];
		$controller = new TestableRussianPostController([
			'user' => new class {
				public function hasPermission(string $action, string $route): bool {
					return $action === 'modify' && $route === 'extension/shipping/russian_post';
				}
			},
			'language' => new class {
				public function get(string $key): string {
					return $key;
				}
			},
			'request' => $request,
			'config' => new class {
				public function get(string $key): mixed {
					return null;
				}
			},
		]);

		self::assertTrue($controller->validateSettings());
	}
}
