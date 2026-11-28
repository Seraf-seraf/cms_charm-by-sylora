<?php
class ControllerExtensionFeedGoogleSitemap extends Controller {
	public function index() {
		if ($this->config->get('feed_google_sitemap_status')) {
			$output  = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
			$output .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . PHP_EOL;
			$store_url = $this->config->get('config_ssl') ? $this->config->get('config_ssl') : $this->config->get('config_url');
			$output .= $this->buildUrl(rtrim($store_url, '/') . '/');
			$output .= $this->buildUrl($this->url->link('information/contact'));

			$this->load->model('catalog/product');
			$this->load->model('tool/image');

			$products = $this->model_catalog_product->getProducts();

			foreach ($products as $product) {
				$output .= '<url>' . PHP_EOL;
				$output .= '  <loc>' . $this->escapeXml($this->url->link('product/product', 'product_id=' . $product['product_id'])) . '</loc>' . PHP_EOL;
				$output .= '  <lastmod>' . date('Y-m-d\TH:i:sP', strtotime($product['date_modified'])) . '</lastmod>' . PHP_EOL;

				if ($product['image']) {
					$output .= '<image:image>' . PHP_EOL;
					$output .= '  <image:loc>' . $this->escapeXml($this->model_tool_image->resize($product['image'], $this->config->get('theme_' . $this->config->get('config_theme') . '_image_popup_width'), $this->config->get('theme_' . $this->config->get('config_theme') . '_image_popup_height'))) . '</image:loc>' . PHP_EOL;
					$output .= '  <image:caption>' . $this->escapeXml($product['name']) . '</image:caption>' . PHP_EOL;
					$output .= '  <image:title>' . $this->escapeXml($product['name']) . '</image:title>' . PHP_EOL;
					$output .= '</image:image>' . PHP_EOL;
				}

				$output .= '</url>' . PHP_EOL;
			}

			$this->load->model('catalog/category');

			$output .= $this->getCategories(0);

			$this->load->model('catalog/information');

			$informations = $this->model_catalog_information->getInformations();

			foreach ($informations as $information) {
				$output .= '<url>' . PHP_EOL;
				$output .= '  <loc>' . $this->escapeXml($this->url->link('information/information', 'information_id=' . $information['information_id'])) . '</loc>' . PHP_EOL;
				$output .= '</url>' . PHP_EOL;
			}

			$output .= '</urlset>';

			$this->response->addHeader('Content-Type: application/xml');
			$this->response->setOutput($output);
		}
	}

	protected function getCategories($parent_id) {
		$output = '';

		$results = $this->model_catalog_category->getCategories($parent_id);

		foreach ($results as $result) {
			if (!$this->model_catalog_category->hasActiveProducts((int)$result['category_id'])) {
				continue;
			}

			$output .= '<url>' . PHP_EOL;
			$output .= '  <loc>' . $this->escapeXml($this->url->link('product/category', 'path=' . $result['category_id'])) . '</loc>' . PHP_EOL;
			$output .= '</url>' . PHP_EOL;

			$output .= $this->getCategories($result['category_id']);
		}

		return $output;
	}

	private function buildUrl($url) {
		return '<url>' . PHP_EOL
			. '  <loc>' . $this->escapeXml($url) . '</loc>' . PHP_EOL
			. '</url>' . PHP_EOL;
	}

	private function escapeXml($value) {
		$decoded_value = html_entity_decode($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

		return htmlspecialchars($decoded_value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}
