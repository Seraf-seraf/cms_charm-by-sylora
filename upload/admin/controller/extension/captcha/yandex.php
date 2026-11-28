<?php
class ControllerExtensionCaptchaYandex extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('extension/captcha/yandex');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');

		if ($this->request->server['REQUEST_METHOD'] == 'POST') {
			if (empty($this->request->post['captcha_yandex_secret']) && $this->config->get('captcha_yandex_secret')) {
				$this->request->post['captcha_yandex_secret'] = $this->config->get('captcha_yandex_secret');
			}

			if ($this->validate()) {
				$this->model_setting_setting->editSetting('captcha_yandex', $this->request->post);
				$this->session->data['success'] = $this->language->get('text_success');
				$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=captcha', true));
			}
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['error_key'] = isset($this->error['key']) ? $this->error['key'] : '';
		$data['error_secret'] = isset($this->error['secret']) ? $this->error['secret'] : '';
		$data['breadcrumbs'] = array();
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=captcha', true)
		);
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/captcha/yandex', 'user_token=' . $this->session->data['user_token'], true)
		);
		$data['action'] = $this->url->link('extension/captcha/yandex', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=captcha', true);
		$data['captcha_yandex_key'] = isset($this->request->post['captcha_yandex_key']) ? $this->request->post['captcha_yandex_key'] : $this->config->get('captcha_yandex_key');
		$data['captcha_yandex_secret'] = '';
		$data['captcha_yandex_status'] = isset($this->request->post['captcha_yandex_status']) ? $this->request->post['captcha_yandex_status'] : $this->config->get('captcha_yandex_status');
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/captcha/yandex', $data));
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/captcha/yandex')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		if (empty($this->request->post['captcha_yandex_key'])) {
			$this->error['key'] = $this->language->get('error_key');
		}

		$secret = isset($this->request->post['captcha_yandex_secret']) ? $this->request->post['captcha_yandex_secret'] : '';

		if (!$this->isStoredSecret($secret)) {
			$this->error['secret'] = $this->language->get('error_secret');
		}

		return !$this->error;
	}

	private function isStoredSecret($value) {
		return is_string($value) && $value !== '' && preg_match('/^env:[A-Z][A-Z0-9_]{1,127}$/', trim($value)) !== 1;
	}
}
