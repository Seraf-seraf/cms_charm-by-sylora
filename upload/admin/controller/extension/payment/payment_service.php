<?php
class ControllerExtensionPaymentPaymentService extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('extension/payment/payment_service');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/setting');

		if ($this->request->server['REQUEST_METHOD'] == 'POST' && empty($this->request->post['payment_payment_service_shared_secret']) && $this->config->get('payment_payment_service_shared_secret')) {
			$this->request->post['payment_payment_service_shared_secret'] = $this->config->get('payment_payment_service_shared_secret');
		}

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('payment_payment_service', $this->request->post);

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['error_api_url'] = isset($this->error['api_url']) ? $this->error['api_url'] : '';
		$data['error_api_key'] = isset($this->error['api_key']) ? $this->error['api_key'] : '';
		$data['error_shared_secret'] = isset($this->error['shared_secret']) ? $this->error['shared_secret'] : '';

		$data['breadcrumbs'] = array();
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
		);
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/payment/payment_service', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['action'] = $this->url->link('extension/payment/payment_service', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);
		$data['callback_url'] = $this->url->link('extension/payment/payment_service/callback', '', true);
		$data['success_url'] = $this->url->link('checkout/success', '', true);
		$data['fail_url'] = $this->url->link('checkout/failure', '', true);

		$fields = array(
			'api_url',
			'api_key',
			'shared_secret',
			'total',
			'pending_status_id',
			'success_status_id',
			'failed_status_id',
			'canceled_status_id',
			'refunded_status_id',
			'geo_zone_id',
			'status',
			'sort_order',
			'timestamp_skew'
		);

		foreach ($fields as $field) {
			$key = 'payment_payment_service_' . $field;

			if (isset($this->request->post[$key])) {
				$data[$key] = $this->request->post[$key];
			} else {
				$data[$key] = $this->config->get($key);
			}
		}

		// Never render the stored callback signing secret back into the admin page.
		$data['payment_payment_service_shared_secret'] = '';

		if ($data['payment_payment_service_api_url'] === null || $data['payment_payment_service_api_url'] === '') {
			$data['payment_payment_service_api_url'] = 'https://pay.charm-by-sylora.ru';
		}

		if ($data['payment_payment_service_timestamp_skew'] === null || $data['payment_payment_service_timestamp_skew'] === '') {
			$data['payment_payment_service_timestamp_skew'] = 300;
		}

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/payment/payment_service', $data));
	}

	public function install() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "payment_service_event` (
			`payment_service_event_id` INT(11) NOT NULL AUTO_INCREMENT,
			`event_id` VARCHAR(64) NOT NULL,
			`order_id` INT(11) NOT NULL,
			`payment_id` VARCHAR(64) NOT NULL,
			`event_type` VARCHAR(64) NOT NULL,
			`status` VARCHAR(32) NOT NULL,
			`payload` MEDIUMTEXT NOT NULL,
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`payment_service_event_id`),
			UNIQUE KEY `event_id` (`event_id`),
			KEY `order_id` (`order_id`),
			KEY `payment_id` (`payment_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("ALTER TABLE `" . DB_PREFIX . "payment_service_event` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
		$this->db->query("ALTER TABLE `" . DB_PREFIX . "payment_service_event` MODIFY `payload` MEDIUMTEXT NOT NULL");
		$payment_id_index = $this->db->query("SHOW INDEX FROM `" . DB_PREFIX . "payment_service_event` WHERE Key_name = 'payment_id'");

		if (!$payment_id_index->num_rows) {
			$this->db->query("ALTER TABLE `" . DB_PREFIX . "payment_service_event` ADD KEY `payment_id` (`payment_id`)");
		}

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "payment_service_payment` (
			`payment_service_payment_id` INT(11) NOT NULL AUTO_INCREMENT,
			`order_id` INT(11) NOT NULL,
			`payment_id` VARCHAR(64) NOT NULL,
			`status` VARCHAR(32) NOT NULL,
			`amount_minor` BIGINT NOT NULL,
			`currency` CHAR(3) NOT NULL,
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`payment_service_payment_id`),
			UNIQUE KEY `order_id` (`order_id`),
			UNIQUE KEY `payment_id` (`payment_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	}

	public function uninstall() {
		// Payment audit data must survive disabling or reinstalling the extension.
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/payment/payment_service')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		if (empty($this->request->post['payment_payment_service_api_url']) || !$this->isAllowedApiUrl($this->request->post['payment_payment_service_api_url'])) {
			$this->error['api_url'] = $this->language->get('error_api_url');
		}

		if (!isset($this->request->post['payment_payment_service_api_key']) || !is_string($this->request->post['payment_payment_service_api_key']) || trim($this->request->post['payment_payment_service_api_key']) === '') {
			$this->error['api_key'] = $this->language->get('error_api_key');
		}

		if (!isset($this->request->post['payment_payment_service_shared_secret']) || !is_string($this->request->post['payment_payment_service_shared_secret']) || strlen($this->request->post['payment_payment_service_shared_secret']) < 32) {
			$this->error['shared_secret'] = $this->language->get('error_shared_secret');
		}

		return !$this->error;
	}

	private function isAllowedApiUrl($url) {
		if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
			return false;
		}

		$parts = parse_url($url);

		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
			return false;
		}

		if (strtolower($parts['scheme']) === 'https') {
			return true;
		}

		return strtolower($parts['scheme']) === 'http' && in_array(strtolower($parts['host']), array('localhost', '127.0.0.1', '::1'), true);
	}
}
