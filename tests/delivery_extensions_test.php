<?php
require_once __DIR__ . '/../upload/system/engine/registry.php';
require_once __DIR__ . '/../upload/system/library/config.php';
require_once __DIR__ . '/../upload/system/library/russian_post_delivery.php';
require_once __DIR__ . '/../upload/system/library/cdek_official/vendor/autoload.php';

final class DeliveryCartStub {
	public $total = 1500.0;
	public function getProducts() {
		return array(
			array('product_id'=>1,'name'=>'Кольцо','quantity'=>2,'price'=>500.0,'total'=>1000.0,'weight'=>0.4,'weight_class_id'=>1,'length'=>4,'width'=>3,'height'=>2,'length_class_id'=>1,'tax_class_id'=>0),
			array('product_id'=>2,'name'=>'Серьги','quantity'=>3,'price'=>166.6667,'total'=>500.0,'weight'=>0,'weight_class_id'=>1,'length'=>0,'width'=>0,'height'=>0,'length_class_id'=>1,'tax_class_id'=>0)
		);
	}
	public function getSubTotal() { return $this->total; }
}

final class DeliveryWeightStub {
	public function convert($value, $from, $to) { return $from === 1 && $to === 2 ? $value * 1000 : $value; }
}

final class DeliveryLengthStub {
	public function convert($value, $from, $to) { return $value; }
}

final class DeliveryLogStub { public function write($message) {} }

function deliveryAssert($expected, $actual, $message) {
	if ($expected !== $actual) throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

$registry = new Registry();
$config = new Config();
foreach (array(
	'shipping_russian_post_default_weight'=>200,
	'shipping_russian_post_default_length'=>15,
	'shipping_russian_post_default_width'=>10,
	'shipping_russian_post_default_height'=>5,
	'shipping_russian_post_origin_postcode'=>'644000',
	'shipping_russian_post_api_url'=>'http://127.0.0.1:18089',
	'shipping_russian_post_token'=>'test-token',
	'shipping_russian_post_login'=>'test-login',
	'shipping_russian_post_password'=>'test-password',
	'shipping_russian_post_timeout'=>3
) as $key=>$value) $config->set($key, $value);
$cart = new DeliveryCartStub();
$registry->set('config', $config);
$registry->set('cart', $cart);
$registry->set('weight', new DeliveryWeightStub());
$registry->set('length', new DeliveryLengthStub());
$registry->set('log', new DeliveryLogStub());

$delivery = new RussianPostDelivery($registry);
$package = $delivery->getPackage();
deliveryAssert(1000, $package['weight'], 'Weight includes OpenCart total weight once and fallback weight per item');
deliveryAssert(15, $package['length'], 'Fallback package length is applied');
deliveryAssert(19, $package['height'], 'Package height accounts for quantities');

$address = array('country_id'=>176,'zone_id'=>2769,'city'=>'Омск','postcode'=>'644000');
$fingerprint = $delivery->getFingerprint($address, $package);
$cart->total = 1600.0;
deliveryAssert(false, hash_equals($fingerprint, $delivery->getFingerprint($address, $package)), 'Cart changes invalidate delivery selection');
$cart->total = 1500.0;

$command = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:18089 ' . escapeshellarg(__DIR__ . '/fixtures/russian_post_api_router.php');
$process = proc_open($command, array(array('pipe','r'), array('file','/tmp/russian-post-api-test.log','a'), array('file','/tmp/russian-post-api-test.log','a')), $pipes);
if (!is_resource($process)) throw new RuntimeException('Unable to start Russian Post API stub');
usleep(300000);
try {
	$tariff = $delivery->calculateTariff('101000', $package);
	deliveryAssert(345.67, $tariff['cost'], 'Tariff converts kopecks to rubles');
	deliveryAssert(3, $tariff['min_days'], 'Minimum delivery time is preserved');
	deliveryAssert(5, $tariff['max_days'], 'Maximum delivery time is preserved');
} finally {
	proc_terminate($process);
	proc_close($process);
}

new CDEK\RegistrySingleton($registry);
CDEK\SettingsSingleton::getInstance(array(
	'cdek_official__dimensionsLength'=>15,
	'cdek_official__dimensionsWidth'=>10,
	'cdek_official__dimensionsHeight'=>5,
	'cdek_official__dimensionsWeight'=>200
));
$recommended = CDEK\Helpers\DeliveryCalculator::getRecommendedPackage(array(array('length'=>4,'width'=>3,'height'=>2,'weight'=>200,'quantity'=>2)));
deliveryAssert(400, $recommended['weight'], 'CDEK package multiplies unit weight by quantity once');
deliveryAssert(15, $recommended['height'], 'CDEK package keeps configured minimum dimensions');

echo "Delivery extension tests passed.\n";
