<?php
class ControllerAccountNavigation extends Controller {
	public function index($settings = array()) {
		if ($this->config->get('config_theme') !== 'charm_by_sylora' || !$this->customer->isLogged()) {
			return '';
		}

		$this->load->language('account/navigation');

		$active_route = isset($settings['active_route']) ? (string)$settings['active_route'] : '';
		$routes = array(
			'account/edit' => $this->language->get('text_atelier_nav_personal'),
			'account/address' => $this->language->get('text_atelier_nav_address'),
			'account/wishlist' => $this->language->get('text_atelier_nav_wishlist'),
			'account/order' => $this->language->get('text_atelier_nav_orders'),
			'account/return' => $this->language->get('text_atelier_nav_returns'),
			'account/password' => $this->language->get('text_atelier_nav_password'),
			'account/newsletter' => $this->language->get('text_atelier_nav_newsletter')
		);

		$links = array();

		foreach ($routes as $route => $label) {
			$links[] = array(
				'label' => $label,
				'href' => $this->url->link($route, '', true),
				'active' => $route === $active_route
			);
		}

		return $this->load->view('account/navigation', array(
			'text_my_account' => $this->language->get('text_atelier_nav_my_account'),
			'links' => $links
		));
	}
}
