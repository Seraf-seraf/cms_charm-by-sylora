<?php
class RussianPostDelivery {
	
	/** 
	 * @var Config 
	 */
	private $config;

	/** 
	 * @var Cart\Cart 
	 */
	private $cart;

	/** 
	 * @var Cart\Weight 
	 */
	private $weight;

	/** 
	 * @var Cart\Length 
	 */
	private $length;

	/** 
	 * @var Log 
	 */
	private $log;

	public function __construct($registry) {
		$this->config = $registry->get('config');
		$this->cart = $registry->get('cart');
		$this->weight = $registry->get('weight');
		$this->length = $registry->get('length');
		$this->log = $registry->get('log');
	}

	public function getPackage() {
		$weight = 0;
		$length = 0;
		$width = 0;
		$height = 0;
		$fallback_weight = max(1, (int)$this->config->get('shipping_russian_post_default_weight'));
		$fallback_length = max(1, (int)$this->config->get('shipping_russian_post_default_length'));
		$fallback_width = max(1, (int)$this->config->get('shipping_russian_post_default_width'));
		$fallback_height = max(1, (int)$this->config->get('shipping_russian_post_default_height'));

		foreach ($this->cart->getProducts() as $product) {
			if ((float)$product['weight'] > 0) {
				$weight += max(1, (int)round($this->weight->convert($product['weight'], $product['weight_class_id'], 2)));
			} else {
				$weight += $fallback_weight * (int)$product['quantity'];
			}
			$length = max($length, (float)$product['length'] > 0 ? (int)ceil($this->length->convert($product['length'], $product['length_class_id'], 1)) : $fallback_length);
			$width = max($width, (float)$product['width'] > 0 ? (int)ceil($this->length->convert($product['width'], $product['length_class_id'], 1)) : $fallback_width);
			$height += ((float)$product['height'] > 0 ? (int)ceil($this->length->convert($product['height'], $product['length_class_id'], 1)) : $fallback_height) * (int)$product['quantity'];
		}

		return array('weight' => max(1, $weight), 'length' => max(1, $length), 'width' => max(1, $width), 'height' => max(1, $height));
	}

	public function getWidgetDimensions($package) {
		return array(array(
			'length' => (int)$package['length'],
			'width' => (int)$package['width'],
			'height' => (int)$package['height']
		));
	}

	public function getFingerprint($address, $package) {
		$items = array();
		foreach ($this->cart->getProducts() as $product) {
			$items[] = array((int)$product['product_id'], (int)$product['quantity'], (float)$product['total']);
		}

		return hash('sha256', json_encode(array(
			'country_id' => (int)$address['country_id'],
			'zone_id' => (int)$address['zone_id'],
			'city' => (string)$address['city'],
			'postcode' => (string)$address['postcode'],
			'package' => $package,
			'items' => $items,
			'total' => (float)$this->cart->getSubTotal()
		)));
	}

	public function calculateTariff($index_to, $package) {
		$payload = array(
			'index-from' => (string)$this->config->get('shipping_russian_post_origin_postcode'),
			'index-to' => $index_to,
			'mail-category' => 'ORDINARY',
			'mail-type' => 'POSTAL_PARCEL',
			'mass' => $package['weight'],
			'dimension' => array('height' => $package['height'], 'length' => $package['length'], 'width' => $package['width']),
			'declared-value' => (int)round($this->cart->getSubTotal() * 100)
		);
		$url = rtrim((string)$this->config->get('shipping_russian_post_api_url'), '/') . '/1.0/tariff';
		$login = (string)$this->config->get('shipping_russian_post_login');
		$password = (string)$this->config->get('shipping_russian_post_password');
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_POST => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT => max(3, (int)$this->config->get('shipping_russian_post_timeout')),
			CURLOPT_HTTPHEADER => array(
				'Accept: application/json;charset=UTF-8',
				'Content-Type: application/json;charset=UTF-8',
				'Authorization: AccessToken ' . (string)$this->config->get('shipping_russian_post_token'),
				'X-User-Authorization: Basic ' . base64_encode($login . ':' . $password)
			),
			CURLOPT_POSTFIELDS => json_encode($payload)
		));
		$body = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);

		if ($body === false || $status < 200 || $status >= 300) {
			$this->log->write('Russian Post tariff error: HTTP ' . $status . ($error ? ', ' . $error : ''));
			throw new RuntimeException('Не удалось проверить тариф Почты России. Попробуйте позже.');
		}

		$data = json_decode($body, true);
		if (!is_array($data) || !isset($data['total-rate'])) {
			throw new RuntimeException('Почта России вернула некорректный тариф.');
		}

		return array(
			'cost' => round((int)$data['total-rate'] / 100, 2),
			'min_days' => isset($data['delivery-time']['min-days']) ? (int)$data['delivery-time']['min-days'] : 0,
			'max_days' => isset($data['delivery-time']['max-days']) ? (int)$data['delivery-time']['max-days'] : 0
		);
	}
}
