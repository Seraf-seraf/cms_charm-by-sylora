<?php
class ControllerExtensionCaptchaSmartcaptcha extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('extension/captcha/smartcaptcha');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');

		if ($this->request->server['REQUEST_METHOD'] == 'POST') {
			if (empty($this->request->post['captcha_smartcaptcha_secret']) && $this->config->get('captcha_smartcaptcha_secret')) {
				$this->request->post['captcha_smartcaptcha_secret'] = $this->config->get('captcha_smartcaptcha_secret');
			}

			if ($this->validate()) {
				$this->model_setting_setting->editSetting('captcha_smartcaptcha', $this->request->post);
				$this->session->data['success'] = $this->language->get('text_success');
				$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=captcha', true));
			}
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['error_key'] = isset($this->error['key']) ? $this->error['key'] : '';
		$data['error_secret'] = isset($this->error['secret']) ? $this->error['secret'] : '';

		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=captcha', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/captcha/smartcaptcha', 'user_token=' . $this->session->data['user_token'], true))
		);

		$data['action'] = $this->url->link('extension/captcha/smartcaptcha', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=captcha', true);

		foreach (array('key', 'secret', 'status') as $field) {
			$key = 'captcha_smartcaptcha_' . $field;
			$data[$key] = isset($this->request->post[$key]) ? $this->request->post[$key] : $this->config->get($key);
		}

		$data['captcha_smartcaptcha_secret_configured'] = (bool)$this->config->get('captcha_smartcaptcha_secret');
		$data['captcha_smartcaptcha_secret'] = '';

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/captcha/smartcaptcha', $data));
	}

	protected function validate() {
		require_once DIR_SYSTEM . 'library/sylora_secret.php';

		if (!$this->user->hasPermission('modify', 'extension/captcha/smartcaptcha')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		if (empty($this->request->post['captcha_smartcaptcha_key'])) {
			$this->error['key'] = $this->language->get('error_key');
		}

		if (empty($this->request->post['captcha_smartcaptcha_secret']) || !SyloraSecret::isReference($this->request->post['captcha_smartcaptcha_secret'])) {
			$this->error['secret'] = $this->language->get('error_secret');
		}

		return !$this->error;
	}
}
