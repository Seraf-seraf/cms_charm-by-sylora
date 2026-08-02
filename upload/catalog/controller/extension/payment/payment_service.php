<?php
class ControllerExtensionPaymentPaymentService extends Controller {
	const MAX_CALLBACK_BODY_BYTES = 65536;
	const MAX_API_RESPONSE_BYTES = 1048576;

	public function index() {
		$this->load->language('extension/payment/payment_service');

		$data['button_confirm'] = $this->language->get('button_confirm');
		$data['text_loading'] = $this->language->get('text_loading');
		$data['error_payment'] = $this->language->get('error_payment');
		$data['confirm'] = $this->url->link('extension/payment/payment_service/confirm', '', true);

		return $this->load->view('extension/payment/payment_service', $data);
	}

	public function confirm() {
		$this->load->language('extension/payment/payment_service');

		$json = array();

		if (!isset($this->session->data['payment_method']['code']) || $this->session->data['payment_method']['code'] != 'payment_service') {
			$this->logPaymentError(0, 'payment_method_not_selected');
			$json['error'] = $this->language->get('error_payment');
		} elseif (empty($this->session->data['order_id'])) {
			$this->logPaymentError(0, 'order_not_initialized');
			$json['error'] = $this->language->get('error_payment');
		} else {
			$this->load->model('checkout/order');

			$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

			if (!$order_info) {
				$this->logPaymentError($this->session->data['order_id'], 'order_not_found');
				$json['error'] = $this->language->get('error_payment');
			} elseif ($order_info['currency_code'] !== 'RUB' || (float)$order_info['total'] <= 0) {
				$this->logPaymentError($order_info['order_id'], 'unsupported_order_amount_or_currency');
				$json['error'] = $this->language->get('error_payment');
			} elseif (!function_exists('curl_init')) {
				$this->logPaymentError($order_info['order_id'], 'curl_not_available');
				$json['error'] = $this->language->get('error_payment');
			} else {
				$body = $this->buildCreatePaymentBody($order_info);
				$response = $this->createPayment($body);

				try {
					if (!empty($response['error'])) {
						$this->logPaymentError($order_info['order_id'], $response['error']);
						$json['error'] = $this->language->get('error_payment');
					} elseif (!$this->isValidPaymentResponse($response, $order_info)) {
						$this->logPaymentError($order_info['order_id'], 'invalid_payment_response');
						$json['error'] = $this->language->get('error_payment');
					} elseif (!$this->rememberPayment($response, $order_info)) {
						$this->logPaymentError($order_info['order_id'], 'payment_mapping_conflict');
						$json['error'] = $this->language->get('error_payment');
					} else {
						$comment = sprintf(
							'Payment service payment_id: %s. Status: %s.',
							$response['payment_id'],
							$response['status']
						);

						$order_status_id = $this->mapStatus($response['status']);

						if ((int)$order_info['order_status_id'] !== $order_status_id) {
							$this->model_checkout_order->addOrderHistory(
								$order_info['order_id'],
								$order_status_id,
								$comment,
								false
							);
						}

						$json['redirect'] = $response['status'] === 'succeeded' ? $this->url->link('checkout/success', '', true) : $response['payment_url'];
					}
				} catch (Throwable $exception) {
					$this->logPaymentError($order_info['order_id'], 'confirmation_processing_failed');
					$json['error'] = $this->language->get('error_payment');
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

		if (!$this->matchesStoredPayment($payload, $order_id)) {
			return $this->jsonResponse(array('code' => 'payment_mismatch', 'message' => 'Callback payment does not match order'), 409);
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

		try {
			if ((int)$order_info['order_status_id'] != (int)$order_status_id) {
				$this->model_checkout_order->addOrderHistory($order_id, $order_status_id, $comment, true);
			}

			$this->updateStoredPaymentStatus($payload['payment_id'], $payload['status']);
		} catch (Throwable $exception) {
			$this->deleteEvent($payload['event_id']);
			$this->logPaymentError($order_id, 'callback_processing_failed');

			return $this->jsonResponse(array('code' => 'processing_failed', 'message' => 'Callback processing failed'), 500);
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
		$configured_api_url = $this->config->get('payment_payment_service_api_url');
		$api_url = is_string($configured_api_url) ? rtrim($configured_api_url, '/') : '';
		$api_key = $this->config->get('payment_payment_service_api_key');
		$shared_secret = $this->config->get('payment_payment_service_shared_secret');
		$api_parts = is_string($api_url) ? parse_url($api_url) : false;

		if (!$this->isAllowedRedirectUrl($api_url) || !is_array($api_parts) || isset($api_parts['query']) || isset($api_parts['fragment']) || !is_string($api_key) || trim($api_key) === '' || !is_string($shared_secret) || strlen($shared_secret) < 32) {
			return array('error' => 'invalid_configuration');
		}

		if (substr($api_url, -7) !== '/api/v1') {
			$api_url .= '/api/v1';
		}

		$api_url .= '/payments';
		$raw_body = json_encode($body);

		if ($raw_body === false) {
			return array('error' => 'request_json_encoding_failed');
		}

		$timestamp = (string)time();
		$signature = hash_hmac('sha256', $timestamp . '.' . $raw_body, $shared_secret);
		$response_body = '';
		$response_too_large = false;

		$ch = curl_init($api_url);

		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $raw_body);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($handle, $chunk) use (&$response_body, &$response_too_large) {
			if (strlen($response_body) + strlen($chunk) > self::MAX_API_RESPONSE_BYTES) {
				$response_too_large = true;
				return 0;
			}

			$response_body .= $chunk;

			return strlen($chunk);
		});
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'X-API-Key: ' . $api_key,
			'X-Timestamp: ' . $timestamp,
			'X-Signature: ' . $signature,
			'Idempotency-Key: order-' . $body['order_id']
		));

		$response = curl_exec($ch);

		if ($response === false) {
			$error_number = curl_errno($ch);
			curl_close($ch);

			if ($response_too_large) {
				return array('error' => 'response_too_large');
			}

			return array('error' => 'transport_error_' . $error_number);
		}

		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		$data = json_decode($response_body, true);

		if ($status < 200 || $status >= 300) {
			$service_code = is_array($data) && isset($data['code']) && $this->isSafeString($data['code'], 1, 64, '/^[A-Za-z0-9._:-]+$/') ? $data['code'] : 'unknown';

			return array('error' => 'service_http_' . $status . '_' . $service_code);
		}

		if (!is_array($data)) {
			return array('error' => 'invalid_response_json');
		}

		return $data;
	}

	private function isValidPaymentResponse($response, $order_info) {
		$required = array('payment_id', 'order_id', 'amount_minor', 'currency', 'status', 'payment_url');

		foreach ($required as $field) {
			if (!array_key_exists($field, $response)) {
				return false;
			}
		}

		if (!$this->isSafeString($response['payment_id'], 36, 36, '/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[1-8][a-fA-F0-9]{3}-[89abAB][a-fA-F0-9]{3}-[a-fA-F0-9]{12}$/')) {
			return false;
		}

		if ((string)$response['order_id'] !== (string)$order_info['order_id']) {
			return false;
		}

		if (!is_int($response['amount_minor']) || !$this->matchesOrderAmount($response, $order_info)) {
			return false;
		}

		if (!is_string($response['status']) || !in_array($response['status'], array('pending', 'succeeded'), true)) {
			return false;
		}

		if ($this->mapStatus($response['status']) <= 0) {
			return false;
		}

		return $this->isAllowedRedirectUrl($response['payment_url']);
	}

	private function isAllowedRedirectUrl($url) {
		if (!is_string($url) || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
			return false;
		}

		$parts = parse_url($url);

		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
			return false;
		}

		if (strtolower($parts['scheme']) === 'https') {
			return true;
		}

		return strtolower($parts['scheme']) === 'http' && in_array(strtolower($parts['host']), array('localhost', '127.0.0.1', '::1'), true);
	}

	private function rememberPayment($response, $order_info) {
		$order_id = (int)$order_info['order_id'];
		$payment_id = $this->db->escape($response['payment_id']);
		$status = $this->db->escape($response['status']);
		$currency = $this->db->escape($response['currency']);

		$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "payment_service_payment` SET order_id = '" . $order_id . "', payment_id = '" . $payment_id . "', status = '" . $status . "', amount_minor = '" . (int)$response['amount_minor'] . "', currency = '" . $currency . "', date_added = NOW(), date_modified = NOW()");

		$query = $this->db->query("SELECT payment_id, amount_minor, currency FROM `" . DB_PREFIX . "payment_service_payment` WHERE order_id = '" . $order_id . "' LIMIT 1");

		if (!$query->num_rows || $query->row['payment_id'] !== $response['payment_id'] || (int)$query->row['amount_minor'] !== (int)$response['amount_minor'] || $query->row['currency'] !== $response['currency']) {
			return false;
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "payment_service_payment` SET status = '" . $status . "', date_modified = NOW() WHERE order_id = '" . $order_id . "'");

		return true;
	}

	private function verifySignature($timestamp, $signature, $raw_body) {
		if ($timestamp === '' || $signature === '') {
			return false;
		}

		if (!ctype_digit((string)$timestamp) || !is_string($signature) || !preg_match('/^[a-fA-F0-9]{64}$/', $signature)) {
			return false;
		}

		$shared_secret = $this->config->get('payment_payment_service_shared_secret');

		if (!is_string($shared_secret) || strlen($shared_secret) < 32) {
			return false;
		}

		$skew = (int)$this->config->get('payment_payment_service_timestamp_skew');

		if ($skew <= 0) {
			$skew = 300;
		}

		if (abs(time() - (int)$timestamp) > $skew) {
			return false;
		}

		$expected = hash_hmac('sha256', $timestamp . '.' . $raw_body, $shared_secret);

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

	private function matchesStoredPayment($payload, $order_id) {
		$query = $this->db->query("SELECT payment_id, amount_minor, currency FROM `" . DB_PREFIX . "payment_service_payment` WHERE order_id = '" . (int)$order_id . "' LIMIT 1");

		return $query->num_rows
			&& $query->row['payment_id'] === $payload['payment_id']
			&& (int)$query->row['amount_minor'] === (int)$payload['amount_minor']
			&& $query->row['currency'] === $payload['currency'];
	}

	private function eventExists($event_id) {
		$query = $this->db->query("SELECT payment_service_event_id FROM `" . DB_PREFIX . "payment_service_event` WHERE event_id = '" . $this->db->escape($event_id) . "' LIMIT 1");

		return $query->num_rows > 0;
	}

	private function saveEvent($payload, $raw_body, $order_id) {
		$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "payment_service_event` SET event_id = '" . $this->db->escape($payload['event_id']) . "', order_id = '" . (int)$order_id . "', payment_id = '" . $this->db->escape($payload['payment_id']) . "', event_type = '" . $this->db->escape($payload['event_type']) . "', status = '" . $this->db->escape($payload['status']) . "', payload = '" . $this->db->escape($raw_body) . "', date_added = NOW()");

		return $this->db->countAffected() > 0;
	}

	private function deleteEvent($event_id) {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "payment_service_event` WHERE event_id = '" . $this->db->escape($event_id) . "'");
	}

	private function updateStoredPaymentStatus($payment_id, $status) {
		$this->db->query("UPDATE `" . DB_PREFIX . "payment_service_payment` SET status = '" . $this->db->escape($status) . "', date_modified = NOW() WHERE payment_id = '" . $this->db->escape($payment_id) . "'");

		if ($this->db->countAffected() < 1) {
			$query = $this->db->query("SELECT payment_service_payment_id FROM `" . DB_PREFIX . "payment_service_payment` WHERE payment_id = '" . $this->db->escape($payment_id) . "' LIMIT 1");

			if (!$query->num_rows) {
				throw new RuntimeException('Payment mapping not found');
			}
		}
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

	private function logPaymentError($order_id, $code) {
		$code = preg_replace('/[^A-Za-z0-9._:-]/', '_', (string)$code);
		$this->log->write('Payment Service order #' . (int)$order_id . ': ' . substr($code, 0, 160));
	}

	private function jsonResponse($data, $status = 200) {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' ' . (int)$status);
		$this->response->setOutput(json_encode($data));
	}
}
