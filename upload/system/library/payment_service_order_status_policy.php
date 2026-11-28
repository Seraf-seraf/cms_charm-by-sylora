<?php
final class PaymentServiceOrderStatusPolicy {
	private const NON_STOCK_STATUSES = array('pending', 'failed', 'canceled', 'refunded');

	public static function violations(array $processing_statuses, array $complete_statuses, array $payment_statuses): array {
		$stock_statuses = array();

		foreach (array_merge($processing_statuses, $complete_statuses) as $status_id) {
			$status_id = (int)$status_id;

			if ($status_id > 0) {
				$stock_statuses[$status_id] = true;
			}
		}

		$violations = array();

		foreach (self::NON_STOCK_STATUSES as $status) {
			$status_id = isset($payment_statuses[$status]) ? (int)$payment_statuses[$status] : 0;

			if ($status_id <= 0 || isset($stock_statuses[$status_id])) {
				$violations[$status] = 'must_not_affect_stock';
			}
		}

		$success_status_id = isset($payment_statuses['succeeded']) ? (int)$payment_statuses['succeeded'] : 0;

		if ($success_status_id <= 0 || !isset($stock_statuses[$success_status_id])) {
			$violations['succeeded'] = 'must_affect_stock';
		}

		return $violations;
	}
}
