<?php
class ControllerExtensionCaptchaYandex extends Controller {
	private const VALIDATE_URL = 'https://smartcaptcha.cloud.yandex.ru/validate';

	public function index($error = array()) {
		$this->load->language('extension/captcha/yandex');
		$data['error_captcha'] = isset($error['captcha']) ? $error['captcha'] : '';
		$data['site_key'] = $this->config->get('captcha_yandex_key');
		$data['container_id'] = 'yandex-smartcaptcha-' . substr(sha1(isset($this->request->get['route']) ? $this->request->get['route'] : 'form'), 0, 12);

		return $this->load->view('extension/captcha/yandex', $data);
	}

	public function validate() {
		$this->load->language('extension/captcha/yandex');

		if (empty($this->request->post['smart-token']) || !is_string($this->request->post['smart-token'])) {
			return $this->language->get('error_captcha');
		}

		$secret = $this->config->get('captcha_yandex_secret');

		if (!is_string($secret) || $secret === '' || preg_match('/^env:[A-Z][A-Z0-9_]{1,127}$/', trim($secret)) === 1) {
			return $this->language->get('error_captcha');
		}

		$result = $this->requestValidation($secret, $this->request->post['smart-token']);

		if (!isset($result['status']) || $result['status'] !== 'ok') {
			return $this->language->get('error_captcha');
		}

		return null;
	}

	private function requestValidation($secret, $token) {
		$post = array(
			'secret' => $secret,
			'token' => $token,
			'ip' => isset($this->request->server['REMOTE_ADDR']) ? $this->request->server['REMOTE_ADDR'] : ''
		);
		$curl = curl_init(self::VALIDATE_URL);

		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 2);
		curl_setopt($curl, CURLOPT_TIMEOUT, 3);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
		$response = curl_exec($curl);
		$http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if (!is_string($response) || $http_code !== 200) {
			return array();
		}

		$result = json_decode($response, true);

		return is_array($result) ? $result : array();
	}
}
