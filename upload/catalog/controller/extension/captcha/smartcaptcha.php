<?php
class ControllerExtensionCaptchaSmartcaptcha extends Controller {
	private const VALIDATE_URL = 'https://smartcaptcha.cloud.yandex.ru/validate';

	public function index($error = array()) {
		$this->load->language('extension/captcha/smartcaptcha');

		$data['error_captcha'] = isset($error['captcha']) ? $error['captcha'] : '';
		$data['site_key'] = $this->config->get('captcha_smartcaptcha_key');
		$data['container_id'] = 'smartcaptcha-' . substr(sha1(isset($this->request->get['route']) ? $this->request->get['route'] : 'form'), 0, 12);
		$data['route'] = isset($this->request->get['route']) ? $this->request->get['route'] : '';

		return $this->load->view('extension/captcha/smartcaptcha', $data);
	}

	public function validate() {
		$this->load->language('extension/captcha/smartcaptcha');

		if (empty($this->request->post['smart-token']) || !is_string($this->request->post['smart-token'])) {
			return $this->language->get('error_captcha');
		}

		require_once DIR_SYSTEM . 'library/sylora_secret.php';

		$secret = SyloraSecret::resolve($this->config->get('captcha_smartcaptcha_secret'));

		if ($secret === '') {
			return $this->language->get('error_captcha');
		}

		$response = $this->requestValidation($secret, $this->request->post['smart-token']);

		if (!is_array($response) || !isset($response['status']) || $response['status'] !== 'ok') {
			return $this->language->get('error_captcha');
		}

		return null;
	}

	private function requestValidation($secret, $token) {
		$post = array(
			'secret' => $secret,
			'token'  => $token,
			'ip'     => isset($this->request->server['REMOTE_ADDR']) ? $this->request->server['REMOTE_ADDR'] : ''
		);

		$curl = curl_init(self::VALIDATE_URL);

		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
		curl_setopt($curl, CURLOPT_TIMEOUT, 5);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));

		$result = curl_exec($curl);
		$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if (!is_string($result) || $status !== 200) {
			return array();
		}

		$data = json_decode($result, true);

		return is_array($data) ? $data : array();
	}
}
