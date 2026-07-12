<?php
class ControllerExtensionAnalyticsYandexMetrica extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('extension/analytics/yandex_metrica');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');

		$store_id = isset($this->request->get['store_id']) ? (int)$this->request->get['store_id'] : 0;

		if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate()) {
			$this->model_setting_setting->editSetting('analytics_yandex_metrica', $this->request->post, $store_id);
			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=analytics', true));
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['error_counter'] = isset($this->error['counter']) ? $this->error['counter'] : '';
		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=analytics', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/analytics/yandex_metrica', 'user_token=' . $this->session->data['user_token'] . '&store_id=' . $store_id, true))
		);
		$data['action'] = $this->url->link('extension/analytics/yandex_metrica', 'user_token=' . $this->session->data['user_token'] . '&store_id=' . $store_id, true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=analytics', true);

		foreach (array('counter', 'webvisor', 'ecommerce', 'status') as $field) {
			$key = 'analytics_yandex_metrica_' . $field;
			$data[$key] = isset($this->request->post[$key]) ? $this->request->post[$key] : $this->model_setting_setting->getSettingValue($key, $store_id);
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/analytics/yandex_metrica', $data));
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/analytics/yandex_metrica')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		if (!isset($this->request->post['analytics_yandex_metrica_counter']) || !preg_match('/^\d{5,12}$/', $this->request->post['analytics_yandex_metrica_counter'])) {
			$this->error['counter'] = $this->language->get('error_counter');
		}

		return !$this->error;
	}
}
