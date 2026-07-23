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
require_once dirname(__DIR__, 4) . '/upload/system/library/russian_post_delivery.php';

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
			'shipping_russian_post_widget_id' => '62604',
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

	public function testPackageUsesProductFieldsAndConfiguredFallbacks(): void {
		$products = [
			[
				'quantity' => 1,
				'weight' => 0.2,
				'weight_class_id' => 1,
				'length' => 150.0,
				'width' => 100.0,
				'height' => 50.0,
				'length_class_id' => 2,
			],
			[
				'quantity' => 2,
				'weight' => 0.0,
				'weight_class_id' => 1,
				'length' => 0.0,
				'width' => 0.0,
				'height' => 0.0,
				'length_class_id' => 2,
			],
		];
		$config = new class {
			public function get(string $key): mixed {
				return [
					'shipping_russian_post_default_weight' => '200',
					'shipping_russian_post_default_length' => '15',
					'shipping_russian_post_default_width' => '10',
					'shipping_russian_post_default_height' => '5',
				][$key] ?? null;
			}
		};
		$registry = new class($config, $products) {
			public function __construct(private readonly object $config, private readonly array $products) {
			}

			public function get(string $key): object {
				return [
					'config' => $this->config,
					'cart' => new class($this->products) {
						public function __construct(private readonly array $products) {
						}

						public function getProducts(): array {
							return $this->products;
						}
					},
					'weight' => new class {
						public function convert(float $value, int $from, int $to): float {
							return $value * 1000;
						}
					},
					'length' => new class {
						public function convert(float $value, int $from, int $to): float {
							return $value / 10;
						}
					},
					'log' => new stdClass(),
				][$key];
			}
		};

		$delivery = new RussianPostDelivery($registry);

		self::assertSame([
			'weight' => 600,
			'length' => 15,
			'width' => 10,
			'height' => 15,
		], $delivery->getPackage());
		self::assertSame([[
			'length' => 15,
			'width' => 10,
			'height' => 15,
		]], $delivery->getWidgetDimensions($delivery->getPackage()));
	}
}
