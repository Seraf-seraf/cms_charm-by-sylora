<?php
class ControllerExtensionShippingRussianPost extends Controller {
	private $error = array();

	public function install() {
		$this->load->model('setting/setting');
		$this->load->model('setting/event');

		if (!$this->model_setting_setting->getSetting('shipping_russian_post', 0)) {
			$this->model_setting_setting->editSetting('shipping_russian_post', array(
				'shipping_russian_post_widget_id' => '',
				'shipping_russian_post_origin_postcode' => '',
				'shipping_russian_post_api_url' => 'https://otpravka-api.pochta.ru',
				'shipping_russian_post_token' => '',
				'shipping_russian_post_login' => '',
				'shipping_russian_post_password' => '',
				'shipping_russian_post_default_weight' => '200',
				'shipping_russian_post_default_length' => '15',
				'shipping_russian_post_default_width' => '10',
				'shipping_russian_post_default_height' => '5',
				'shipping_russian_post_timeout' => '10',
				'shipping_russian_post_status' => '0',
				'shipping_russian_post_sort_order' => '2'
			), 0);
		}

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "russian_post_order` (`order_id` INT(11) NOT NULL, `office_id` VARCHAR(64) NOT NULL DEFAULT '', `postcode` VARCHAR(6) NOT NULL, `address` VARCHAR(255) NOT NULL, `cost` DECIMAL(15,4) NOT NULL, `min_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0, `max_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0, `date_added` DATETIME NOT NULL, PRIMARY KEY (`order_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$events = array(
			'russian_post_validate_office' => array('catalog/controller/checkout/shipping_method/save/before', 'extension/shipping/russian_post/validateOffice'),
			'russian_post_save_order' => array('catalog/controller/checkout/confirm/after', 'extension/shipping/russian_post/saveOrder'),
			'russian_post_header_script' => array('catalog/view/common/header/before', 'extension/shipping/russian_post/addHeaderScript'),
			'russian_post_admin_order' => array('admin/view/sale/order_info/before', 'extension/shipping/russian_post/orderInfo')
		);

		foreach ($events as $code => $event) {
			$this->model_setting_event->deleteEventByCode($code);
			$this->model_setting_event->addEvent($code, $event[0], $event[1]);
		}
	}

	public function uninstall() {
		$this->load->model('setting/event');

		foreach (array('russian_post_validate_office', 'russian_post_save_order', 'russian_post_header_script', 'russian_post_admin_order') as $code) {
			$this->model_setting_event->deleteEventByCode($code);
		}
	}

	public function index() {
		$this->load->language('extension/shipping/russian_post');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');

		if ($this->request->server['REQUEST_METHOD'] === 'POST' && $this->validate()) {
			$post = $this->request->post;

			foreach (array('token', 'login', 'password') as $secret) {
				$key = 'shipping_russian_post_' . $secret;

				if (empty($post[$key])) {
					$post[$key] = $this->config->get($key);
				}
			}
			$this->model_setting_setting->editSetting('shipping_russian_post', $post);
			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true));
		}

		$data = array();
		foreach ($this->language->all() as $key => $value) $data[$key] = $value;
		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['action'] = $this->url->link('extension/shipping/russian_post', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true);
		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)),
			array('text' => $this->language->get('text_extension'), 'href' => $data['cancel']),
			array('text' => $this->language->get('heading_title'), 'href' => $data['action'])
		);
		$defaults = array(
			'widget_id' => '', 'origin_postcode' => '', 'api_url' => 'https://otpravka-api.pochta.ru',
			'default_weight' => 200, 'default_length' => 15, 'default_width' => 10, 'default_height' => 5,
			'timeout' => 10, 'status' => 0, 'sort_order' => 2
		);
		foreach ($defaults as $name => $default) {
			$key = 'shipping_russian_post_' . $name;
			$data[$key] = isset($this->request->post[$key]) ? $this->request->post[$key] : ($this->config->get($key) !== null ? $this->config->get($key) : $default);
		}
		foreach (array('token', 'login', 'password') as $secret) {
			$key = 'shipping_russian_post_' . $secret;
			$data[$key . '_configured'] = (bool)$this->config->get($key);
			$data[$key] = '';
		}
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/shipping/russian_post', $data));
	}

	public function orderInfo(&$route, &$data) {
		$order_id = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "russian_post_order` WHERE order_id = '" . $order_id . "' LIMIT 1");
		if ($query->num_rows) {
			$data['tabs'][] = array('code' => 'russian_post', 'title' => 'Почта России', 'content' => $this->load->view('extension/shipping/russian_post_order', array('delivery' => $query->row)));
		}
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/shipping/russian_post')) $this->error['warning'] = $this->language->get('error_permission');
		if (!empty($this->request->post['shipping_russian_post_status'])) {
			foreach (array('widget_id', 'origin_postcode', 'token', 'login', 'password') as $field) {
				$key = 'shipping_russian_post_' . $field;
				if (empty($this->request->post[$key]) && !$this->config->get($key)) $this->error['warning'] = $this->language->get('error_required');
			}
		}
		return !$this->error;
	}
}
