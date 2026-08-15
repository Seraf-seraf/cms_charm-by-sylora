<?php
require_once DIR_SYSTEM . 'library/payment_service_order_status_policy.php';

class ModelExtensionPaymentPaymentService extends Model {
	public function getMethod($address, $total) {
		$this->load->language('extension/payment/payment_service');

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_payment_service_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

		$violations = PaymentServiceOrderStatusPolicy::violations(
			(array)$this->config->get('config_processing_status'),
			(array)$this->config->get('config_complete_status'),
			array(
				'pending'   => $this->config->get('payment_payment_service_pending_status_id'),
				'succeeded' => $this->config->get('payment_payment_service_success_status_id'),
				'failed'    => $this->config->get('payment_payment_service_failed_status_id'),
				'canceled'  => $this->config->get('payment_payment_service_canceled_status_id'),
				'refunded'  => $this->config->get('payment_payment_service_refunded_status_id')
			)
		);

		if ($violations) {
			$status = false;
		} elseif ($total <= 0) {
			$status = false;
		} elseif (!isset($this->session->data['currency']) || strtoupper($this->session->data['currency']) !== 'RUB') {
			$status = false;
		} elseif ($this->config->get('payment_payment_service_total') > 0 && $this->config->get('payment_payment_service_total') > $total) {
			$status = false;
		} elseif (!$this->config->get('payment_payment_service_geo_zone_id')) {
			$status = true;
		} elseif ($query->num_rows) {
			$status = true;
		} else {
			$status = false;
		}

		$method_data = array();

		if ($status) {
			$method_data = array(
				'code'       => 'payment_service',
				'title'      => $this->language->get('text_title'),
				'terms'      => '',
				'sort_order' => $this->config->get('payment_payment_service_sort_order')
			);
		}

		return $method_data;
	}
}
