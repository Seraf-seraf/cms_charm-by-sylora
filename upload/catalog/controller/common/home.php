<?php
class ControllerCommonHome extends Controller {
	public function index() {
		$this->document->setTitle($this->config->get('config_meta_title') ?: 'Charm by Sylora - украшения ручной работы');
		$this->document->setDescription($this->config->get('config_meta_description') ?: 'Интернет-магазин авторских украшений ручной работы: серьги, браслеты, подвески, колье и подарочные комплекты.');
		$this->document->setKeywords('украшения ручной работы, авторские украшения, серьги, браслеты, подвески, Charm by Sylora');

		$this->load->model('catalog/category');
		$this->load->model('catalog/product');
		$this->load->model('tool/image');
		$this->load->library('seo');

		$is_https = (!empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] != 'off')
			|| (!empty($this->request->server['HTTP_X_FORWARDED_PROTO']) && strtolower($this->request->server['HTTP_X_FORWARDED_PROTO']) == 'https');
		$canonical = $is_https ? $this->config->get('config_ssl') : $this->config->get('config_url');

		$this->document->addLink($canonical, 'canonical');

		$data['home_categories'] = array();
		$data['heading_title'] = $this->seo->heading($this->config->get('config_name'), 'home');
		$data['catalog'] = $this->getCatalogUrl();
		$data['about'] = $this->url->link('information/information', 'information_id=4');

		$categories = $this->model_catalog_category->getCategories(0);

		foreach ($categories as $category) {
			$data['home_categories'][] = array(
				'name'        => $category['name'],
				'description' => $this->getCategorySummary($category['description']),
				'href'        => $this->url->link('product/category', 'path=' . $category['category_id'])
			);

			if (count($data['home_categories']) >= 6) {
				break;
			}
		}

		$data['featured_products'] = array();
		$data['hero_products'] = array();
		$featured_products = array();

		foreach ($this->model_catalog_product->getProductSpecials(array('sort' => 'p.date_added', 'order' => 'DESC', 'start' => 0, 'limit' => 6)) as $product) {
			$featured_products[$product['product_id']] = $product;
		}

		foreach ($this->model_catalog_product->getLatestProducts(6) as $product) {
			$featured_products[$product['product_id']] = $product;
		}

		foreach ($this->model_catalog_product->getPopularProducts(6) as $product) {
			$featured_products[$product['product_id']] = $product;
		}

		foreach ($featured_products as $product) {
			if ($product['image']) {
				$image = $this->model_tool_image->resizeWithSources($product['image'], 420, 315);
			} else {
				$image = $this->model_tool_image->resizeWithSources('placeholder.png', 420, 315);
			}

			if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
				$price = $this->currency->format($this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
			} else {
				$price = false;
			}

			if (!is_null($product['special']) && (float)$product['special'] >= 0) {
				$special = $this->currency->format($this->tax->calculate($product['special'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
				$badge = 'Скидка';
				$badge_class = '';
			} elseif (!empty($product['date_added']) && strtotime($product['date_added']) >= strtotime('-30 days')) {
				$special = false;
				$badge = 'Новинка';
				$badge_class = 'sylora-badge--leaf';
			} else {
				$special = false;
				$badge = 'Популярное';
				$badge_class = 'sylora-badge--earth';
			}

			if ($product['quantity'] <= 0) {
				$stock = 'Под заказ';
				$stock_class = 'is-out';
			} elseif ($product['quantity'] <= 2) {
				$stock = 'Осталось мало';
				$stock_class = 'is-low';
			} else {
				$stock = 'В наличии';
				$stock_class = 'is-in';
			}

			$product_data = array(
				'product_id'  => $product['product_id'],
				'thumb'       => $image['src'],
				'image'       => $image,
				'name'        => $product['name'],
				'price'       => $price,
				'special'     => $special,
				'badge'       => $badge,
				'badge_class' => $badge_class,
				'stock'       => $stock,
				'stock_class' => $stock_class,
				'href'        => $this->url->link('product/product', 'product_id=' . $product['product_id'])
			);

			$data['hero_products'][] = $product_data;

			if (count($data['featured_products']) < 3) {
				$data['featured_products'][] = $product_data;
			}

			if (count($data['hero_products']) >= 6) {
				break;
			}
		}

		$data['column_left'] = '';
		$data['column_right'] = '';
		$data['content_top'] = '';
		$data['content_bottom'] = '';
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('common/home', $data));
	}

	private function getCategorySummary($description) {
		$summary = trim(strip_tags(html_entity_decode($description, ENT_QUOTES, 'UTF-8')));

		if ($summary) {
			return utf8_substr($summary, 0, 96);
		}

		return 'Подборка украшений из управляемого каталога';
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
}
