<?php
class ControllerInformationInformation extends Controller {
	public function index() {
		$this->load->language('information/information');

		$this->load->model('catalog/information');
		$this->load->model('catalog/category');
		$this->load->library('seo');

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/home')
		);

		if (isset($this->request->get['information_id'])) {
			$information_id = (int)$this->request->get['information_id'];
		} else {
			$information_id = 0;
		}

		$information_info = $this->model_catalog_information->getInformation($information_id);

		if ($information_info) {
			$this->document->setTitle($this->seo->title($information_info['meta_title'], $information_info['title'], 'information'));
			$this->document->setDescription($this->seo->description($information_info['meta_description'], $information_info['description'], $information_info['title'], 'information'));
			$this->document->setKeywords($information_info['meta_keyword']);
			$this->document->addLink($this->url->link('information/information', 'information_id=' . $information_id), 'canonical');

			$data['breadcrumbs'][] = array(
				'text' => $information_info['title'],
				'href' => $this->url->link('information/information', 'information_id=' .  $information_id)
			);

			$data['heading_title'] = $information_info['title'];
			$data['about_page'] = ($information_id == 4);
			$data['catalog_href'] = $this->getCatalogUrl();
			$data['contact_href'] = $this->url->link('information/contact');
			$data['about_image'] = $this->getAboutImage();
			$data['about_schema'] = $information_id == 4 ? $this->getAboutSchema($information_info) : '';

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
			$data['breadcrumbs'][] = array(
				'text' => $this->language->get('text_error'),
				'href' => $this->url->link('information/information', 'information_id=' . $information_id)
			);

			$this->document->setTitle($this->language->get('text_error'));

			$data['heading_title'] = $this->language->get('text_error');

			$data['text_error'] = $this->language->get('text_error');

			$data['continue'] = $this->url->link('common/home');

			$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 404 Not Found');

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

	private function getAboutSchema(array $information_info) {
		$is_https = !empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] != 'off';
		$server = $is_https ? $this->config->get('config_ssl') : $this->config->get('config_url');

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'AboutPage',
			'url'      => $this->url->link('information/information', 'information_id=4'),
			'name'     => $information_info['title'],
			'description' => $information_info['meta_description'],
			'about'    => array(
				'@type' => 'Organization',
				'@id'   => rtrim($server, '/') . '/#organization',
				'name'  => $this->config->get('config_name') ? $this->config->get('config_name') : 'Charm by Sylora'
			)
		);

		return json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
}
