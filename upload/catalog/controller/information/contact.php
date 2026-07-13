<?php
class ControllerInformationContact extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('information/contact');
		$this->load->library('seo');

		$this->document->setTitle($this->seo->title('', $this->language->get('heading_title'), 'contact'));
		$this->document->setDescription($this->seo->description('', '', $this->language->get('heading_title'), 'contact'));
		$this->document->addLink($this->url->link('information/contact'), 'canonical');

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$message = "Новое сообщение с сайта Charm by Sylora\n\n";
			$message .= "Имя: " . $this->request->post['name'] . "\n";
			$message .= "Email: " . $this->request->post['email'] . "\n";
			$message .= "Телефон: " . (isset($this->request->post['telephone']) ? $this->request->post['telephone'] : '') . "\n\n";
			$message .= "Сообщение:\n" . $this->request->post['enquiry'];

			$mail = new Mail($this->config->get('config_mail_engine'));
			$mail->parameter = $this->config->get('config_mail_parameter');
			$mail->smtp_hostname = $this->config->get('config_mail_smtp_hostname');
			$mail->smtp_username = $this->config->get('config_mail_smtp_username');
			$mail->smtp_password = html_entity_decode($this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
			$mail->smtp_port = $this->config->get('config_mail_smtp_port');
			$mail->smtp_timeout = $this->config->get('config_mail_smtp_timeout');

			$mail->setTo($this->config->get('config_email'));
			$mail->setFrom($this->config->get('config_email'));
			if (!empty($this->request->post['email'])) {
				$mail->setReplyTo($this->request->post['email']);
			}
			$mail->setSender(html_entity_decode($this->request->post['name'], ENT_QUOTES, 'UTF-8'));
			$mail->setSubject(html_entity_decode(sprintf($this->language->get('email_subject'), $this->request->post['name']), ENT_QUOTES, 'UTF-8'));
			$mail->setText($message);
			$mail->send();

			$this->response->redirect($this->url->link('information/contact/success'));
		}




		if (isset($this->error['name'])) {
			$data['error_name'] = $this->error['name'];
		} else {
			$data['error_name'] = '';
		}

		if (isset($this->error['email'])) {
			$data['error_email'] = $this->error['email'];
		} else {
			$data['error_email'] = '';
		}

		$data['error_email_text'] = $this->language->get('error_email');

		if (isset($this->error['enquiry'])) {
			$data['error_enquiry'] = $this->error['enquiry'];
		} else {
			$data['error_enquiry'] = '';
		}

		if (isset($this->error['privacy'])) {
			$data['error_privacy'] = $this->error['privacy'];
		} else {
			$data['error_privacy'] = '';
		}

		if (isset($this->error['spam'])) {
			$data['error_spam'] = $this->error['spam'];
		} else {
			$data['error_spam'] = '';
		}

		$data['button_submit'] = $this->language->get('button_submit');
		$data['text_intro'] = $this->language->get('text_intro');
		$data['text_region'] = $this->language->get('text_region');
		$data['text_delivery'] = $this->language->get('text_delivery');
		$data['text_privacy'] = $this->language->get('text_privacy');
		$data['text_email'] = $this->language->get('text_email');
		$data['text_messengers'] = $this->language->get('text_messengers');
		$data['text_socials'] = $this->language->get('text_socials');
		$data['text_legal'] = $this->language->get('text_legal');
		$data['text_response'] = $this->language->get('text_response');
		$data['entry_telephone'] = $this->language->get('entry_telephone');
		$data['delivery_href'] = $this->url->link('information/information', 'information_id=6');
		$data['privacy_href'] = $this->url->link('information/information', 'information_id=3');

		$data['action'] = $this->url->link('information/contact', '', true);

		$this->load->model('tool/image');

		if ($this->config->get('config_image')) {
			$data['image'] = $this->model_tool_image->resizeWithSources($this->config->get('config_image'), (int)$this->config->get('theme_' . $this->config->get('config_theme') . '_image_location_width'), (int)$this->config->get('theme_' . $this->config->get('config_theme') . '_image_location_height'));
		} else {
			$data['image'] = array();
		}

		$data['store'] = $this->config->get('config_name');
		$data['address'] = nl2br($this->config->get('config_address'));
		$data['geocode'] = $this->config->get('config_geocode');
		$data['geocode_hl'] = $this->config->get('config_language');
		$data['telephone'] = $this->config->get('config_telephone');
		$data['telephone_href'] = $this->normalizePhoneHref($data['telephone']);
		$data['store_email'] = $this->config->get('config_email');
		$data['fax'] = $this->config->get('config_fax');
		$data['open'] = nl2br($this->config->get('config_open'));
		$data['comment'] = $this->config->get('config_comment');
		$data['region'] = $this->getConfigValue('config_sylora_region');
		$data['legal_info'] = nl2br($this->getConfigValue('config_sylora_legal_info'));
		$data['response_time'] = $this->getConfigValue('config_sylora_response_time');
		$data['messenger_links'] = $this->getContactLinks(array(
			array('label' => 'Telegram', 'key' => 'config_sylora_telegram'),
			array('label' => 'WhatsApp', 'key' => 'config_sylora_whatsapp'),
			array('label' => 'VK', 'key' => 'config_sylora_vk')
		));
		$data['social_links'] = $this->getContactLinks(array(
			array('label' => 'Instagram', 'key' => 'config_sylora_instagram'),
			array('label' => 'Pinterest', 'key' => 'config_sylora_pinterest'),
			array('label' => 'YouTube', 'key' => 'config_sylora_youtube')
		));
		$data['contact_schema'] = $this->getContactSchema($data);

		$data['locations'] = array();

		$this->load->model('localisation/location');

		foreach((array)$this->config->get('config_location') as $location_id) {
			$location_info = $this->model_localisation_location->getLocation($location_id);

			if ($location_info) {
				if ($location_info['image']) {
					$image = $this->model_tool_image->resize($location_info['image'], $this->config->get('theme_' . $this->config->get('config_theme') . '_image_location_width'), $this->config->get('theme_' . $this->config->get('config_theme') . '_image_location_height'));
				} else {
					$image = false;
				}

				$data['locations'][] = array(
					'location_id' => $location_info['location_id'],
					'name'        => $location_info['name'],
					'address'     => nl2br($location_info['address']),
					'geocode'     => $location_info['geocode'],
					'telephone'   => $location_info['telephone'],
					'fax'         => $location_info['fax'],
					'image'       => $image,
					'open'        => nl2br($location_info['open']),
					'comment'     => $location_info['comment']
				);
			}
		}

		if (isset($this->request->post['name'])) {
			$data['name'] = $this->request->post['name'];
		} else {
			$data['name'] = $this->customer->getFirstName();
		}

		if (isset($this->request->post['email'])) {
			$data['email'] = $this->request->post['email'];
		} else {
			$data['email'] = $this->customer->getEmail();
		}

		if (isset($this->request->post['telephone'])) {
			$data['telephone_value'] = $this->request->post['telephone'];
		} else {
			$data['telephone_value'] = '';
		}

		if (isset($this->request->post['enquiry'])) {
			$data['enquiry'] = $this->request->post['enquiry'];
		} else {
			$data['enquiry'] = '';
		}

		$data['privacy_agree'] = !empty($this->request->post['privacy_agree']);
		$data['form_started_at'] = time();

		// Captcha
		if ($this->config->get('captcha_' . $this->config->get('config_captcha') . '_status') && in_array('contact', (array)$this->config->get('config_captcha_page'))) {
			$data['captcha'] = $this->load->controller('extension/captcha/' . $this->config->get('config_captcha'), $this->error);
		} else {
			$data['captcha'] = '';
		}

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('information/contact', $data));
	}

	protected function validate() {
		if (!empty($this->request->post['company'])) {
			$this->error['spam'] = $this->language->get('error_spam');
		}

		if (!empty($this->request->post['form_started_at'])) {
			$form_started_at = (int)$this->request->post['form_started_at'];

			if ($form_started_at > time() || time() - $form_started_at < 2) {
				$this->error['spam'] = $this->language->get('error_spam');
			}
		}

		if (!empty($this->request->post['name'])) {
			if ((utf8_strlen($this->request->post['name']) < 3) || (utf8_strlen($this->request->post['name']) > 32)) {
				$this->error['name'] = $this->language->get('error_name');
			}
		} else {
			$this->error['name'] = $this->language->get('error_name');
		}

		if (!empty($this->request->post['email']) || !empty($this->request->post['telephone'])) {
			if (!empty($this->request->post['email']) && !filter_var($this->request->post['email'], FILTER_VALIDATE_EMAIL)) {
				$this->error['email'] = $this->language->get('error_email');
			}

			if (empty($this->request->post['email']) && utf8_strlen(preg_replace('/[^0-9+]/', '', $this->request->post['telephone'])) < 7) {
				$this->error['email'] = $this->language->get('error_email');
			}
		} else {
			$this->error['email'] = $this->language->get('error_email');
		}

		if (!empty($this->request->post['enquiry'])) {
			if ((utf8_strlen($this->request->post['enquiry']) < 10) || (utf8_strlen($this->request->post['enquiry']) > 3000)) {
				$this->error['enquiry'] = $this->language->get('error_enquiry');
			}
		} else {
			$this->error['enquiry'] = $this->language->get('error_enquiry');
		}

		if (empty($this->request->post['privacy_agree'])) {
			$this->error['privacy'] = $this->language->get('error_privacy');
		}

		// Captcha
		if ($this->config->get('captcha_' . $this->config->get('config_captcha') . '_status') && in_array('contact', (array)$this->config->get('config_captcha_page'))) {
			$captcha = $this->load->controller('extension/captcha/' . $this->config->get('config_captcha') . '/validate');

			if ($captcha) {
				$this->error['captcha'] = $captcha;
			}
		}

		return !$this->error;
	}

	private function getConfigValue($key) {
		$value = $this->config->get($key);

		if (is_string($value)) {
			return trim($value);
		}

		return '';
	}

	private function normalizePhoneHref($phone) {
		$phone = preg_replace('/[^0-9+]/', '', (string)$phone);

		return $phone;
	}

	private function getContactLinks(array $items) {
		$links = array();

		foreach ($items as $item) {
			$url = $this->getConfigValue($item['key']);

			if ($url) {
				$links[] = array(
					'label' => $item['label'],
					'href'  => $url
				);
			}
		}

		return $links;
	}

	private function getContactSchema(array $data) {
		$is_https = !empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] != 'off';
		$server = $is_https ? $this->config->get('config_ssl') : $this->config->get('config_url');
		$url = $this->url->link('information/contact');
		$same_as = array();

		foreach (array_merge($data['messenger_links'], $data['social_links']) as $link) {
			$same_as[] = $link['href'];
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'ContactPage',
			'url'      => $url,
			'name'     => $this->language->get('heading_title'),
			'about'    => array(
				'@type' => 'Organization',
				'@id'   => rtrim($server, '/') . '/#organization',
				'name'  => $data['store'] ? $data['store'] : 'Charm by Sylora'
			)
		);

		if ($data['store_email']) {
			$schema['about']['email'] = $data['store_email'];
		}

		if ($data['telephone']) {
			$schema['about']['telephone'] = $data['telephone'];
		}

		if ($same_as) {
			$schema['about']['sameAs'] = $same_as;
		}

		return json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	public function success() {
		$this->load->language('information/contact');

		$this->document->setTitle($this->language->get('heading_title'));




 		$data['text_message'] = $this->language->get('text_message'); 

		$data['continue'] = $this->url->link('common/home');

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('common/success', $data));
	}
}
