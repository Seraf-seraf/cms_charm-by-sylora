<?php
class ControllerExtensionThemeCharmBySylora extends Controller {
	private const HERO_PRODUCT_KEY = 'theme_charm_by_sylora_hero_product_id';
	private const HERO_THUMB_SIZE_PX = 48;
	private const HERO_SEARCH_LIMIT = 8;

	private $error = array();

	public function install() {
		$this->load->model('setting/setting');

		$this->model_setting_setting->editSetting('theme_charm_by_sylora', array(
			'theme_charm_by_sylora_directory' => 'charm_by_sylora',
			'theme_charm_by_sylora_status' => 1,
			'theme_charm_by_sylora_product_limit' => 15,
			'theme_charm_by_sylora_product_description_length' => 100,
			'theme_charm_by_sylora_image_category_width' => 80,
			'theme_charm_by_sylora_image_category_height' => 80,
			'theme_charm_by_sylora_image_thumb_width' => 228,
			'theme_charm_by_sylora_image_thumb_height' => 228,
			'theme_charm_by_sylora_image_popup_width' => 500,
			'theme_charm_by_sylora_image_popup_height' => 500,
			'theme_charm_by_sylora_image_product_width' => 480,
			'theme_charm_by_sylora_image_product_height' => 600,
			'theme_charm_by_sylora_image_additional_width' => 74,
			'theme_charm_by_sylora_image_additional_height' => 74,
			'theme_charm_by_sylora_image_related_width' => 480,
			'theme_charm_by_sylora_image_related_height' => 600,
			'theme_charm_by_sylora_image_compare_width' => 90,
			'theme_charm_by_sylora_image_compare_height' => 90,
			'theme_charm_by_sylora_image_wishlist_width' => 47,
			'theme_charm_by_sylora_image_wishlist_height' => 47,
			'theme_charm_by_sylora_image_cart_width' => 47,
			'theme_charm_by_sylora_image_cart_height' => 47,
			'theme_charm_by_sylora_image_location_width' => 268,
			'theme_charm_by_sylora_image_location_height' => 50
		));
	}

	public function uninstall() {
		$this->load->model('setting/setting');

		$this->model_setting_setting->deleteSetting('theme_charm_by_sylora');
	}

	public function index() {
		$this->load->language('extension/theme/charm_by_sylora');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/setting');

		$store_id = $this->getThemeStoreId();
		if ($store_id === null) {
			$this->response->addHeader('HTTP/1.1 400 Bad Request');
			$this->response->setOutput($this->language->get('error_hero_store'));

			return;
		}

		$setting_info = $this->model_setting_setting->getSetting('theme_charm_by_sylora', $store_id);
		$saved_hero = $setting_info[self::HERO_PRODUCT_KEY] ?? null;
		if ($this->request->server['REQUEST_METHOD'] === 'POST' && $this->validate($store_id, $saved_hero)) {
			$settings = array_replace($setting_info, $this->request->post);
			$this->model_setting_setting->editSetting('theme_charm_by_sylora', $settings, $store_id);

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=theme', true));
		}

		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		if (isset($this->error['product_limit'])) {
			$data['error_product_limit'] = $this->error['product_limit'];
		} else {
			$data['error_product_limit'] = '';
		}

		if (isset($this->error['product_description_length'])) {
			$data['error_product_description_length'] = $this->error['product_description_length'];
		} else {
			$data['error_product_description_length'] = '';
		}

		if (isset($this->error['image_category'])) {
			$data['error_image_category'] = $this->error['image_category'];
		} else {
			$data['error_image_category'] = '';
		}

		if (isset($this->error['image_thumb'])) {
			$data['error_image_thumb'] = $this->error['image_thumb'];
		} else {
			$data['error_image_thumb'] = '';
		}

		if (isset($this->error['image_popup'])) {
			$data['error_image_popup'] = $this->error['image_popup'];
		} else {
			$data['error_image_popup'] = '';
		}

		if (isset($this->error['image_product'])) {
			$data['error_image_product'] = $this->error['image_product'];
		} else {
			$data['error_image_product'] = '';
		}

		if (isset($this->error['image_additional'])) {
			$data['error_image_additional'] = $this->error['image_additional'];
		} else {
			$data['error_image_additional'] = '';
		}

		if (isset($this->error['image_related'])) {
			$data['error_image_related'] = $this->error['image_related'];
		} else {
			$data['error_image_related'] = '';
		}

		if (isset($this->error['image_compare'])) {
			$data['error_image_compare'] = $this->error['image_compare'];
		} else {
			$data['error_image_compare'] = '';
		}

		if (isset($this->error['image_wishlist'])) {
			$data['error_image_wishlist'] = $this->error['image_wishlist'];
		} else {
			$data['error_image_wishlist'] = '';
		}

		if (isset($this->error['image_cart'])) {
			$data['error_image_cart'] = $this->error['image_cart'];
		} else {
			$data['error_image_cart'] = '';
		}

		if (isset($this->error['image_location'])) {
			$data['error_image_location'] = $this->error['image_location'];
		} else {
			$data['error_image_location'] = '';
		}

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=theme', true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/theme/charm_by_sylora', 'user_token=' . $this->session->data['user_token'] . '&store_id=' . $store_id, true)
		);

		$data['action'] = $this->url->link('extension/theme/charm_by_sylora', 'user_token=' . $this->session->data['user_token'] . '&store_id=' . $store_id, true);

		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=theme', true);

		$hero_value = isset($this->error['hero_product'])
			? $saved_hero
			: ($this->request->post[self::HERO_PRODUCT_KEY] ?? $saved_hero);
		$data['hero_product_id'] = $this->parseNonnegativeId($hero_value ?? '') ?? 0;
		$data['hero_product'] = $this->getHeroProduct($data['hero_product_id'], $store_id);
		$data['hero_product_name'] = $data['hero_product']['name'] ?? '';
		if ($data['hero_product_id'] > 0 && $data['hero_product_name'] === '') {
			$this->load->model('catalog/product');
			$descriptions = $this->model_catalog_product->getProductDescriptions($data['hero_product_id']);
			$name = $descriptions[$this->config->get('config_language_id')]['name'] ?? '';
			$data['hero_product_name'] = $name !== ''
				? html_entity_decode($name, ENT_QUOTES, 'UTF-8')
				: sprintf($this->language->get('text_hero_product_id'), $data['hero_product_id']);
		}
		$data['error_hero_product'] = $this->error['hero_product'] ?? '';
		$data['hero_product_search_url'] = $this->url->link('extension/theme/charm_by_sylora/heroProductAutocomplete', 'user_token=' . $this->session->data['user_token'] . '&store_id=' . $store_id, true);

		if (isset($this->request->post['theme_charm_by_sylora_directory'])) {
			$data['theme_charm_by_sylora_directory'] = $this->request->post['theme_charm_by_sylora_directory'];
		} elseif (isset($setting_info['theme_charm_by_sylora_directory'])) {
			$data['theme_charm_by_sylora_directory'] = $setting_info['theme_charm_by_sylora_directory'];
		} else {
			$data['theme_charm_by_sylora_directory'] = 'charm_by_sylora';
		}

		$data['directories'] = array();

		$directories = glob(DIR_CATALOG . 'view/theme/*', GLOB_ONLYDIR);

		foreach ($directories as $directory) {
			$data['directories'][] = basename($directory);
		}

		if (isset($this->request->post['theme_charm_by_sylora_product_limit'])) {
			$data['theme_charm_by_sylora_product_limit'] = $this->request->post['theme_charm_by_sylora_product_limit'];
		} elseif (isset($setting_info['theme_charm_by_sylora_product_limit'])) {
			$data['theme_charm_by_sylora_product_limit'] = $setting_info['theme_charm_by_sylora_product_limit'];
		} else {
			$data['theme_charm_by_sylora_product_limit'] = 15;
		}

		if (isset($this->request->post['theme_charm_by_sylora_status'])) {
			$data['theme_charm_by_sylora_status'] = $this->request->post['theme_charm_by_sylora_status'];
		} elseif (isset($setting_info['theme_charm_by_sylora_status'])) {
			$data['theme_charm_by_sylora_status'] = $setting_info['theme_charm_by_sylora_status'];
		} else {
			$data['theme_charm_by_sylora_status'] = '';
		}

		if (isset($this->request->post['theme_charm_by_sylora_product_description_length'])) {
			$data['theme_charm_by_sylora_product_description_length'] = $this->request->post['theme_charm_by_sylora_product_description_length'];
		} elseif (isset($setting_info['theme_charm_by_sylora_product_description_length'])) {
			$data['theme_charm_by_sylora_product_description_length'] = $setting_info['theme_charm_by_sylora_product_description_length'];
		} else {
			$data['theme_charm_by_sylora_product_description_length'] = 100;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_category_width'])) {
			$data['theme_charm_by_sylora_image_category_width'] = $this->request->post['theme_charm_by_sylora_image_category_width'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_category_width'])) {
			$data['theme_charm_by_sylora_image_category_width'] = $setting_info['theme_charm_by_sylora_image_category_width'];
		} else {
			$data['theme_charm_by_sylora_image_category_width'] = 80;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_category_height'])) {
			$data['theme_charm_by_sylora_image_category_height'] = $this->request->post['theme_charm_by_sylora_image_category_height'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_category_height'])) {
			$data['theme_charm_by_sylora_image_category_height'] = $setting_info['theme_charm_by_sylora_image_category_height'];
		} else {
			$data['theme_charm_by_sylora_image_category_height'] = 80;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_thumb_width'])) {
			$data['theme_charm_by_sylora_image_thumb_width'] = $this->request->post['theme_charm_by_sylora_image_thumb_width'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_thumb_width'])) {
			$data['theme_charm_by_sylora_image_thumb_width'] = $setting_info['theme_charm_by_sylora_image_thumb_width'];
		} else {
			$data['theme_charm_by_sylora_image_thumb_width'] = 228;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_thumb_height'])) {
			$data['theme_charm_by_sylora_image_thumb_height'] = $this->request->post['theme_charm_by_sylora_image_thumb_height'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_thumb_height'])) {
			$data['theme_charm_by_sylora_image_thumb_height'] = $setting_info['theme_charm_by_sylora_image_thumb_height'];
		} else {
			$data['theme_charm_by_sylora_image_thumb_height'] = 228;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_popup_width'])) {
			$data['theme_charm_by_sylora_image_popup_width'] = $this->request->post['theme_charm_by_sylora_image_popup_width'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_popup_width'])) {
			$data['theme_charm_by_sylora_image_popup_width'] = $setting_info['theme_charm_by_sylora_image_popup_width'];
		} else {
			$data['theme_charm_by_sylora_image_popup_width'] = 500;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_popup_height'])) {
			$data['theme_charm_by_sylora_image_popup_height'] = $this->request->post['theme_charm_by_sylora_image_popup_height'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_popup_height'])) {
			$data['theme_charm_by_sylora_image_popup_height'] = $setting_info['theme_charm_by_sylora_image_popup_height'];
		} else {
			$data['theme_charm_by_sylora_image_popup_height'] = 500;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_product_width'])) {
			$data['theme_charm_by_sylora_image_product_width'] = $this->request->post['theme_charm_by_sylora_image_product_width'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_product_width'])) {
			$data['theme_charm_by_sylora_image_product_width'] = $setting_info['theme_charm_by_sylora_image_product_width'];
		} else {
			$data['theme_charm_by_sylora_image_product_width'] = 480;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_product_height'])) {
			$data['theme_charm_by_sylora_image_product_height'] = $this->request->post['theme_charm_by_sylora_image_product_height'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_product_height'])) {
			$data['theme_charm_by_sylora_image_product_height'] = $setting_info['theme_charm_by_sylora_image_product_height'];
		} else {
			$data['theme_charm_by_sylora_image_product_height'] = 600;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_additional_width'])) {
			$data['theme_charm_by_sylora_image_additional_width'] = $this->request->post['theme_charm_by_sylora_image_additional_width'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_additional_width'])) {
			$data['theme_charm_by_sylora_image_additional_width'] = $setting_info['theme_charm_by_sylora_image_additional_width'];
		} else {
			$data['theme_charm_by_sylora_image_additional_width'] = 74;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_additional_height'])) {
			$data['theme_charm_by_sylora_image_additional_height'] = $this->request->post['theme_charm_by_sylora_image_additional_height'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_additional_height'])) {
			$data['theme_charm_by_sylora_image_additional_height'] = $setting_info['theme_charm_by_sylora_image_additional_height'];
		} else {
			$data['theme_charm_by_sylora_image_additional_height'] = 74;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_related_width'])) {
			$data['theme_charm_by_sylora_image_related_width'] = $this->request->post['theme_charm_by_sylora_image_related_width'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_related_width'])) {
			$data['theme_charm_by_sylora_image_related_width'] = $setting_info['theme_charm_by_sylora_image_related_width'];
		} else {
			$data['theme_charm_by_sylora_image_related_width'] = 480;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_related_height'])) {
			$data['theme_charm_by_sylora_image_related_height'] = $this->request->post['theme_charm_by_sylora_image_related_height'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_related_height'])) {
			$data['theme_charm_by_sylora_image_related_height'] = $setting_info['theme_charm_by_sylora_image_related_height'];
		} else {
			$data['theme_charm_by_sylora_image_related_height'] = 600;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_compare_width'])) {
			$data['theme_charm_by_sylora_image_compare_width'] = $this->request->post['theme_charm_by_sylora_image_compare_width'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_compare_width'])) {
			$data['theme_charm_by_sylora_image_compare_width'] = $setting_info['theme_charm_by_sylora_image_compare_width'];
		} else {
			$data['theme_charm_by_sylora_image_compare_width'] = 90;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_compare_height'])) {
			$data['theme_charm_by_sylora_image_compare_height'] = $this->request->post['theme_charm_by_sylora_image_compare_height'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_compare_height'])) {
			$data['theme_charm_by_sylora_image_compare_height'] = $setting_info['theme_charm_by_sylora_image_compare_height'];
		} else {
			$data['theme_charm_by_sylora_image_compare_height'] = 90;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_wishlist_width'])) {
			$data['theme_charm_by_sylora_image_wishlist_width'] = $this->request->post['theme_charm_by_sylora_image_wishlist_width'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_wishlist_width'])) {
			$data['theme_charm_by_sylora_image_wishlist_width'] = $setting_info['theme_charm_by_sylora_image_wishlist_width'];
		} else {
			$data['theme_charm_by_sylora_image_wishlist_width'] = 47;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_wishlist_height'])) {
			$data['theme_charm_by_sylora_image_wishlist_height'] = $this->request->post['theme_charm_by_sylora_image_wishlist_height'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_wishlist_height'])) {
			$data['theme_charm_by_sylora_image_wishlist_height'] = $setting_info['theme_charm_by_sylora_image_wishlist_height'];
		} else {
			$data['theme_charm_by_sylora_image_wishlist_height'] = 47;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_cart_width'])) {
			$data['theme_charm_by_sylora_image_cart_width'] = $this->request->post['theme_charm_by_sylora_image_cart_width'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_cart_width'])) {
			$data['theme_charm_by_sylora_image_cart_width'] = $setting_info['theme_charm_by_sylora_image_cart_width'];
		} else {
			$data['theme_charm_by_sylora_image_cart_width'] = 47;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_cart_height'])) {
			$data['theme_charm_by_sylora_image_cart_height'] = $this->request->post['theme_charm_by_sylora_image_cart_height'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_cart_height'])) {
			$data['theme_charm_by_sylora_image_cart_height'] = $setting_info['theme_charm_by_sylora_image_cart_height'];
		} else {
			$data['theme_charm_by_sylora_image_cart_height'] = 47;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_location_width'])) {
			$data['theme_charm_by_sylora_image_location_width'] = $this->request->post['theme_charm_by_sylora_image_location_width'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_location_width'])) {
			$data['theme_charm_by_sylora_image_location_width'] = $setting_info['theme_charm_by_sylora_image_location_width'];
		} else {
			$data['theme_charm_by_sylora_image_location_width'] = 268;
		}

		if (isset($this->request->post['theme_charm_by_sylora_image_location_height'])) {
			$data['theme_charm_by_sylora_image_location_height'] = $this->request->post['theme_charm_by_sylora_image_location_height'];
		} elseif (isset($setting_info['theme_charm_by_sylora_image_location_height'])) {
			$data['theme_charm_by_sylora_image_location_height'] = $setting_info['theme_charm_by_sylora_image_location_height'];
		} else {
			$data['theme_charm_by_sylora_image_location_height'] = 50;
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/theme/charm_by_sylora', $data));
	}

	public function heroProductAutocomplete(): void {
		$this->response->addHeader('Content-Type: application/json; charset=utf-8');
		$this->response->addHeader('Cache-Control: no-store');
		$store_id = $this->getThemeStoreId();
		$filter_name = $this->request->get['filter_name'] ?? '';
		$products = array();

		if ($store_id === null) {
			$this->response->addHeader('HTTP/1.1 400 Bad Request');
		} elseif (is_string($filter_name) && trim($filter_name) !== '') {
			$this->load->model('catalog/product');
			$filter_name = trim(html_entity_decode($filter_name, ENT_QUOTES, 'UTF-8'));
			$offset = 0;

			do {
				$matches = $this->model_catalog_product->findHeroProducts(
					$store_id,
					$filter_name,
					self::HERO_SEARCH_LIMIT,
					$offset,
				);

				foreach ($matches as $match) {
					$product = $this->formatHeroProduct($match);

					if ($product !== array()) {
						$products[] = $product;

						if (count($products) === self::HERO_SEARCH_LIMIT) {
							break 2;
						}
					}
				}

				$offset += self::HERO_SEARCH_LIMIT;
			} while (count($matches) === self::HERO_SEARCH_LIMIT);
		}

		$this->response->setOutput(json_encode($products, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
	}

	private function getThemeStoreId(): ?int {
		$raw_store_id = $this->request->get['store_id'] ?? '0';
		$store_id = $raw_store_id === '' ? null : $this->parseNonnegativeId($raw_store_id);
		if ($store_id === null || $store_id === 0) {
			return $store_id;
		}

		$this->load->model('setting/store');

		return $this->model_setting_store->getStore($store_id) ? $store_id : null;
	}

	private function parseNonnegativeId(mixed $value): ?int {
		if ($value === '') {
			return 0;
		}

		if (!is_string($value) && !is_int($value)) {
			return null;
		}

		$id = filter_var($value, FILTER_VALIDATE_INT);

		return $id !== false && $id >= 0 && (string)$id === (string)$value ? $id : null;
	}

	/**
	 * @return array{product_id: int, name: string, thumb: string}|array{}
	 */
	private function getHeroProduct(int $product_id, int $store_id): array {
		if ($product_id === 0) {
			return array();
		}

		$this->load->model('catalog/product');
		$product = $this->model_catalog_product->getHeroProduct($product_id, $store_id);

		return $product === array() ? array() : $this->formatHeroProduct($product);
	}

	/**
	 * @param array{product_id: int|string, name: string, image: string} $product
	 * @return array{product_id: int, name: string, thumb: string}|array{}
	 */
	private function formatHeroProduct(array $product): array {
		$image = realpath(DIR_IMAGE . $product['image']);
		if ($image === false || !is_file($image) || !str_starts_with($image, DIR_IMAGE) || @getimagesize($image) === false) {
			return array();
		}

		$this->load->model('tool/image');
		try {
			$thumb = @$this->model_tool_image->resize(
				$product['image'],
				self::HERO_THUMB_SIZE_PX,
				self::HERO_THUMB_SIZE_PX,
			);
		} catch (TypeError|ValueError) {
			return array();
		}

		return $thumb ? array(
			'product_id' => (int)$product['product_id'],
			'name' => html_entity_decode($product['name'], ENT_QUOTES, 'UTF-8'),
			'thumb' => str_replace(' ', '%20', $thumb),
		) : array();
	}

	protected function validate(int $store_id, mixed $saved_hero): bool {
		if (!$this->user->hasPermission('modify', 'extension/theme/charm_by_sylora')) {
			$this->error['warning'] = $this->language->get('error_permission');

			return false;
		}

		if (array_key_exists(self::HERO_PRODUCT_KEY, $this->request->post)) {
			$submitted_hero = $this->request->post[self::HERO_PRODUCT_KEY];
			$product_id = $this->parseNonnegativeId($submitted_hero);

			if (
				$product_id === null
				|| ($product_id > 0 && $submitted_hero !== $saved_hero && $this->getHeroProduct($product_id, $store_id) === array())
			) {
				$this->error['hero_product'] = $this->language->get('error_hero_product');
			}
		}

		if (!$this->request->post['theme_charm_by_sylora_product_limit']) {
			$this->error['product_limit'] = $this->language->get('error_limit');
		}

		if (!$this->request->post['theme_charm_by_sylora_product_description_length']) {
			$this->error['product_description_length'] = $this->language->get('error_limit');
		}

		if (!$this->request->post['theme_charm_by_sylora_image_category_width'] || !$this->request->post['theme_charm_by_sylora_image_category_height']) {
			$this->error['image_category'] = $this->language->get('error_image_category');
		}

		if (!$this->request->post['theme_charm_by_sylora_image_thumb_width'] || !$this->request->post['theme_charm_by_sylora_image_thumb_height']) {
			$this->error['image_thumb'] = $this->language->get('error_image_thumb');
		}

		if (!$this->request->post['theme_charm_by_sylora_image_popup_width'] || !$this->request->post['theme_charm_by_sylora_image_popup_height']) {
			$this->error['image_popup'] = $this->language->get('error_image_popup');
		}

		if (!$this->request->post['theme_charm_by_sylora_image_product_width'] || !$this->request->post['theme_charm_by_sylora_image_product_height']) {
			$this->error['image_product'] = $this->language->get('error_image_product');
		}

		if (!$this->request->post['theme_charm_by_sylora_image_additional_width'] || !$this->request->post['theme_charm_by_sylora_image_additional_height']) {
			$this->error['image_additional'] = $this->language->get('error_image_additional');
		}

		if (!$this->request->post['theme_charm_by_sylora_image_related_width'] || !$this->request->post['theme_charm_by_sylora_image_related_height']) {
			$this->error['image_related'] = $this->language->get('error_image_related');
		}

		if (!$this->request->post['theme_charm_by_sylora_image_compare_width'] || !$this->request->post['theme_charm_by_sylora_image_compare_height']) {
			$this->error['image_compare'] = $this->language->get('error_image_compare');
		}

		if (!$this->request->post['theme_charm_by_sylora_image_wishlist_width'] || !$this->request->post['theme_charm_by_sylora_image_wishlist_height']) {
			$this->error['image_wishlist'] = $this->language->get('error_image_wishlist');
		}

		if (!$this->request->post['theme_charm_by_sylora_image_cart_width'] || !$this->request->post['theme_charm_by_sylora_image_cart_height']) {
			$this->error['image_cart'] = $this->language->get('error_image_cart');
		}

		if (!$this->request->post['theme_charm_by_sylora_image_location_width'] || !$this->request->post['theme_charm_by_sylora_image_location_height']) {
			$this->error['image_location'] = $this->language->get('error_image_location');
		}

		return !$this->error;
	}
}
