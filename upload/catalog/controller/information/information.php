<?php
class ControllerInformationInformation extends Controller {
	public function index() {
		$this->load->language('information/information');

		$this->load->model('catalog/information');
		$this->load->model('catalog/category');
		$this->load->library('seo');



		if (isset($this->request->get['information_id'])) {
			$information_id = (int)$this->request->get['information_id'];
		} else {
			$information_id = 0;
		}

		$information_info = $this->model_catalog_information->getInformation($information_id);

		if ($information_info) {
			$canonical = $this->url->link('information/information', 'information_id=' . $information_id);
			$about_information = $this->model_catalog_information->getInformationBySeoKeyword('about');
			$about_information_id = 0;

			if (is_array($about_information) && isset($about_information['information_id'])) {
				$about_information_id = (int)$about_information['information_id'];
			}

			$is_about_page = $about_information_id > 0 && $information_id === $about_information_id;

			$this->document->setTitle($this->seo->title($information_info['meta_title'], $information_info['title'], 'information'));
			$this->document->setDescription($this->seo->description($information_info['meta_description'], $information_info['description'], $information_info['title'], 'information'));
			$this->document->setKeywords($information_info['meta_keyword']);
			$this->document->addLink($canonical, 'canonical');


			$data['heading_title'] = $information_info['title'];
			$data['about_page'] = $is_about_page;
			$data['content_page'] = !$is_about_page;
			$data['catalog_href'] = $this->getCatalogUrl();
			$data['contact_href'] = $this->url->link('information/contact');
			$data['about_image'] = $this->getAboutImage();
			$data['about_schema'] = $is_about_page ? $this->getAboutSchema($information_info, $canonical) : '';

			$data['description'] = html_entity_decode($information_info['description'], ENT_QUOTES, 'UTF-8');

			$data['continue'] = $this->url->link('common/home');

			$data['column_left'] = $this->load->controller('common/column_left');
			$data['column_right'] = $this->load->controller('common/column_right');
			$data['content_top'] = $this->load->controller('common/content_top');
			$data['content_bottom'] = $this->load->controller('common/content_bottom');
			$data['footer'] = $this->load->controller('common/footer');
			$data['header'] = $this->load->controller('common/header');

			$this->response->setOutput($this->load->view('information/information', $data));
		} else {

			$this->document->setTitle($this->language->get('text_error'));

			$data['heading_title'] = $this->language->get('text_error');

			$data['text_error'] = $this->language->get('text_error');

			$data['continue'] = $this->url->link('common/home');

			$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 404 Not Found');
			$this->document->setRobots('noindex, nofollow');

			$data['column_left'] = $this->load->controller('common/column_left');
			$data['column_right'] = $this->load->controller('common/column_right');
			$data['content_top'] = $this->load->controller('common/content_top');
			$data['content_bottom'] = $this->load->controller('common/content_bottom');
			$data['footer'] = $this->load->controller('common/footer');
			$data['header'] = $this->load->controller('common/header');

			$this->response->setOutput($this->load->view('error/not_found', $data));
		}
	}

	public function agree() {
		$this->load->model('catalog/information');

		if (isset($this->request->get['information_id'])) {
			$information_id = (int)$this->request->get['information_id'];
		} else {
			$information_id = 0;
		}

		$output = '';

		$information_info = $this->model_catalog_information->getInformation($information_id);

		if ($information_info) {
			$output .= html_entity_decode($information_info['description'], ENT_QUOTES, 'UTF-8') . "\n";
		}

		$this->response->addHeader('X-Robots-Tag: noindex');

		$this->response->setOutput($output);
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

	private function getAboutImage() {
		$image = trim((string)$this->config->get('config_sylora_about_image'));

		if ($image && is_file(DIR_IMAGE . $image)) {
			return 'image/' . $image;
		}

		if (is_file(DIR_IMAGE . 'catalog/sylora/jewelry-collection.png')) {
			return 'image/catalog/sylora/jewelry-collection.png';
		}

		return '';
	}

	private function getAboutSchema(array $information_info, $canonical) {
		$is_https = !empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] != 'off';
		$server = $is_https ? $this->config->get('config_ssl') : $this->config->get('config_url');

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'AboutPage',
			'url'      => $canonical,
			'name'     => $information_info['title'],
			'description' => $information_info['meta_description'],
			'about'    => array(
				'@type' => 'Organization',
				'@id'   => rtrim($server, '/') . '/#organization',
				'name'  => $this->config->get('config_name')
			)
		);

		return json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
}
