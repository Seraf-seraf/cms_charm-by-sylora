<?php
class ControllerCommonHeader extends Controller {
	public function index() {
		// Analytics
		$this->load->model('setting/extension');

		$data['analytics'] = array();

		$analytics = $this->model_setting_extension->getExtensions('analytics');

		foreach ($analytics as $analytic) {
			if ($this->config->get('analytics_' . $analytic['code'] . '_status')) {
				$data['analytics'][] = $this->load->controller('extension/analytics/' . $analytic['code'], $this->config->get('analytics_' . $analytic['code'] . '_status'));
			}
		}

		$is_https = (!empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] != 'off')
			|| (!empty($this->request->server['HTTP_X_FORWARDED_PROTO']) && strtolower($this->request->server['HTTP_X_FORWARDED_PROTO']) == 'https');
		$server = $is_https ? $this->config->get('config_ssl') : $this->config->get('config_url');

		if (is_file(DIR_IMAGE . $this->config->get('config_icon'))) {
			$this->document->addLink($server . 'image/' . $this->config->get('config_icon'), 'icon');
		}

		$data['title'] = $this->document->getTitle();

		$data['base'] = $server;
		$data['description'] = $this->document->getDescription();
		$data['keywords'] = $this->document->getKeywords();
		$data['robots'] = $this->getRobotsDirective();
		$data['og_title'] = $this->document->getTitle();
		$data['og_description'] = $this->document->getDescription();
		$data['og_type'] = 'website';
		$data['og_image'] = $server . 'image/catalog/sylora/jewelry-collection.png';
		$data['og_url'] = $server;

		if (isset($this->request->server['REQUEST_URI'])) {
			$data['og_url'] = rtrim($server, '/') . $this->request->server['REQUEST_URI'];
		}

		if (isset($this->request->get['route']) && $this->request->get['route'] == 'product/product' && !empty($this->request->get['product_id'])) {
			$this->load->model('catalog/product');

			$product_info = $this->model_catalog_product->getProduct((int)$this->request->get['product_id']);

			if ($product_info) {
				$data['og_type'] = 'product';
				$data['og_title'] = $product_info['meta_title'] ? $product_info['meta_title'] : $product_info['name'];

				if ($product_info['meta_description']) {
					$data['og_description'] = $product_info['meta_description'];
				}

				if ($product_info['image'] && is_file(DIR_IMAGE . $product_info['image'])) {
					$data['og_image'] = rtrim($server, '/') . '/image/' . $product_info['image'];
				}
			}
		}

		$data['links'] = $this->document->getLinks();

		foreach ($data['links'] as $link) {
			if ($link['rel'] == 'canonical') {
				$data['og_url'] = str_replace('&amp;', '&', $link['href']);
				break;
			}
		}
		$data['styles'] = $this->document->getStyles();
		$data['scripts'] = $this->document->getScripts('header');
		$data['lang'] = $this->language->get('code');
		$data['direction'] = $this->language->get('direction');

		$data['name'] = $this->config->get('config_name');
		$data['brand_logo'] = '';
		$data['brand_logo_image'] = array();

		if (is_file(DIR_IMAGE . 'catalog/sylora/charm-by-sylora.png')) {
			$this->load->model('tool/image');

			$data['brand_logo_image'] = $this->model_tool_image->resizeWithSources('catalog/sylora/charm-by-sylora.png', 108, 108);
			$data['brand_logo'] = $data['brand_logo_image']['src'];
		}

		if (is_file(DIR_IMAGE . $this->config->get('config_logo'))) {
			$data['logo'] = $server . 'image/' . $this->config->get('config_logo');
		} else {
			$data['logo'] = '';
		}

		$organization_schema = array(
			'@type' => $this->config->get('config_address') ? 'LocalBusiness' : 'Organization',
			'@id'   => rtrim($server, '/') . '/#organization',
			'name'  => $data['name'],
			'url'   => $server
		);

		if ($data['logo']) {
			$organization_schema['logo'] = $data['logo'];
			$organization_schema['image'] = $data['logo'];
		} else {
			$organization_schema['image'] = $data['og_image'];
		}

		if ($this->config->get('config_telephone')) {
			$organization_schema['telephone'] = $this->config->get('config_telephone');
		}

		if ($this->config->get('config_email')) {
			$organization_schema['email'] = $this->config->get('config_email');
		}

		if ($this->config->get('config_address')) {
			$organization_schema['address'] = array(
				'@type' => 'PostalAddress',
				'streetAddress' => trim(preg_replace('/\s+/u', ' ', strip_tags((string)$this->config->get('config_address'))))
			);
		}

		if ($this->config->get('config_geocode')) {
			$coordinates = array_map('trim', explode(',', $this->config->get('config_geocode')));

			if (count($coordinates) == 2 && is_numeric($coordinates[0]) && is_numeric($coordinates[1])) {
				$organization_schema['geo'] = array(
					'@type' => 'GeoCoordinates',
					'latitude' => (float)$coordinates[0],
					'longitude' => (float)$coordinates[1]
				);
			}
		}

		$same_as = array();

		foreach (array('config_sylora_vk', 'config_sylora_telegram', 'config_sylora_instagram', 'config_sylora_pinterest', 'config_sylora_youtube') as $social_key) {
			if (filter_var($this->config->get($social_key), FILTER_VALIDATE_URL)) {
				$same_as[] = $this->config->get($social_key);
			}
		}

		if ($same_as) {
			$organization_schema['sameAs'] = $same_as;
		}

		$data['site_schema'] = json_encode(array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				$organization_schema,
				array(
					'@type' => 'WebSite',
					'@id'   => rtrim($server, '/') . '/#website',
					'url'   => $server,
						'name'  => $data['name'],
					'publisher' => array(
						'@id' => rtrim($server, '/') . '/#organization'
					),
					'potentialAction' => array(
						'@type'       => 'SearchAction',
						'target'      => rtrim($server, '/') . '/index.php?route=product/search&search={search_term_string}',
						'query-input' => 'required name=search_term_string'
					)
				)
			)
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		$this->load->model('catalog/category');
		
		$data['home'] = $this->url->link('common/home');
		$data['shopping_cart'] = $this->url->link('checkout/cart');
		$data['contact'] = $this->url->link('information/contact');
		$data['catalog'] = $this->getCatalogUrl();
		$data['about'] = $this->url->link('information/information', 'information_id=4');
		
		$data['search'] = $this->load->controller('common/search');
		$data['cart'] = $this->load->controller('common/cart');

		return $this->load->view('common/header', $data);
	}

	private function getCatalogUrl() {
		$category = $this->model_catalog_category->getCategoryBySeoKeyword('all-jewelry');

		if (!$category) {
			$category = $this->model_catalog_category->getCategoryByName('Все украшения');
		}

		if ($category) {
			return $this->url->link('product/category', 'path=' . (int)$category['category_id']);
		}

		return $this->url->link('product/search');
	}

	private function getRobotsDirective() {
		$route = isset($this->request->get['route']) ? (string)$this->request->get['route'] : 'common/home';
		$noindex_prefixes = array('account/', 'affiliate/', 'checkout/', 'error/');

		foreach ($noindex_prefixes as $prefix) {
			if (strpos($route, $prefix) === 0) {
				return 'noindex, nofollow';
			}
		}

		if ($route == 'product/search') {
			return 'noindex, follow';
		}

		return 'index, follow';
	}
}
