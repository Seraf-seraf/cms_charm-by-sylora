<?php
class ControllerExtensionPaymentPaymentService extends Controller {
	const MAX_CALLBACK_BODY_BYTES = 65536;

	public function index() {
		$this->load->language('extension/payment/payment_service');

		$data['button_confirm'] = $this->language->get('button_confirm');
		$data['text_loading'] = $this->language->get('text_loading');
		$data['confirm'] = $this->url->link('extension/payment/payment_service/confirm', '', true);

		return $this->load->view('extension/payment/payment_service', $data);
	}

	public function confirm() {
		$json = array();

		if (!isset($this->session->data['payment_method']['code']) || $this->session->data['payment_method']['code'] != 'payment_service') {
			$json['error'] = 'Payment method is not selected';
		} elseif (empty($this->session->data['order_id'])) {
			$json['error'] = 'Order is not initialized';
		} else {
			$this->load->model('checkout/order');

			$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

			if (!$order_info) {
				$json['error'] = 'Order not found';
			} elseif (!function_exists('curl_init')) {
				$json['error'] = 'PHP cURL extension is not available';
			} else {
				$body = $this->buildCreatePaymentBody($order_info);
				$response = $this->createPayment($body);

				if (!empty($response['error'])) {
					$json['error'] = $response['error'];
				} elseif (empty($response['payment_url'])) {
					$json['error'] = 'Payment service returned empty payment_url';
				} else {
					$comment = sprintf(
						'Payment service payment_id: %s. Status: %s.',
						isset($response['payment_id']) ? $response['payment_id'] : '',
						isset($response['status']) ? $response['status'] : ''
					);

					$this->model_checkout_order->addOrderHistory(
						$order_info['order_id'],
						$this->config->get('payment_payment_service_pending_status_id'),
						$comment,
						false
					);

					$json['redirect'] = $response['payment_url'];
				}
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function callback() {
		if ($this->request->server['REQUEST_METHOD'] != 'POST') {
			return $this->jsonResponse(array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
		}

		if (!$this->isJsonRequest()) {
			return $this->jsonResponse(array('code' => 'unsupported_media_type', 'message' => 'Content-Type must be application/json'), 415);
		}

		if (!$this->isAllowedContentLength()) {
			return $this->jsonResponse(array('code' => 'payload_too_large', 'message' => 'Callback payload is too large'), 413);
		}

		$raw_body = file_get_contents('php://input');

		if ($raw_body === false || $raw_body === '') {
			return $this->jsonResponse(array('code' => 'empty_body', 'message' => 'Callback body is empty'), 400);
		}

		if (strlen($raw_body) > self::MAX_CALLBACK_BODY_BYTES) {
			return $this->jsonResponse(array('code' => 'payload_too_large', 'message' => 'Callback payload is too large'), 413);
		}

		$timestamp = isset($this->request->server['HTTP_X_TIMESTAMP']) ? $this->request->server['HTTP_X_TIMESTAMP'] : '';
		$signature = isset($this->request->server['HTTP_X_SIGNATURE']) ? $this->request->server['HTTP_X_SIGNATURE'] : '';

		if (!$this->verifySignature($timestamp, $signature, $raw_body)) {
			return $this->jsonResponse(array('code' => 'invalid_signature', 'message' => 'Invalid signature'), 401);
		}

		$payload = json_decode($raw_body, true);

		if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
			return $this->jsonResponse(array('code' => 'invalid_json', 'message' => 'Invalid JSON payload'), 400);
		}

		$validation_error = $this->validateCallbackPayload($payload);

		if ($validation_error) {
			return $this->jsonResponse($validation_error, 400);
		}

		$order_id = (int)$payload['order_id'];

		$this->load->model('checkout/order');
		$order_info = $this->model_checkout_order->getOrder($order_id);

		if (!$order_info) {
			return $this->jsonResponse(array('code' => 'order_not_found', 'message' => 'Order not found'), 404);
		}

		if ($order_info['payment_code'] != 'payment_service') {
			return $this->jsonResponse(array('code' => 'payment_method_mismatch', 'message' => 'Order payment method mismatch'), 409);
		}

		if (!$this->matchesOrderAmount($payload, $order_info)) {
			return $this->jsonResponse(array('code' => 'amount_mismatch', 'message' => 'Callback amount or currency does not match order'), 409);
		}

		if ($this->eventExists($payload['event_id'])) {
			return $this->jsonResponse(array('status' => 'ok'));
		}

		$order_status_id = $this->mapStatus($payload['status']);

		if (!$order_status_id) {
			return $this->jsonResponse(array('code' => 'unsupported_status', 'message' => 'Unsupported status'), 400);
		}

		if (!$this->saveEvent($payload, $raw_body, $order_id)) {
			return $this->jsonResponse(array('status' => 'ok'));
		}

		$comment = sprintf(
			'Payment service callback: %s. payment_id=%s, event_id=%s, amount_minor=%s %s.',
			$payload['status'],
			$payload['payment_id'],
			$payload['event_id'],
			$payload['amount_minor'],
			$payload['currency']
		);

		if ((int)$order_info['order_status_id'] != (int)$order_status_id) {
			$this->model_checkout_order->addOrderHistory($order_id, $order_status_id, $comment, true);
		}

		return $this->jsonResponse(array('status' => 'ok'));
	}

	private function isJsonRequest() {
		$content_type = isset($this->request->server['CONTENT_TYPE']) ? $this->request->server['CONTENT_TYPE'] : '';

		if ($content_type === '' && isset($this->request->server['HTTP_CONTENT_TYPE'])) {
			$content_type = $this->request->server['HTTP_CONTENT_TYPE'];
		}

		$content_type = strtolower(trim(explode(';', $content_type)[0]));

		return $content_type === 'application/json';
	}

	private function isAllowedContentLength() {
		if (!isset($this->request->server['CONTENT_LENGTH']) || $this->request->server['CONTENT_LENGTH'] === '') {
			return true;
		}

		if (!ctype_digit((string)$this->request->server['CONTENT_LENGTH'])) {
			return false;
		}

		return (int)$this->request->server['CONTENT_LENGTH'] <= self::MAX_CALLBACK_BODY_BYTES;
	}

	private function validateCallbackPayload($payload) {
		$required = array('event_id', 'event_type', 'payment_id', 'order_id', 'status', 'amount_minor', 'currency');

		foreach ($required as $field) {
			if (!array_key_exists($field, $payload) || $payload[$field] === '') {
				return array('code' => 'missing_field', 'message' => 'Missing field: ' . $field);
			}
		}

		if (!$this->isSafeString($payload['event_id'], 1, 64, '/^[A-Za-z0-9._:-]+$/')) {
			return array('code' => 'invalid_field', 'message' => 'Invalid field: event_id');
		}

		if (!$this->isSafeString($payload['event_type'], 1, 64, '/^[A-Za-z0-9._:-]+$/')) {
			return array('code' => 'invalid_field', 'message' => 'Invalid field: event_type');
		}

		if (!$this->isSafeString($payload['payment_id'], 1, 64, '/^[A-Za-z0-9._:-]+$/')) {
			return array('code' => 'invalid_field', 'message' => 'Invalid field: payment_id');
		}

		if (is_int($payload['order_id'])) {
			$order_id = $payload['order_id'];
		} elseif (is_string($payload['order_id']) && ctype_digit($payload['order_id'])) {
			$order_id = (int)$payload['order_id'];
		} else {
			return array('code' => 'invalid_field', 'message' => 'Invalid field: order_id');
		}

		if ($order_id <= 0) {
			return array('code' => 'invalid_field', 'message' => 'Invalid field: order_id');
		}

		if (!is_int($payload['amount_minor'])) {
			return array('code' => 'invalid_field', 'message' => 'Invalid field: amount_minor');
		}

		if ($payload['amount_minor'] <= 0) {
			return array('code' => 'invalid_field', 'message' => 'Invalid field: amount_minor');
		}

		if (!$this->isSafeString($payload['currency'], 3, 3, '/^[A-Z]{3}$/')) {
			return array('code' => 'invalid_field', 'message' => 'Invalid field: currency');
		}

		if (!is_string($payload['status']) || !in_array($payload['status'], array('pending', 'succeeded', 'failed', 'canceled', 'refunded'), true)) {
			return array('code' => 'invalid_field', 'message' => 'Invalid field: status');
		}

		return null;
	}

	private function isSafeString($value, $min_length, $max_length, $pattern) {
		if (!is_string($value)) {
			return false;
		}

		$length = strlen($value);

		if ($length < $min_length || $length > $max_length) {
			return false;
		}

		return preg_match($pattern, $value) === 1;
	}

	private function buildCreatePaymentBody($order_info) {
		$total = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
		$amount_minor = (int)round((float)$total * 100);
		$description = $this->config->get('config_name') . ' order #' . $order_info['order_id'];
		$items = $this->buildReceiptItems($order_info, $amount_minor, $description);

		$body = array(
			'order_id'       => (string)$order_info['order_id'],
			'amount_minor'   => $amount_minor,
			'currency'       => $order_info['currency_code'],
			'description'    => $description,
			'payment_method' => 'bank_card',
			'receipt'        => array(
				'taxation' => 'osn',
				'items'    => $items
			)
		);

		if ($this->isValidEmail($order_info['email'])) {
			$body['customer'] = array(
				'email' => $order_info['email']
			);

			if (strlen($order_info['email']) <= 64) {
				$body['receipt']['email'] = $order_info['email'];
			}
		}

		if (!empty($order_info['telephone'])) {
			$body['receipt']['phone'] = substr((string)$order_info['telephone'], 0, 64);
		}

		return $body;
	}

	private function buildReceiptItems($order_info, $amount_minor, $fallback_name) {
		$items = array();
		$products = $this->model_checkout_order->getOrderProducts($order_info['order_id']);

		foreach ($products as $product) {
			$quantity = isset($product['quantity']) ? (int)$product['quantity'] : 1;

			if ($quantity < 1) {
				$quantity = 1;
			}

			$product_total = (float)$product['total'] + ((float)$product['tax'] * $quantity);
			$product_amount_minor = $this->moneyToMinor($product_total, $order_info);

			if ($product_amount_minor <= 0) {
				continue;
			}

			$items[] = $this->receiptItem($product['name'], $product_amount_minor, 'commodity', $quantity);
		}

		$totals = $this->model_checkout_order->getOrderTotals($order_info['order_id']);

		foreach ($totals as $total) {
			if (in_array($total['code'], array('sub_total', 'tax', 'total'), true) || (float)$total['value'] <= 0) {
				continue;
			}

			$total_amount_minor = $this->moneyToMinor($total['value'], $order_info);

			if ($total_amount_minor <= 0) {
				continue;
			}

			$items[] = $this->receiptItem($total['title'], $total_amount_minor, $this->receiptObjectForTotal($total['code']));
		}

		if (!$items) {
			$items[] = $this->receiptItem($fallback_name, $amount_minor, 'commodity');
		}

		if (count($items) > 100) {
			$items = $this->collapseReceiptItems($items);
		}

		$this->reconcileReceiptItems($items, $amount_minor);

		return $items;
	}

	private function receiptItem($name, $amount_minor, $payment_object, $quantity = 1) {
		$quantity = (float)$quantity;

		if ($quantity <= 0) {
			$quantity = 1;
		}

		return array(
			'name'           => $this->truncateReceiptName($name),
			'price_minor'    => max(1, (int)round($amount_minor / $quantity)),
			'quantity'       => $quantity,
			'amount_minor'   => $amount_minor,
			'payment_method' => 'full_payment',
			'payment_object' => $payment_object,
			'tax'            => 'none'
		);
	}

	private function moneyToMinor($value, $order_info) {
		$formatted = $this->currency->format($value, $order_info['currency_code'], $order_info['currency_value'], false);

		return (int)round((float)$formatted * 100);
	}

	private function receiptObjectForTotal($code) {
		if ($code == 'shipping') {
			return 'service';
		}

		return 'payment';
	}

	private function collapseReceiptItems($items) {
		$collapsed = array_slice($items, 0, 99);
		$other_amount_minor = 0;

		foreach (array_slice($items, 99) as $item) {
			$other_amount_minor += (int)$item['amount_minor'];
		}

		if ($other_amount_minor > 0) {
			$collapsed[] = $this->receiptItem('Other order items', $other_amount_minor, 'commodity');
		}

		return $collapsed;
	}

	private function reconcileReceiptItems(&$items, $amount_minor) {
		$current_amount_minor = $this->receiptItemsAmount($items);
		$difference = $amount_minor - $current_amount_minor;

		if ($difference > 0) {
			$last_index = count($items) - 1;
			$items[$last_index]['amount_minor'] += $difference;
			$items[$last_index]['price_minor'] = $this->receiptItemPrice($items[$last_index]);

			return;
		}

		if ($difference < 0) {
			$remaining_discount_minor = abs($difference);

			for ($index = count($items) - 1; $index >= 0 && $remaining_discount_minor > 0; $index--) {
				$discount_minor = min($remaining_discount_minor, $items[$index]['amount_minor'] - 1);

				if ($discount_minor <= 0) {
					continue;
				}

				$items[$index]['amount_minor'] -= $discount_minor;
				$items[$index]['price_minor'] = $this->receiptItemPrice($items[$index]);
				$remaining_discount_minor -= $discount_minor;
			}
		}

		if ($this->receiptItemsAmount($items) !== $amount_minor) {
			$items = array($this->receiptItem($this->config->get('config_name') . ' order', $amount_minor, 'commodity'));
		}
	}

	private function receiptItemsAmount($items) {
		$amount_minor = 0;

		foreach ($items as $item) {
			$amount_minor += (int)$item['amount_minor'];
		}

		return $amount_minor;
	}

	private function receiptItemPrice($item) {
		$quantity = isset($item['quantity']) ? (float)$item['quantity'] : 1;

		if ($quantity <= 0) {
			$quantity = 1;
		}

		return max(1, (int)round($item['amount_minor'] / $quantity));
	}

	private function truncateReceiptName($value) {
		if (function_exists('mb_substr')) {
			return mb_substr($value, 0, 128, 'UTF-8');
		}

		if (preg_match_all('/./us', $value, $matches)) {
			return implode('', array_slice($matches[0], 0, 128));
		}

		return substr($value, 0, 128);
	}

	private function isValidEmail($value) {
		return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
	}

	private function createPayment($body) {
		$api_url = rtrim($this->config->get('payment_payment_service_api_url'), '/');

		if (substr($api_url, -7) !== '/api/v1') {
			$api_url .= '/api/v1';
		}

		$api_url .= '/payments';
		$raw_body = json_encode($body);
		$timestamp = (string)time();
		$signature = hash_hmac('sha256', $timestamp . '.' . $raw_body, $this->config->get('payment_payment_service_shared_secret'));

		$ch = curl_init($api_url);

		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $raw_body);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'X-API-Key: ' . $this->config->get('payment_payment_service_api_key'),
			'X-Timestamp: ' . $timestamp,
			'X-Signature: ' . $signature,
			'Idempotency-Key: order-' . $body['order_id']
		));

		$response = curl_exec($ch);

		if ($response === false) {
			$error = curl_error($ch);
			curl_close($ch);

			return array('error' => $error);
		}

		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$response_body = substr($response, $header_size);

		curl_close($ch);

		$data = json_decode($response_body, true);

		if ($status < 200 || $status >= 300) {
			$message = is_array($data) && isset($data['message']) ? $data['message'] : $response_body;

			return array('error' => 'Payment service returned HTTP ' . $status . ': ' . $message);
		}

		if (!is_array($data)) {
			return array('error' => 'Payment service returned invalid JSON');
		}

		return $data;
	}

	private function verifySignature($timestamp, $signature, $raw_body) {
		if ($timestamp === '' || $signature === '') {
			return false;
		}

		if (!ctype_digit((string)$timestamp)) {
			return false;
		}

		$skew = (int)$this->config->get('payment_payment_service_timestamp_skew');

		if ($skew <= 0) {
			$skew = 300;
		}

		if (abs(time() - (int)$timestamp) > $skew) {
			return false;
		}

		$expected = hash_hmac('sha256', $timestamp . '.' . $raw_body, $this->config->get('payment_payment_service_shared_secret'));

		if (function_exists('hash_equals')) {
			return hash_equals($expected, $signature);
		}

		return $expected === $signature;
	}

	private function matchesOrderAmount($payload, $order_info) {
		$total = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
		$amount_minor = (int)round((float)$total * 100);

		return (int)$payload['amount_minor'] === $amount_minor && $payload['currency'] === $order_info['currency_code'];
	}

	private function eventExists($event_id) {
		$query = $this->db->query("SELECT payment_service_event_id FROM `" . DB_PREFIX . "payment_service_event` WHERE event_id = '" . $this->db->escape($event_id) . "' LIMIT 1");

		return $query->num_rows > 0;
	}

	private function saveEvent($payload, $raw_body, $order_id) {
		$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "payment_service_event` SET event_id = '" . $this->db->escape($payload['event_id']) . "', order_id = '" . (int)$order_id . "', payment_id = '" . $this->db->escape($payload['payment_id']) . "', event_type = '" . $this->db->escape($payload['event_type']) . "', status = '" . $this->db->escape($payload['status']) . "', payload = '" . $this->db->escape($raw_body) . "', date_added = NOW()");

		return $this->db->countAffected() > 0;
	}

	private function mapStatus($status) {
		$map = array(
			'pending'   => 'payment_payment_service_pending_status_id',
			'succeeded' => 'payment_payment_service_success_status_id',
			'failed'    => 'payment_payment_service_failed_status_id',
			'canceled'  => 'payment_payment_service_canceled_status_id',
			'refunded'  => 'payment_payment_service_refunded_status_id'
		);

		if (!isset($map[$status])) {
			return 0;
		}

		return (int)$this->config->get($map[$status]);
	}

	private function jsonResponse($data, $status = 200) {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' ' . (int)$status);
		$this->response->setOutput(json_encode($data));
	}
}
