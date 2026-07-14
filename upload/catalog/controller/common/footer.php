<?php
class ControllerCommonFooter extends Controller {
	public function index() {
		$this->load->language('common/footer');

		$this->load->model('catalog/information');
		$this->load->model('catalog/category');

		$data['informations'] = array();

		foreach ($this->model_catalog_information->getInformations() as $result) {
			if ($result['bottom']) {
				$data['informations'][] = array(
					'title' => $result['title'],
					'href'  => $this->url->link('information/information', 'information_id=' . $result['information_id'])
				);
			}
		}

		$data['contact'] = $this->url->link('information/contact');
		$data['home'] = $this->url->link('common/home');
		$data['catalog'] = $this->getCatalogUrl();
		$data['cart'] = $this->url->link('checkout/cart');
		$data['about'] = $this->url->link('information/information', 'information_id=4');
		$data['delivery'] = $this->getInformationUrl('delivery-payment');
		$data['privacy'] = $this->getInformationUrl('privacy-policy');
		$data['terms'] = $this->getInformationUrl('offer');
		$data['care'] = $this->getInformationUrl('jewelry-care');
		$data['return'] = $this->getInformationUrl('returns');
		$data['sizes_materials'] = $this->getInformationUrl('sizes-materials');
		$data['gift_packaging'] = $this->getInformationUrl('gift-packaging');
		$data['sitemap'] = $this->url->link('information/sitemap');
		$data['tracking'] = $this->url->link('information/tracking');
		$data['manufacturer'] = $this->url->link('product/manufacturer');
		$data['voucher'] = $this->url->link('account/voucher', '', true);
		$data['affiliate'] = $this->url->link('affiliate/login', '', true);
		$data['special'] = $this->url->link('product/special');
		$data['account'] = $this->url->link('account/account', '', true);
		$data['order'] = $this->url->link('account/order', '', true);
		$data['wishlist'] = $this->url->link('account/wishlist', '', true);
		$data['newsletter'] = $this->url->link('account/newsletter', '', true);
		$data['store'] = $this->config->get('config_name');
		$data['telephone'] = $this->config->get('config_telephone');
		$data['email'] = $this->config->get('config_email');
		$data['address'] = $this->config->get('config_address');
		$data['payment_methods'] = trim((string)$this->config->get('config_footer_payment_methods'));
		$data['social_links'] = $this->getSocialLinks();

		$data['powered'] = sprintf($this->language->get('text_powered'), date('Y', time()));

		// Whos Online
		if ($this->config->get('config_customer_online')) {
			$this->load->model('tool/online');

			if (isset($this->request->server['REMOTE_ADDR'])) {
				$ip = $this->request->server['REMOTE_ADDR'];
			} else {
				$ip = '';
			}

			if (isset($this->request->server['HTTP_HOST']) && isset($this->request->server['REQUEST_URI'])) {
				$url = ($this->request->server['HTTPS'] ? 'https://' : 'http://') . $this->request->server['HTTP_HOST'] . $this->request->server['REQUEST_URI'];
			} else {
				$url = '';
			}

			if (isset($this->request->server['HTTP_REFERER'])) {
				$referer = $this->request->server['HTTP_REFERER'];
			} else {
				$referer = '';
			}

			$this->model_tool_online->addOnline($ip, $this->customer->getId(), $url, $referer);
		}

		$data['scripts'] = $this->document->getScripts('footer');
		$data['styles'] = $this->document->getStyles('footer');
		
		return $this->load->view('common/footer', $data);
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

	private function getInformationUrl($keyword) {
		$information = $this->model_catalog_information->getInformationBySeoKeyword($keyword);

		return $information ? $this->url->link('information/information', 'information_id=' . (int)$information['information_id']) : $this->url->link('information/contact');
	}

	private function getSocialLinks() {
		$links = array(
			array('name' => 'Telegram', 'href' => trim((string)$this->config->get('config_footer_social_telegram'))),
			array('name' => 'VK', 'href' => trim((string)$this->config->get('config_footer_social_vk'))),
			array('name' => 'Instagram', 'href' => trim((string)$this->config->get('config_footer_social_instagram'))),
		);

		return array_values(array_filter($links, static function ($link) {
			return $link['href'] !== '';
		}));
	}
}
