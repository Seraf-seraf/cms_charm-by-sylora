<?php
class ControllerExtensionAnalyticsYandexMetrica extends Controller {
	public function index() {
		$counter = (string)$this->config->get('analytics_yandex_metrica_counter');

		if (!preg_match('/^\d{5,12}$/', $counter)) {
			return '';
		}

		$data['counter'] = $counter;
		$data['webvisor'] = (bool)$this->config->get('analytics_yandex_metrica_webvisor');
		$data['ecommerce'] = (bool)$this->config->get('analytics_yandex_metrica_ecommerce');
		$data['currency'] = isset($this->session->data['currency']) ? $this->session->data['currency'] : $this->config->get('config_currency');
		$data['ecommerce_event'] = array();
		$route = isset($this->request->get['route']) ? $this->request->get['route'] : 'common/home';
		$data['success_page'] = ($route == 'checkout/success');
		$data['checkout_page'] = ($route == 'checkout/checkout');
		$data['contact_success'] = ($route == 'information/contact/success');

		if ($data['ecommerce'] && $route == 'product/product' && !empty($this->request->get['product_id'])) {
			$this->load->model('catalog/product');
			$product = $this->model_catalog_product->getProduct((int)$this->request->get['product_id']);

			if ($product) {
				$data['ecommerce_event'] = array('ecommerce' => array('currencyCode' => $data['currency'], 'detail' => array('products' => array($this->productData($product, 1)))));
			}
		} elseif ($data['ecommerce'] && $route == 'checkout/success' && !empty($this->session->data['analytics_purchase'])) {
			$data['ecommerce_event'] = $this->session->data['analytics_purchase'];
			unset($this->session->data['analytics_purchase']);
		}

		return $this->load->view('extension/analytics/yandex_metrica', $data);
	}

	private function productData($product, $quantity) {
		$price = !is_null($product['special']) ? (float)$product['special'] : (float)$product['price'];

		return array('id' => (string)$product['product_id'], 'name' => $product['name'], 'price' => $price, 'brand' => $product['manufacturer'], 'quantity' => (int)$quantity);
	}
}
