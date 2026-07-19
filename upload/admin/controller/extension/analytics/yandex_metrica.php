<?php
class ControllerExtensionAnalyticsYandexMetrica extends Controller {
	private $error = array();

	public function install() {
		$this->load->model('setting/setting');

		if ($this->model_setting_setting->getSetting('analytics_yandex_metrica', 0)) {
			return;
		}

		$this->model_setting_setting->editSetting('analytics_yandex_metrica', array(
			'analytics_yandex_metrica_counter' => '',
			'analytics_yandex_metrica_webvisor' => '0',
			'analytics_yandex_metrica_ecommerce' => '1',
			'analytics_yandex_metrica_cookie_days' => '365',
			'analytics_yandex_metrica_privacy_information_id' => '0',
			'analytics_yandex_metrica_banner' => array(),
			'analytics_yandex_metrica_status' => '0'
		), 0);
	}

	public function index() {
		$this->load->language('extension/analytics/yandex_metrica');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');
		$this->load->model('localisation/language');
		$this->load->model('catalog/information');

		$store_id = isset($this->request->get['store_id']) ? (int)$this->request->get['store_id'] : 0;

		if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate()) {
			$this->model_setting_setting->editSetting('analytics_yandex_metrica', $this->request->post, $store_id);
			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=analytics', true));
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['error_counter'] = isset($this->error['counter']) ? $this->error['counter'] : '';
		$data['error_cookie_days'] = isset($this->error['cookie_days']) ? $this->error['cookie_days'] : '';
		$data['error_banner'] = isset($this->error['banner']) ? $this->error['banner'] : array();
		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=analytics', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/analytics/yandex_metrica', 'user_token=' . $this->session->data['user_token'] . '&store_id=' . $store_id, true))
		);
		$data['action'] = $this->url->link('extension/analytics/yandex_metrica', 'user_token=' . $this->session->data['user_token'] . '&store_id=' . $store_id, true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=analytics', true);

		foreach (array('counter', 'webvisor', 'ecommerce', 'cookie_days', 'privacy_information_id', 'status') as $field) {
			$key = 'analytics_yandex_metrica_' . $field;
			$data[$key] = isset($this->request->post[$key]) ? $this->request->post[$key] : $this->model_setting_setting->getSettingValue($key, $store_id);
		}

		if (!$data['analytics_yandex_metrica_cookie_days']) {
			$data['analytics_yandex_metrica_cookie_days'] = '365';
		}

		$data['languages'] = $this->model_localisation_language->getLanguages();
		$data['informations'] = $this->model_catalog_information->getInformations();
		$banner = isset($this->request->post['analytics_yandex_metrica_banner'])
			? $this->request->post['analytics_yandex_metrica_banner']
			: $this->model_setting_setting->getSettingValue('analytics_yandex_metrica_banner', $store_id);
		$data['analytics_yandex_metrica_banner'] = is_array($banner) ? $banner : array();
		$defaults = $this->getBannerDefaults();

		foreach ($data['languages'] as $language) {
			$language_id = (int)$language['language_id'];

			if (!isset($data['analytics_yandex_metrica_banner'][$language_id]) || !is_array($data['analytics_yandex_metrica_banner'][$language_id])) {
				$data['analytics_yandex_metrica_banner'][$language_id] = $defaults;
			} else {
				$data['analytics_yandex_metrica_banner'][$language_id] = array_merge($defaults, $data['analytics_yandex_metrica_banner'][$language_id]);
			}
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

		$status = isset($this->request->post['analytics_yandex_metrica_status']) && $this->request->post['analytics_yandex_metrica_status'];
		$counter = isset($this->request->post['analytics_yandex_metrica_counter']) ? $this->request->post['analytics_yandex_metrica_counter'] : '';

		if ($status && !preg_match('/^\d{5,12}$/', $counter)) {
			$this->error['counter'] = $this->language->get('error_counter');
		}

		$cookie_days = isset($this->request->post['analytics_yandex_metrica_cookie_days']) ? $this->request->post['analytics_yandex_metrica_cookie_days'] : '';

		if (!ctype_digit((string)$cookie_days) || (int)$cookie_days < 1 || (int)$cookie_days > 730) {
			$this->error['cookie_days'] = $this->language->get('error_cookie_days');
		}

		$banner = isset($this->request->post['analytics_yandex_metrica_banner']) && is_array($this->request->post['analytics_yandex_metrica_banner'])
			? $this->request->post['analytics_yandex_metrica_banner']
			: array();
		$this->load->model('localisation/language');

		foreach ($this->model_localisation_language->getLanguages() as $language) {
			$language_id = (int)$language['language_id'];
			$values = isset($banner[$language_id]) && is_array($banner[$language_id]) ? $banner[$language_id] : array();

			foreach (array('title', 'description', 'privacy', 'accept', 'reject', 'settings') as $field) {
				$value = isset($values[$field]) && is_string($values[$field]) ? trim($values[$field]) : '';

				if ($value === '') {
					$this->error['banner'][$language_id][$field] = $this->language->get('error_banner_text');
				}
			}
		}

		return !$this->error;
	}

	private function getBannerDefaults() {
		return array(
			'title' => 'Аналитические cookies',
			'description' => 'Мы используем Яндекс Метрику, чтобы понимать, как работает сайт. Подробнее — в',
			'privacy' => 'Политике конфиденциальности',
			'accept' => 'Разрешить аналитику',
			'reject' => 'Продолжить без аналитики',
			'settings' => 'Настройки аналитики'
		);
	}
}
