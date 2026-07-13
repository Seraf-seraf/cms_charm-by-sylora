<?php

define('DB_PREFIX', '');
define('DIR_SYSTEM', __DIR__ . '/../upload/system/');

require_once __DIR__ . '/../upload/system/engine/registry.php';
require_once __DIR__ . '/../upload/system/engine/controller.php';
require_once __DIR__ . '/../upload/system/engine/model.php';
require_once __DIR__ . '/../upload/system/library/config.php';
require_once __DIR__ . '/../upload/catalog/controller/extension/payment/payment_service.php';
require_once __DIR__ . '/../upload/catalog/model/extension/payment/payment_service.php';

final class PaymentServiceCurrencyStub {
	public function format($number, $currency, $value = '', $format = true) {
		return (float)$number * (float)$value;
	}
}

final class PaymentServiceOrderModelStub {
	public function getOrderProducts($order_id) {
		return array(
			array('name' => 'Серьги', 'quantity' => 2, 'total' => 2000.00, 'tax' => 0.00)
		);
	}

	public function getOrderTotals($order_id) {
		return array(
			array('code' => 'sub_total', 'title' => 'Товары', 'value' => 2000.00),
			array('code' => 'shipping', 'title' => 'Доставка', 'value' => 300.00),
			array('code' => 'coupon', 'title' => 'Скидка', 'value' => -100.00),
			array('code' => 'total', 'title' => 'Итого', 'value' => 2200.00)
		);
	}
}

final class PaymentServiceDbStub {
	public function query($sql) {
		$result = new stdClass();
		$result->num_rows = 0;

		return $result;
	}
}

final class PaymentServiceLoaderStub {
	public function language($route) {
		return array();
	}
}

final class PaymentServiceLanguageStub {
	public function get($key) {
		return $key === 'text_title' ? 'Банковская карта' : $key;
	}
}

function invokePrivate($object, string $method, array $arguments = array()) {
	$reflection = new ReflectionMethod($object, $method);
	$reflection->setAccessible(true);

	return $reflection->invokeArgs($object, $arguments);
}

function assertSameValue($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

function assertTrueValue($actual, string $message): void {
	assertSameValue(true, $actual, $message);
}

function assertFalseValue($actual, string $message): void {
	assertSameValue(false, $actual, $message);
}

$registry = new Registry();
$config = new Config();
$config->set('config_name', 'Charm by Sylora');
$config->set('payment_payment_service_pending_status_id', 21);
$config->set('payment_payment_service_success_status_id', 22);
$config->set('payment_payment_service_shared_secret', 'env:PAYMENT_SERVICE_TEST_SHARED_SECRET');
$config->set('payment_payment_service_timestamp_skew', 300);
putenv('PAYMENT_SERVICE_TEST_SHARED_SECRET=' . str_repeat('s', 32));

$registry->set('config', $config);
$registry->set('currency', new PaymentServiceCurrencyStub());
$registry->set('model_checkout_order', new PaymentServiceOrderModelStub());

$controller = new ControllerExtensionPaymentPaymentService($registry);
$order = array(
	'order_id' => 42,
	'total' => 2200.00,
	'currency_code' => 'RUB',
	'currency_value' => 1,
	'email' => 'buyer@example.com',
	'telephone' => '+79990000000'
);

$body = invokePrivate($controller, 'buildCreatePaymentBody', array($order));
$receipt_total = 0;

foreach ($body['receipt']['items'] as $item) {
	$receipt_total += $item['amount_minor'];
}

assertSameValue(220000, $body['amount_minor'], 'Order amount uses minor units');
assertSameValue(220000, $receipt_total, 'Receipt items match order amount after discount');
assertSameValue('service', $body['receipt']['items'][1]['payment_object'], 'Shipping is marked as a service');
assertSameValue('buyer@example.com', $body['receipt']['email'], 'Receipt contains customer email');

$timestamp = (string)time();
$raw_callback = '{"event_id":"evt-1"}';
$signature = hash_hmac('sha256', $timestamp . '.' . $raw_callback, str_repeat('s', 32));

assertTrueValue(invokePrivate($controller, 'verifySignature', array($timestamp, $signature, $raw_callback)), 'Valid callback signature is accepted');
assertFalseValue(invokePrivate($controller, 'verifySignature', array((string)(time() - 301), $signature, $raw_callback)), 'Expired callback signature is rejected');
assertFalseValue(invokePrivate($controller, 'verifySignature', array($timestamp, 'invalid', $raw_callback)), 'Malformed callback signature is rejected');

$callback = array(
	'event_id' => '550e8400-e29b-41d4-a716-446655440001',
	'event_type' => 'payment.confirmed',
	'payment_id' => '550e8400-e29b-41d4-a716-446655440000',
	'order_id' => '42',
	'status' => 'succeeded',
	'amount_minor' => 220000,
	'currency' => 'RUB'
);

assertSameValue(null, invokePrivate($controller, 'validateCallbackPayload', array($callback)), 'Valid callback payload is accepted');
$callback['amount_minor'] = '220000';
assertSameValue('invalid_field', invokePrivate($controller, 'validateCallbackPayload', array($callback))['code'], 'String callback amount is rejected');

$response = array(
	'payment_id' => '550e8400-e29b-41d4-a716-446655440000',
	'order_id' => '42',
	'amount_minor' => 220000,
	'currency' => 'RUB',
	'status' => 'pending',
	'payment_url' => 'https://securepay.tinkoff.ru/payment/42'
);

assertTrueValue(invokePrivate($controller, 'isValidPaymentResponse', array($response, $order)), 'Valid payment response is accepted');
$response['payment_url'] = 'javascript:alert(1)';
assertFalseValue(invokePrivate($controller, 'isValidPaymentResponse', array($response, $order)), 'Unsafe payment redirect is rejected');
assertSameValue('invalid_configuration', invokePrivate($controller, 'createPayment', array($body))['error'], 'Missing API credentials fail before the network call');

$config->set('payment_payment_service_geo_zone_id', 0);
$config->set('payment_payment_service_total', 0);
$config->set('payment_payment_service_sort_order', 1);
$session = new stdClass();
$session->data = array('currency' => 'RUB');
$registry->set('session', $session);
$registry->set('db', new PaymentServiceDbStub());
$registry->set('load', new PaymentServiceLoaderStub());
$registry->set('language', new PaymentServiceLanguageStub());
$payment_model = new ModelExtensionPaymentPaymentService($registry);

assertSameValue('payment_service', $payment_model->getMethod(array('country_id' => 176, 'zone_id' => 0), 1000)['code'], 'RUB orders can use Payment Service');
$session->data['currency'] = 'USD';
assertSameValue(array(), $payment_model->getMethod(array('country_id' => 176, 'zone_id' => 0), 1000), 'Non-RUB orders cannot use Payment Service');
$session->data['currency'] = 'RUB';
assertSameValue(array(), $payment_model->getMethod(array('country_id' => 176, 'zone_id' => 0), 0), 'Free orders cannot use Payment Service');

echo 'Payment Service extension tests passed.' . PHP_EOL;
