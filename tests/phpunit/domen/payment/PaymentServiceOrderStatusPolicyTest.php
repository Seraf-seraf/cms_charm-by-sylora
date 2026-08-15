<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/upload/system/library/payment_service_order_status_policy.php';

final class PaymentServiceOrderStatusPolicyTest extends TestCase {
	private const SAFE_STATUSES = array(
		'pending'   => 1,
		'succeeded' => 2,
		'failed'    => 3,
		'canceled'  => 4,
		'refunded'  => 5,
	);

	public function testSafeConfigurationAllowsOnlySucceededToAffectStock(): void {
		self::assertSame(array(), PaymentServiceOrderStatusPolicy::violations(array(2), array(6), self::SAFE_STATUSES));
	}

	public function testEachUnpaidStatusIsRejectedAsProcessingOrComplete(): void {
		foreach (array('pending', 'failed', 'canceled', 'refunded') as $status) {
			$statusId = self::SAFE_STATUSES[$status];
			self::assertArrayHasKey($status, PaymentServiceOrderStatusPolicy::violations(array($statusId), array(6), self::SAFE_STATUSES));
			self::assertArrayHasKey($status, PaymentServiceOrderStatusPolicy::violations(array(2), array($statusId), self::SAFE_STATUSES));
		}
	}

	public function testSucceededIsRejectedOutsideStockAffectingStatuses(): void {
		self::assertSame(
			array('succeeded' => 'must_affect_stock'),
			PaymentServiceOrderStatusPolicy::violations(array(6), array(7), self::SAFE_STATUSES)
		);
	}

	public function testMissingStatusMappingsAreRejected(): void {
		$statuses = self::SAFE_STATUSES;
		$statuses['pending'] = 0;
		$statuses['succeeded'] = 0;

		self::assertSame(
			array('pending' => 'must_not_affect_stock', 'succeeded' => 'must_affect_stock'),
			PaymentServiceOrderStatusPolicy::violations(array(2), array(6), $statuses)
		);
	}

	public function testRuntimeGuardsConfigurationBeforePaymentAndOrderHistory(): void {
		$root = dirname(__DIR__, 4);
		$controller = file_get_contents($root . '/upload/catalog/controller/extension/payment/payment_service.php');
		$model = file_get_contents($root . '/upload/catalog/model/extension/payment/payment_service.php');
		$admin = file_get_contents($root . '/upload/admin/controller/extension/payment/payment_service.php');

		self::assertIsString($controller);
		self::assertIsString($model);
		self::assertIsString($admin);
		self::assertGreaterThanOrEqual(4, substr_count($controller, 'statusConfigurationViolation()'));
		self::assertStringContainsString("'unsafe_status_configuration_' . \$configuration_violation", $controller);
		self::assertStringContainsString('PaymentServiceOrderStatusPolicy::violations(', $model);
		self::assertStringContainsString('PaymentServiceOrderStatusPolicy::violations(', $admin);
	}
}
