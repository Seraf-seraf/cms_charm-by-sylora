<?php
class ControllerAccountSuccess extends Controller {
	public function index() {
		$this->load->language('account/success');

		$logged_in = $this->customer->isLogged();
		$data['registration_confirmed'] = $logged_in && $this->config->get('config_theme') === 'charm_by_sylora';
		$data['heading_title'] = $this->language->get($data['registration_confirmed'] ? 'heading_registration_complete' : 'heading_title');
		$this->document->setTitle($data['heading_title']);

		if ($logged_in) {
			$data['text_message'] = sprintf($this->language->get('text_message'), $this->url->link('information/contact'));
		} else {
			$data['text_message'] = sprintf($this->language->get('text_approval'), $this->config->get('config_name'), $this->url->link('information/contact'));
		}

		if ($this->cart->hasProducts()) {
			$data['continue'] = $this->url->link('checkout/cart');
		} else {
			$data['continue'] = $this->url->link('account/account', '', true);

			if ($data['registration_confirmed']) {
				$data['button_continue'] = $this->language->get('button_registration_account');
			}
		}

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('common/success', $data));
	}
}
