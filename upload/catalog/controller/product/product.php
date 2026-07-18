<?php
class ControllerProductProduct extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('product/product');
		$this->load->library('seo');

		if (isset($this->request->get['product_id'])) {
			$product_id = (int)$this->request->get['product_id'];
		} else {
			$product_id = 0;
		}

		$this->load->model('catalog/product');

		$product_info = $this->model_catalog_product->getProduct($product_id);

		//check product page open from cateory page
		if (isset($this->request->get['path'])) {
			$parts = explode('_', (string)$this->request->get['path']);
						
			if(empty($this->model_catalog_product->checkProductCategory($product_id, $parts))) {
				$product_info = array();
			}
		}

		//check product page open from manufacturer page
		if (isset($this->request->get['manufacturer_id']) && !empty($product_info)) {
			if($product_info['manufacturer_id'] !=  $this->request->get['manufacturer_id']) {
				$product_info = array();
			}
		}

		if ($product_info) {
			$url = '';

			if (isset($this->request->get['path'])) {
				$url .= '&path=' . $this->request->get['path'];
			}

			if (isset($this->request->get['filter'])) {
				$url .= '&filter=' . $this->request->get['filter'];
			}

			if (isset($this->request->get['manufacturer_id'])) {
				$url .= '&manufacturer_id=' . $this->request->get['manufacturer_id'];
			}

			if (isset($this->request->get['search'])) {
				$url .= '&search=' . $this->request->get['search'];
			}

			if (isset($this->request->get['tag'])) {
				$url .= '&tag=' . urlencode(html_entity_decode(trim($this->request->get['tag']), ENT_QUOTES, 'UTF-8'));
			}

			if (isset($this->request->get['description'])) {
				$url .= '&description=' . $this->request->get['description'];
			}

			if (isset($this->request->get['category_id'])) {
				$url .= '&category_id=' . $this->request->get['category_id'];
			}

			if (isset($this->request->get['sub_category'])) {
				$url .= '&sub_category=' . $this->request->get['sub_category'];
			}

			if (isset($this->request->get['sort'])) {
				$url .= '&sort=' . $this->request->get['sort'];
			}

			if (isset($this->request->get['order'])) {
				$url .= '&order=' . $this->request->get['order'];
			}

			if (isset($this->request->get['page'])) {
				$url .= '&page=' . $this->request->get['page'];
			}

			if (isset($this->request->get['limit'])) {
				$url .= '&limit=' . $this->request->get['limit'];
			}


			$this->document->setTitle($this->seo->title($product_info['meta_title'], $product_info['name'], 'product'));
			$this->document->setDescription($this->seo->description($product_info['meta_description'], $product_info['description'], $product_info['name'], 'product'));
			$this->document->setKeywords($product_info['meta_keyword']);
			$this->document->addLink($this->url->link('product/product', 'product_id=' . $this->request->get['product_id']), 'canonical');
			$this->document->addScript('catalog/view/javascript/jquery/magnific/jquery.magnific-popup.min.js');
			$this->document->addStyle('catalog/view/javascript/jquery/magnific/magnific-popup.css');

			$data['heading_title'] = $product_info['name'];

			$data['text_minimum'] = sprintf($this->language->get('text_minimum'), $product_info['minimum']);
			$data['text_login'] = sprintf($this->language->get('text_login'), $this->url->link('account/login', '', true), $this->url->link('account/register', '', true));

			$this->load->model('catalog/review');

			$data['tab_review'] = sprintf($this->language->get('tab_review'), $product_info['reviews']);

			$data['product_id'] = (int)$this->request->get['product_id'];
			$data['manufacturer'] = $product_info['manufacturer'];
			$data['manufacturers'] = $this->url->link('product/manufacturer/info', 'manufacturer_id=' . $product_info['manufacturer_id']);
			$data['model'] = $product_info['model'];
			$data['reward'] = $product_info['reward'];
			$data['points'] = $product_info['points'];
			$data['description'] = html_entity_decode($product_info['description'], ENT_QUOTES, 'UTF-8');
			$stock_status_id = (int)$product_info['stock_status_id'];
			$is_preorder = $product_info['quantity'] <= 0 && in_array($stock_status_id, array(6, 8), true);

			if ($product_info['quantity'] <= 0) {
				if ($is_preorder) {
					$data['stock'] = 'Под заказ';
					$data['stock_class'] = 'is-preorder';
				} elseif ($stock_status_id === 5) {
					$data['stock'] = 'Нет в наличии';
					$data['stock_class'] = 'is-out';
				} else {
					$data['stock'] = $product_info['stock_status'];
					$data['stock_class'] = 'is-out';
				}
			} elseif ($product_info['quantity'] <= 2) {
				$data['stock'] = 'Осталось мало';
				$data['stock_class'] = 'is-low';
			} elseif ($this->config->get('config_stock_display')) {
				$data['stock'] = $product_info['quantity'];
				$data['stock_class'] = 'is-in';
			} else {
				$data['stock'] = 'В наличии';
				$data['stock_class'] = 'is-in';
			}

			$data['can_buy'] = $product_info['quantity'] > 0 || $is_preorder;

			$this->load->model('tool/image');

			$image_filename = is_string($product_info['image']) && $product_info['image'] !== '' && is_file(DIR_IMAGE . $product_info['image'])
				? $product_info['image']
				: 'placeholder.png';
			$popup_width = (int)$this->config->get('theme_' . $this->config->get('config_theme') . '_image_popup_width');
			$popup_height = (int)$this->config->get('theme_' . $this->config->get('config_theme') . '_image_popup_height');
			$thumb_width = (int)$this->config->get('theme_' . $this->config->get('config_theme') . '_image_thumb_width');
			$thumb_height = (int)$this->config->get('theme_' . $this->config->get('config_theme') . '_image_thumb_height');

			$data['popup'] = $this->model_tool_image->resize($image_filename, $popup_width, $popup_height);
			$data['image'] = $this->model_tool_image->resizeWithSources($image_filename, $thumb_width, $thumb_height);
			$data['thumb'] = $data['image']['src'];

			$data['images'] = array();
			$data['main_image_alt'] = $product_info['name'];

			$results = $this->model_catalog_product->getProductImages($this->request->get['product_id']);

			foreach ($results as $image_index => $result) {
				$additional_image = $this->model_tool_image->resizeWithSources($result['image'], (int)$this->config->get('theme_' . $this->config->get('config_theme') . '_image_additional_width'), (int)$this->config->get('theme_' . $this->config->get('config_theme') . '_image_additional_height'));

				$data['images'][] = array(
					'popup' => $this->model_tool_image->resize($result['image'], $this->config->get('theme_' . $this->config->get('config_theme') . '_image_popup_width'), $this->config->get('theme_' . $this->config->get('config_theme') . '_image_popup_height')),
					'thumb' => $additional_image['src'],
					'image' => $additional_image,
					'alt'   => $product_info['name'] . ' - фото ' . ($image_index + 2)
				);
			}

			$regular_price = (float)$product_info['price'];
			$special_price = (float)$product_info['special'];
			$has_special = !is_null($product_info['special']) && $special_price >= 0;
			$has_discount = $has_special && $special_price < $regular_price;
			$regular_price_with_tax = $this->tax->calculate($regular_price, $product_info['tax_class_id'], $this->config->get('config_tax'));
			$current_price = $has_special ? $special_price : $regular_price;
			$current_price_with_tax = $this->tax->calculate($current_price, $product_info['tax_class_id'], $this->config->get('config_tax'));

			if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
				$data['price'] = $this->currency->format($regular_price_with_tax, $this->session->data['currency']);

				if ($has_special) {
					$data['special'] = $this->currency->format($current_price_with_tax, $this->session->data['currency']);
				} else {
					$data['special'] = false;
				}

				if ($has_discount) {
					$data['compare_price'] = $data['price'];
					$data['saving'] = $this->currency->format($regular_price_with_tax - $current_price_with_tax, $this->session->data['currency']);
					$saving_percent = $regular_price > 0 ? (int)round((($regular_price - $special_price) / $regular_price) * 100) : 0;
					$data['saving_percent'] = $saving_percent > 0 ? $saving_percent : false;
				} else {
					$data['compare_price'] = false;
					$data['saving'] = false;
					$data['saving_percent'] = false;
				}
			} else {
				$data['price'] = false;
				$data['special'] = false;
				$data['compare_price'] = false;
				$data['saving'] = false;
				$data['saving_percent'] = false;
			}

			$tax_price = $current_price;

			if ($this->config->get('config_tax')) {
				$data['tax'] = $this->currency->format($tax_price, $this->session->data['currency']);
			} else {
				$data['tax'] = false;
			}

			$discounts = $this->model_catalog_product->getProductDiscounts($this->request->get['product_id']);

			$data['discounts'] = array();

			foreach ($discounts as $discount) {
				$data['discounts'][] = array(
					'quantity' => $discount['quantity'],
					'price'    => $this->currency->format($this->tax->calculate($discount['price'], $product_info['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency'])
				);
			}

			$data['options'] = array();
			$has_datetime_option = false;

			foreach ($this->model_catalog_product->getProductOptions($this->request->get['product_id']) as $option) {
				if (in_array($option['type'], array('date', 'time', 'datetime'))) {
					$has_datetime_option = true;
				}

				$product_option_value_data = array();

				foreach ($option['product_option_value'] as $option_value) {
					if (!$option_value['subtract'] || ($option_value['quantity'] > 0)) {
						if ((($this->config->get('config_customer_price') && $this->customer->isLogged()) || !$this->config->get('config_customer_price')) && (float)$option_value['price']) {
							$price = $this->currency->format($this->tax->calculate($option_value['price'], $product_info['tax_class_id'], $this->config->get('config_tax') ? 'P' : false), $this->session->data['currency']);
						} else {
							$price = false;
						}

						$product_option_value_data[] = array(
							'product_option_value_id' => $option_value['product_option_value_id'],
							'option_value_id'         => $option_value['option_value_id'],
							'name'                    => $option_value['name'],
							'image'                   => $this->model_tool_image->resize($option_value['image'], 50, 50),
							'price'                   => $price,
							'price_prefix'            => $option_value['price_prefix']
						);
					}
				}

				$data['options'][] = array(
					'product_option_id'    => $option['product_option_id'],
					'product_option_value' => $product_option_value_data,
					'option_id'            => $option['option_id'],
					'name'                 => $option['name'],
					'type'                 => $option['type'],
					'value'                => $option['value'],
					'required'             => $option['required']
				);
			}

			if ($has_datetime_option) {
				$this->document->addScript('catalog/view/javascript/jquery/datetimepicker/moment/moment-with-locales.min.js');
				$this->document->addScript('catalog/view/javascript/jquery/datetimepicker/bootstrap-datetimepicker.min.js');
				$this->document->addStyle('catalog/view/javascript/jquery/datetimepicker/bootstrap-datetimepicker.min.css');
			}

			if ($product_info['minimum']) {
				$data['minimum'] = $product_info['minimum'];
			} else {
				$data['minimum'] = 1;
			}

			$data['review_status'] = $this->config->get('config_review_status');

			if ($this->config->get('config_review_guest') || $this->customer->isLogged()) {
				$data['review_guest'] = true;
			} else {
				$data['review_guest'] = false;
			}

			if ($this->customer->isLogged()) {
				$data['customer_name'] = $this->customer->getFirstName() . '&nbsp;' . $this->customer->getLastName();
			} else {
				$data['customer_name'] = '';
			}

			$data['reviews'] = sprintf($this->language->get('text_reviews'), (int)$product_info['reviews']);
			$data['rating'] = (int)$product_info['rating'];

			// Captcha
			if ($this->config->get('captcha_' . $this->config->get('config_captcha') . '_status') && in_array('review', (array)$this->config->get('config_captcha_page'))) {
				$data['captcha'] = $this->load->controller('extension/captcha/' . $this->config->get('config_captcha'));
			} else {
				$data['captcha'] = '';
			}

			$data['share'] = $this->url->link('product/product', 'product_id=' . (int)$this->request->get['product_id']);

			$data['attribute_groups'] = $this->model_catalog_product->getProductAttributes($this->request->get['product_id']);
			$data['product_details'] = array(
				'materials' => '',
				'size'      => '',
				'color'     => '',
				'care'      => '',
				'delivery_return' => '',
				'image_alt' => ''
			);

			foreach ($data['attribute_groups'] as $attribute_group) {
				foreach ($attribute_group['attribute'] as $attribute) {
					$name = utf8_strtolower($attribute['name']);

					if (strpos($name, 'материал') !== false || strpos($name, 'material') !== false) {
						$data['product_details']['materials'] = $attribute['text'];
					}

					if (strpos($name, 'размер') !== false || strpos($name, 'size') !== false) {
						$data['product_details']['size'] = $attribute['text'];
					}

					if (strpos($name, 'цвет') !== false || strpos($name, 'color') !== false || strpos($name, 'colour') !== false) {
						$data['product_details']['color'] = $attribute['text'];
					}

					if (strpos($name, 'уход') !== false || strpos($name, 'care') !== false) {
						$data['product_details']['care'] = $attribute['text'];
					}

					if (strpos($name, 'доставка') !== false || strpos($name, 'возврат') !== false || strpos($name, 'delivery') !== false || strpos($name, 'return') !== false) {
						$data['product_details']['delivery_return'] = $attribute['text'];
					}

					if (strpos($name, 'alt') !== false || strpos($name, 'альт') !== false) {
						$data['product_details']['image_alt'] = $attribute['text'];
					}
				}
			}

			if ($data['product_details']['image_alt']) {
				$data['main_image_alt'] = $data['product_details']['image_alt'];
			}

			foreach ($data['images'] as $image_index => $image) {
				$data['images'][$image_index]['alt'] = $data['main_image_alt'] . ' - дополнительное фото ' . ($image_index + 1);
			}

			$data['care_text'] = $data['product_details']['care'] ? $data['product_details']['care'] : 'Храните украшение отдельно от других изделий, снимайте перед душем, сном и тренировками. Избегайте длительного контакта с водой, парфюмом и бытовой химией.';
			$data['delivery_return_text'] = $data['product_details']['delivery_return'] ? $data['product_details']['delivery_return'] : 'Доставка по России и итоговая стоимость рассчитываются при оформлении заказа. Если украшение создается под заказ, срок изготовления уточняется отдельно.';

			$is_https = !empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] != 'off';
			$server = $is_https ? $this->config->get('config_ssl') : $this->config->get('config_url');
			$product_url = html_entity_decode($this->url->link('product/product', 'product_id=' . (int)$this->request->get['product_id']), ENT_QUOTES, 'UTF-8');
			$image_url = '';

			if ($product_info['image']) {
				$image_url = rtrim($server, '/') . '/image/' . $product_info['image'];
			}

			$schema_availability = 'https://schema.org/InStock';
			$schema_price = $this->tax->calculate($tax_price, $product_info['tax_class_id'], $this->config->get('config_tax'));

			if ($product_info['quantity'] <= 0) {
				if ($data['stock'] == 'Под заказ') {
					$schema_availability = 'https://schema.org/PreOrder';
				} else {
					$schema_availability = 'https://schema.org/OutOfStock';
				}
			} elseif ($product_info['quantity'] <= 2) {
				$schema_availability = 'https://schema.org/LimitedAvailability';
			}

			$manufacturer = trim((string)$product_info['manufacturer']);
			$brand_name = $manufacturer !== '' ? $manufacturer : trim((string)$this->config->get('config_name'));
			$product_schema = array(
				'@context' => 'https://schema.org',
				'@type' => 'Product',
				'name' => $product_info['name'],
				'description' => utf8_substr(trim(strip_tags(html_entity_decode($product_info['description'], ENT_QUOTES, 'UTF-8'))), 0, 300),
				'brand' => array(
					'@type' => 'Brand',
					'name' => $brand_name
				),
				'offers' => array(
					'@type' => 'Offer',
					'url' => $product_url,
					'priceCurrency' => $this->session->data['currency'],
					'price' => number_format($schema_price, 2, '.', ''),
					'availability' => $schema_availability,
					'itemCondition' => 'https://schema.org/NewCondition'
				)
			);

			if (trim((string)$product_info['model']) !== '') {
				$product_schema['sku'] = trim((string)$product_info['model']);
			}

			if ($image_url) {
				$product_schema['image'] = array($image_url);
			}

			if ($data['product_details']['materials']) {
				$product_schema['material'] = $data['product_details']['materials'];
			}

			if ($data['product_details']['color']) {
				$product_schema['color'] = $data['product_details']['color'];
			}

			if ($data['product_details']['size']) {
				$product_schema['size'] = $data['product_details']['size'];
			}

			if ($data['rating'] && $product_info['reviews']) {
				$product_schema['aggregateRating'] = array(
					'@type' => 'AggregateRating',
					'ratingValue' => $data['rating'],
					'reviewCount' => (int)$product_info['reviews']
				);
			}

			$schema_reviews = $this->model_catalog_review->getReviewsByProductId((int)$this->request->get['product_id'], 0, 20);

			foreach ($schema_reviews as $schema_review) {
				$review = array(
					'@type' => 'Review',
					'author' => array(
						'@type' => 'Person',
						'name' => html_entity_decode($schema_review['author'], ENT_QUOTES, 'UTF-8')
					),
					'reviewRating' => array(
						'@type' => 'Rating',
						'ratingValue' => (int)$schema_review['rating'],
						'bestRating' => 5,
						'worstRating' => 1
					),
					'reviewBody' => trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($schema_review['text'], ENT_QUOTES, 'UTF-8'))))
				);

				if (!empty($schema_review['date_added'])) {
					$review['datePublished'] = date('Y-m-d', strtotime($schema_review['date_added']));
				}

				$product_schema['review'][] = $review;
			}

			$data['product_schema'] = json_encode($product_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

			$data['products'] = array();

			$results = $this->model_catalog_product->getProductRelated($this->request->get['product_id']);

			foreach ($results as $result) {
				$image_width = (int)$this->config->get('theme_' . $this->config->get('config_theme') . '_image_related_width');
				$image_height = (int)$this->config->get('theme_' . $this->config->get('config_theme') . '_image_related_height');

				if ($result['image']) {
					$image = $this->model_tool_image->resizeWithSources($result['image'], $image_width, $image_height);
				} else {
					$image = $this->model_tool_image->resizeWithSources('placeholder.png', $image_width, $image_height);
				}

				$hover_image = array(
					'src'     => '',
					'sources' => array(),
					'width'   => $image_width,
					'height'  => $image_height
				);
				$product_images = $this->model_catalog_product->getProductImages($result['product_id']);

				foreach ($product_images as $product_image) {
					if (!empty($product_image['image']) && $product_image['image'] !== $result['image']) {
						$hover_image = $this->model_tool_image->resizeWithSources($product_image['image'], $image_width, $image_height);
						break;
					}
				}

				if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
					$price = $this->currency->format($this->tax->calculate($result['price'], $result['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
				} else {
					$price = false;
				}

				if (!is_null($result['special']) && (float)$result['special'] >= 0) {
					$special = $this->currency->format($this->tax->calculate($result['special'], $result['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
					$tax_price = (float)$result['special'];
				} else {
					$special = false;
					$tax_price = (float)$result['price'];
				}
	
				if ($this->config->get('config_tax')) {
					$tax = $this->currency->format($tax_price, $this->session->data['currency']);
				} else {
					$tax = false;
				}

				if ($this->config->get('config_review_status')) {
					$rating = (int)$result['rating'];
				} else {
					$rating = false;
				}

				if ($result['quantity'] <= 0) {
					if ((int)$result['stock_status_id'] === 6 || (int)$result['stock_status_id'] === 8) {
						$stock = 'Под заказ';
						$stock_class = 'is-preorder';
					} elseif ((int)$result['stock_status_id'] === 5) {
						$stock = 'Нет в наличии';
						$stock_class = 'is-out';
					} else {
						$stock = $result['stock_status'];
						$stock_class = 'is-out';
					}
				} elseif ($result['quantity'] <= 2) {
					$stock = 'Осталось мало';
					$stock_class = 'is-low';
				} else {
					$stock = 'В наличии';
					$stock_class = 'is-in';
				}

				$is_new = !empty($result['date_added']) && strtotime($result['date_added']) >= strtotime('-30 days');
				$badge = '';
				$badge_class = '';

				if ($stock === 'Нет в наличии') {
					$badge = 'Нет в наличии';
					$badge_class = 'is-out';
				} elseif ($stock === 'Под заказ') {
					$badge = 'Под заказ';
					$badge_class = 'is-preorder';
				} elseif ($special) {
					$badge = 'Скидка';
					$badge_class = 'is-sale';
				} elseif ($is_new) {
					$badge = 'Новинка';
					$badge_class = 'is-new';
				}

				$data['products'][] = array(
					'product_id'  => $result['product_id'],
					'thumb'       => $image['src'],
					'image'       => $image,
					'hover_image' => $hover_image,
					'name'        => $result['name'],
					'description' => utf8_substr(trim(strip_tags(html_entity_decode($result['description'], ENT_QUOTES, 'UTF-8'))), 0, $this->config->get('theme_' . $this->config->get('config_theme') . '_product_description_length')) . '..',
					'price'       => $price,
					'special'     => $special,
					'badge'       => $badge,
					'badge_class' => $badge_class,
					'stock'       => $stock,
					'stock_class' => $stock_class,
					'can_buy'     => $stock !== 'Нет в наличии',
					'tax'         => $tax,
					'minimum'     => $result['minimum'] > 0 ? $result['minimum'] : 1,
					'rating'      => $rating,
					'href'        => $this->url->link('product/product', 'product_id=' . $result['product_id'])
				);
			}

			$data['tags'] = array();

			if ($product_info['tag']) {
				$tags = explode(',', $product_info['tag']);

				foreach ($tags as $tag) {
					$data['tags'][] = array(
						'tag'  => trim($tag),
						'href' => $this->url->link('product/search', 'tag=' . urlencode(html_entity_decode(trim($tag), ENT_QUOTES, 'UTF-8')))
					);
				}
			}

			$data['recurrings'] = $this->model_catalog_product->getProfiles($this->request->get['product_id']);

			$this->model_catalog_product->updateViewed($this->request->get['product_id']);
			
			$data['column_left'] = $this->load->controller('common/column_left');
			$data['column_right'] = $this->load->controller('common/column_right');
			$data['content_top'] = $this->load->controller('common/content_top');
			$data['content_bottom'] = $this->load->controller('common/content_bottom');
			$data['footer'] = $this->load->controller('common/footer');
			$data['header'] = $this->load->controller('common/header');

			$this->response->setOutput($this->load->view('product/product', $data));
		} else {
			$url = '';

			if (isset($this->request->get['path'])) {
				$url .= '&path=' . $this->request->get['path'];
			}

			if (isset($this->request->get['filter'])) {
				$url .= '&filter=' . $this->request->get['filter'];
			}

			if (isset($this->request->get['manufacturer_id'])) {
				$url .= '&manufacturer_id=' . $this->request->get['manufacturer_id'];
			}

			if (isset($this->request->get['search'])) {
				$url .= '&search=' . $this->request->get['search'];
			}

			if (isset($this->request->get['tag'])) {
				$url .= '&tag=' . urlencode(html_entity_decode(trim($this->request->get['tag']), ENT_QUOTES, 'UTF-8'));
			}

			if (isset($this->request->get['description'])) {
				$url .= '&description=' . $this->request->get['description'];
			}

			if (isset($this->request->get['category_id'])) {
				$url .= '&category_id=' . $this->request->get['category_id'];
			}

			if (isset($this->request->get['sub_category'])) {
				$url .= '&sub_category=' . $this->request->get['sub_category'];
			}

			if (isset($this->request->get['sort'])) {
				$url .= '&sort=' . $this->request->get['sort'];
			}

			if (isset($this->request->get['order'])) {
				$url .= '&order=' . $this->request->get['order'];
			}

			if (isset($this->request->get['page'])) {
				$url .= '&page=' . $this->request->get['page'];
			}

			if (isset($this->request->get['limit'])) {
				$url .= '&limit=' . $this->request->get['limit'];
			}


			$this->document->setTitle($this->language->get('text_error'));

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

	public function review() {
		$this->load->language('product/product');

		$this->load->model('catalog/review');

		if (isset($this->request->get['page'])) {
			$page = (int)$this->request->get['page'];
		} else {
			$page = 1;
		}

		$data['reviews'] = array();

		$review_total = $this->model_catalog_review->getTotalReviewsByProductId($this->request->get['product_id']);

		$results = $this->model_catalog_review->getReviewsByProductId($this->request->get['product_id'], ($page - 1) * 5, 5);

		foreach ($results as $result) {
			$data['reviews'][] = array(
				'author'         => $result['author'],
				'text'           => nl2br($result['text']),
				'rating'         => (int)$result['rating'],
				'date_added'     => date($this->language->get('date_format_short'), strtotime($result['date_added'])),
				'date_added_iso' => date('Y-m-d', strtotime($result['date_added']))
			);
		}

		$pagination = new Pagination();
		$pagination->total = $review_total;
		$pagination->page = $page;
		$pagination->limit = 5;
		$pagination->url = $this->url->link('product/product/review', 'product_id=' . $this->request->get['product_id'] . '&page={page}');

		$data['pagination'] = $pagination->render();

		$data['results'] = sprintf($this->language->get('text_pagination'), ($review_total) ? (($page - 1) * 5) + 1 : 0, ((($page - 1) * 5) > ($review_total - 5)) ? $review_total : ((($page - 1) * 5) + 5), $review_total, ceil($review_total / 5));

		$this->response->setOutput($this->load->view('product/review', $data));
	}

	public function write() {
		$this->load->language('product/product');

		$json = array();

		if (isset($this->request->get['product_id']) && $this->request->get['product_id']) {
			if ($this->request->server['REQUEST_METHOD'] == 'POST') {
				if ((utf8_strlen($this->request->post['name']) < 3) || (utf8_strlen($this->request->post['name']) > 25)) {
					$json['error'] = $this->language->get('error_name');
				}

				if ((utf8_strlen($this->request->post['text']) < 25) || (utf8_strlen($this->request->post['text']) > 1000)) {
					$json['error'] = $this->language->get('error_text');
				}
			
				if (empty($this->request->post['rating']) || $this->request->post['rating'] < 0 || $this->request->post['rating'] > 5) {
					$json['error'] = $this->language->get('error_rating');
				}

				// Captcha
				if ($this->config->get('captcha_' . $this->config->get('config_captcha') . '_status') && in_array('review', (array)$this->config->get('config_captcha_page'))) {
					$captcha = $this->load->controller('extension/captcha/' . $this->config->get('config_captcha') . '/validate');

					if ($captcha) {
						$json['error'] = $captcha;
					}
				}

				if (!isset($json['error'])) {
					$this->load->model('catalog/review');

					$this->model_catalog_review->addReview($this->request->get['product_id'], $this->request->post);

					$json['success'] = $this->language->get('text_success');
				}
			}
		} else {
			$json['error'] = $this->language->get('error_product');
		} 

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function getRecurringDescription() {
		$this->load->language('product/product');
		$this->load->model('catalog/product');

		if (isset($this->request->post['product_id'])) {
			$product_id = $this->request->post['product_id'];
		} else {
			$product_id = 0;
		}

		if (isset($this->request->post['recurring_id'])) {
			$recurring_id = $this->request->post['recurring_id'];
		} else {
			$recurring_id = 0;
		}

		if (isset($this->request->post['quantity'])) {
			$quantity = $this->request->post['quantity'];
		} else {
			$quantity = 1;
		}

		$product_info = $this->model_catalog_product->getProduct($product_id);
		
		$recurring_info = $this->model_catalog_product->getProfile($product_id, $recurring_id);

		$json = array();

		if ($product_info && $recurring_info) {
			if (!$json) {
				$frequencies = array(
					'day'        => $this->language->get('text_day'),
					'week'       => $this->language->get('text_week'),
					'semi_month' => $this->language->get('text_semi_month'),
					'month'      => $this->language->get('text_month'),
					'year'       => $this->language->get('text_year'),
				);

				if ($recurring_info['trial_status'] == 1) {
					$price = $this->currency->format($this->tax->calculate($recurring_info['trial_price'] * $quantity, $product_info['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
					$trial_text = sprintf($this->language->get('text_trial_description'), $price, $recurring_info['trial_cycle'], $frequencies[$recurring_info['trial_frequency']], $recurring_info['trial_duration']) . ' ';
				} else {
					$trial_text = '';
				}

				$price = $this->currency->format($this->tax->calculate($recurring_info['price'] * $quantity, $product_info['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);

				if ($recurring_info['duration']) {
					$text = $trial_text . sprintf($this->language->get('text_payment_description'), $price, $recurring_info['cycle'], $frequencies[$recurring_info['frequency']], $recurring_info['duration']);
				} else {
					$text = $trial_text . sprintf($this->language->get('text_payment_cancel'), $price, $recurring_info['cycle'], $frequencies[$recurring_info['frequency']], $recurring_info['duration']);
				}

				$json['success'] = $text;
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}
