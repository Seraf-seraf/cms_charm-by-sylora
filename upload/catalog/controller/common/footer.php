<?php
class ControllerCommonFooter extends Controller {
	public function index() {
		$this->load->language('common/footer');

		$column_count = (int)$this->config->get('config_footer_column_count');

		if ($column_count < 1 || $column_count > 5) {
			$column_count = 5;
		}

		$columns = $this->config->get('config_footer_columns');

		if (!is_array($columns)) {
			$columns = $this->getDefaultFooterColumns();
		}

		$data['footer_columns'] = $this->prepareFooterColumns($columns, $column_count);
		$data['footer_column_count'] = count($data['footer_columns']);

		$data['powered'] = sprintf($this->language->get('text_powered'), date('Y', time()));
		$metrica_counter = (string)$this->config->get('analytics_yandex_metrica_counter');
		$data['analytics_consent_enabled'] = (bool)$this->config->get('analytics_yandex_metrica_status') && preg_match('/^\d{5,12}$/', $metrica_counter) === 1;
		$data['privacy_policy'] = $this->getInformationUrl('privacy-policy');

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
		$this->load->model('catalog/category');

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
		$this->load->model('catalog/information');

		$information = $this->model_catalog_information->getInformationBySeoKeyword($keyword);

		return $information ? $this->url->link('information/information', 'information_id=' . (int)$information['information_id']) : $this->url->link('information/contact');
	}

	private function getDefaultFooterColumns() {
		$contact_items = array(
			array('text' => 'Написать нам', 'url' => $this->url->link('information/contact')),
		);

		$telephone = trim((string)$this->config->get('config_telephone'));

		if ($telephone !== '') {
			$contact_items[] = array(
				'text' => $telephone,
				'url' => 'tel:' . preg_replace('/[^0-9+]/', '', $telephone),
			);
		}

		$email = trim((string)$this->config->get('config_email'));

		if ($email !== '') {
			$contact_items[] = array('text' => $email, 'url' => 'mailto:' . $email);
		}

		$address = trim((string)$this->config->get('config_address'));

		if ($address !== '') {
			$contact_items[] = array('text' => $address, 'url' => '');
		}

		return array(
			array(
				'title' => 'Charm by Sylora',
				'item_count' => 2,
				'items' => array(
					array('text' => 'Ручные украшения небольшими партиями: серьги, браслеты, подвески, колье и комплекты с бережной упаковкой.', 'url' => ''),
					array('text' => 'Онлайн-оплата через платежный агрегатор.', 'url' => ''),
				),
			),
			array(
				'title' => 'Каталог',
				'item_count' => 4,
				'items' => array(
					array('text' => 'Главная', 'url' => $this->url->link('common/home')),
					array('text' => 'Каталог', 'url' => $this->getCatalogUrl()),
					array('text' => 'Обо мне', 'url' => $this->url->link('information/information', 'information_id=4')),
					array('text' => 'Корзина', 'url' => $this->url->link('checkout/cart')),
				),
			),
			array(
				'title' => 'Покупателям',
				'item_count' => 5,
				'items' => array(
					array('text' => 'Доставка и оплата', 'url' => $this->getInformationUrl('delivery-payment')),
					array('text' => 'Возврат и обмен', 'url' => $this->getInformationUrl('returns')),
					array('text' => 'Уход за украшениями', 'url' => $this->getInformationUrl('jewelry-care')),
					array('text' => 'Размеры и материалы', 'url' => $this->getInformationUrl('sizes-materials')),
					array('text' => 'Подарочная упаковка', 'url' => $this->getInformationUrl('gift-packaging')),
				),
			),
			array(
				'title' => 'Информация',
				'item_count' => 3,
				'items' => array(
					array('text' => 'Политика конфиденциальности', 'url' => $this->getInformationUrl('privacy-policy')),
					array('text' => 'Оферта', 'url' => $this->getInformationUrl('offer')),
					array('text' => 'Карта сайта', 'url' => $this->url->link('information/sitemap')),
				),
			),
			array(
				'title' => 'Контакты',
				'item_count' => min(5, count($contact_items)),
				'items' => $contact_items,
			),
		);
	}

	private function prepareFooterColumns(array $columns, $column_count) {
		$prepared_columns = array();

		for ($column_index = 0; $column_index < $column_count; $column_index++) {
			$column = isset($columns[$column_index]) && is_array($columns[$column_index]) ? $columns[$column_index] : array();
			$items = isset($column['items']) && is_array($column['items']) ? $column['items'] : array();
			$item_count = isset($column['item_count']) ? (int)$column['item_count'] : count($items);
			$item_count = max(1, min(5, $item_count));
			$prepared_items = array();

			for ($item_index = 0; $item_index < $item_count; $item_index++) {
				$item = isset($items[$item_index]) && is_array($items[$item_index]) ? $items[$item_index] : array();
				$text = isset($item['text']) ? trim((string)$item['text']) : '';

				if ($text === '') {
					continue;
				}

				$url = isset($item['url']) ? trim((string)$item['url']) : '';

				if ($url !== '' && !preg_match('#^(?:https?://|mailto:|tel:|/(?!/)|\#)#i', $url)) {
					$url = '';
				}

				$prepared_items[] = array(
					'text' => $text,
					'href' => $url,
					'external' => preg_match('#^https?://#i', $url) === 1,
				);
			}

			$prepared_columns[] = array(
				'title' => isset($column['title']) ? trim((string)$column['title']) : '',
				'items' => $prepared_items,
			);
		}

		return $prepared_columns;
	}
}
