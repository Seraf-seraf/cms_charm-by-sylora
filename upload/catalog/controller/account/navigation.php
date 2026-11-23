<?php
class ControllerAccountNavigation extends Controller {
	public function index($settings = array()) {
		if ($this->config->get('config_theme') !== 'charm_by_sylora' || !$this->customer->isLogged()) {
			return '';
		}

		$this->load->language('account/navigation');

		$active_route = $settings['active_route'] ?? 'account/account';
		if (!is_string($active_route)) {
			$active_route = 'account/account';
		}
		$main_routes = array(
			'account/edit' => 'text_atelier_nav_personal',
			'account/address' => 'text_atelier_nav_address',
			'account/wishlist' => 'text_atelier_nav_wishlist',
			'account/order' => 'text_atelier_nav_orders',
			'account/return' => 'text_atelier_nav_returns',
			'account/password' => 'text_atelier_nav_password',
			'account/newsletter' => 'text_atelier_nav_newsletter'
		);
		$more_routes = array(
			'account/account' => 'text_atelier_nav_my_account',
			'account/download' => 'text_atelier_nav_download',
			'account/recurring' => 'text_atelier_nav_recurring',
			'account/reward' => 'text_atelier_nav_reward',
			'account/transaction' => 'text_atelier_nav_transaction',
			'account/logout' => 'text_atelier_nav_logout'
		);

		$links = array();
		foreach ($main_routes + $more_routes as $route => $label) {
			$links[$route] = array(
				'label' => $this->language->get($label),
				'href' => $this->url->link($route, '', true),
				'active' => $route === $active_route
			);
		}

		return $this->load->view('account/navigation', array(
			'text_my_account' => $links['account/account']['label'],
			'text_more' => $this->language->get('text_atelier_nav_more'),
			'current_label' => $links[$active_route]['label'] ?? $links['account/account']['label'],
			'links' => $links,
			'main_links' => array_intersect_key($links, $main_routes),
			'more_links' => array_intersect_key($links, $more_routes)
		));
	}
}
