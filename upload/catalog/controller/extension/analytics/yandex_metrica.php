<?php
class ControllerExtensionAnalyticsYandexMetrica extends Controller {
	public function index() {
		$counter = (string)$this->config->get('analytics_yandex_metrica_counter');

		if (!preg_match('/^\d{5,12}$/', $counter)) {
			return '';
		}

		$language_id = (int)$this->config->get('config_language_id');
		$banner_settings = $this->config->get('analytics_yandex_metrica_banner');
		$banner = is_array($banner_settings) && isset($banner_settings[$language_id]) && is_array($banner_settings[$language_id])
			? $banner_settings[$language_id]
			: array();
		$banner = array_merge($this->getBannerDefaults(), $banner);
		$privacy_information_id = (int)$this->config->get('analytics_yandex_metrica_privacy_information_id');
		$privacy_url = '';

		if ($privacy_information_id > 0) {
			$privacy_url = $this->url->link('information/information', 'information_id=' . $privacy_information_id);
		}

		$cookie_days = (int)$this->config->get('analytics_yandex_metrica_cookie_days');

		if ($cookie_days < 1 || $cookie_days > 730) {
			$cookie_days = 365;
		}

		$currency = isset($this->session->data['currency']) ? $this->session->data['currency'] : $this->config->get('config_currency');
		$ecommerce = (bool)$this->config->get('analytics_yandex_metrica_ecommerce');
		$ecommerce_event = array();
		$route = isset($this->request->get['route']) ? $this->request->get['route'] : 'common/home';

		if ($ecommerce && $route == 'product/product' && !empty($this->request->get['product_id'])) {
			$this->load->model('catalog/product');
			$product = $this->model_catalog_product->getProduct((int)$this->request->get['product_id']);

			if ($product) {
				$ecommerce_event = array('ecommerce' => array('currencyCode' => $currency, 'detail' => array('products' => array($this->productData($product, 1)))));
			}
		} elseif ($ecommerce && $route == 'checkout/success' && !empty($this->session->data['analytics_purchase'])) {
			$ecommerce_event = $this->session->data['analytics_purchase'];
			unset($this->session->data['analytics_purchase']);
		}

		$data['config'] = array(
			'counter' => (int)$counter,
			'webvisor' => (bool)$this->config->get('analytics_yandex_metrica_webvisor'),
			'ecommerce' => $ecommerce,
			'currency' => (string)$currency,
			'ecommerceEvent' => $ecommerce_event,
			'goals' => array(
				'orderSuccess' => $route == 'checkout/success',
				'checkoutStart' => $route == 'checkout/checkout',
				'contactSubmit' => $route == 'information/contact/success'
			),
			'cookieDays' => $cookie_days,
			'privacyUrl' => $privacy_url,
			'banner' => array(
				'title' => (string)$banner['title'],
				'description' => (string)$banner['description'],
				'accept' => (string)$banner['accept'],
				'reject' => (string)$banner['reject'],
				'settings' => (string)$banner['settings'],
				'privacy' => (string)$banner['privacy']
			)
		);

		return $this->load->view('extension/analytics/yandex_metrica', $data);
	}

	private function productData($product, $quantity) {
		$price = !is_null($product['special']) ? (float)$product['special'] : (float)$product['price'];

		return array('id' => (string)$product['product_id'], 'name' => $product['name'], 'price' => $price, 'brand' => $product['manufacturer'], 'quantity' => (int)$quantity);
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
