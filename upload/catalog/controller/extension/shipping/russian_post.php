<?php
class ControllerExtensionShippingRussianPost extends Controller {
	public function params() {
		$this->response->addHeader('Content-Type: application/json');
		if (empty($this->session->data['shipping_address'])) {
			$this->response->setOutput(json_encode(array('error' => 'Не указан адрес доставки.')));
			return;
		}

		require_once DIR_SYSTEM . 'library/russian_post_delivery.php';
		$delivery = new RussianPostDelivery($this->registry);
		$package = $delivery->getPackage();
		$lines = array();
		foreach ($this->cart->getProducts() as $product) {
			$lines[] = array('name' => $product['name'], 'quantity' => (int)$product['quantity'], 'value' => (int)round($product['price'] * 100));
		}

		$this->response->setOutput(json_encode(array(
			'id' => (int)$this->config->get('shipping_russian_post_widget_id'),
			'weight' => $package['weight'],
			'sumoc' => (int)round($this->cart->getSubTotal() * 100),
			'startZip' => (string)$this->config->get('shipping_russian_post_origin_postcode'),
			'dimensions' => array($package['length'], $package['width'], $package['height']),
			'order_lines' => $lines
		)));
	}

	public function select() {
		$this->response->addHeader('Content-Type: application/json');
		try {
			$data = json_decode(isset($this->request->post['selection']) ? htmlspecialchars_decode($this->request->post['selection'], ENT_COMPAT) : '', true);
			if (!is_array($data) || !isset($data['indexTo']) || !preg_match('/^\d{6}$/', (string)$data['indexTo']) || (isset($data['pvzType']) && $data['pvzType'] !== 'russian_post')) {
				throw new RuntimeException('Виджет вернул некорректное отделение.');
			}
			if (empty($this->session->data['shipping_address'])) {
				throw new RuntimeException('Не указан адрес доставки.');
			}

			require_once DIR_SYSTEM . 'library/russian_post_delivery.php';
			$delivery = new RussianPostDelivery($this->registry);
			$package = $delivery->getPackage();
			$tariff = $delivery->calculateTariff((string)$data['indexTo'], $package);
			$terms = '';
			if ($tariff['max_days']) {
				$terms = $tariff['min_days'] && $tariff['min_days'] !== $tariff['max_days'] ? $tariff['min_days'] . '-' . $tariff['max_days'] . ' дней' : 'до ' . $tariff['max_days'] . ' дней';
			}
			$this->session->data['russian_post_delivery'] = array(
				'verified' => true,
				'fingerprint' => $delivery->getFingerprint($this->session->data['shipping_address'], $package),
				'office_id' => isset($data['id']) ? (string)$data['id'] : '',
				'index' => (string)$data['indexTo'],
				'address' => trim((isset($data['cityTo']) ? $data['cityTo'] . ', ' : '') . (isset($data['addressTo']) ? $data['addressTo'] : '')),
				'cost' => $tariff['cost'],
				'min_days' => $tariff['min_days'],
				'max_days' => $tariff['max_days'],
				'terms' => $terms
			);
			unset($this->session->data['shipping_method'], $this->session->data['shipping_methods']);
			$this->response->setOutput(json_encode(array('success' => true)));
		} catch (Throwable $exception) {
			$this->response->setOutput(json_encode(array('error' => $exception->getMessage())));
		}
	}

	public function validateOffice() {
		if (!isset($this->request->post['shipping_method']) || $this->request->post['shipping_method'] !== 'russian_post.office') {
			return null;
		}
		if (!empty($this->session->data['russian_post_delivery']['verified']) && !empty($this->session->data['shipping_address'])) {
			require_once DIR_SYSTEM . 'library/russian_post_delivery.php';
			$delivery = new RussianPostDelivery($this->registry);
			$current = $delivery->getFingerprint($this->session->data['shipping_address'], $delivery->getPackage());
			if (isset($this->session->data['russian_post_delivery']['fingerprint']) && hash_equals($this->session->data['russian_post_delivery']['fingerprint'], $current)) {
				return null;
			}
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(array('error' => array('warning' => 'Выберите отделение Почты России и дождитесь расчета стоимости.'))));
		return false;
	}

	public function saveOrder() {
		if (empty($this->session->data['order_id']) || empty($this->session->data['russian_post_delivery']['verified']) || empty($this->session->data['shipping_method']['code']) || $this->session->data['shipping_method']['code'] !== 'russian_post.office') {
			return;
		}
		$data = $this->session->data['russian_post_delivery'];
		$this->db->query("INSERT INTO `" . DB_PREFIX . "russian_post_order` SET order_id = '" . (int)$this->session->data['order_id'] . "', office_id = '" . $this->db->escape($data['office_id']) . "', postcode = '" . $this->db->escape($data['index']) . "', address = '" . $this->db->escape($data['address']) . "', cost = '" . (float)$data['cost'] . "', min_days = '" . (int)$data['min_days'] . "', max_days = '" . (int)$data['max_days'] . "', date_added = NOW() ON DUPLICATE KEY UPDATE office_id = VALUES(office_id), postcode = VALUES(postcode), address = VALUES(address), cost = VALUES(cost), min_days = VALUES(min_days), max_days = VALUES(max_days)");
	}

	public function addHeaderScript(&$route, &$data) {
		if ($this->config->get('shipping_russian_post_status')) {
			$data['scripts'][] = 'catalog/view/javascript/shipping/russian_post.js';
		}
	}
}
