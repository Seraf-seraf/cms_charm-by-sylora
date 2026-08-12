<?php
class Seo {
	private $config;

	public function __construct($registry) {
		$this->config = $registry->get('config');
	}

	public function title($primary, $name, $type) {
		$primary = $this->clean($primary);
		$defaults = array('category' => '{name} ручной работы — купить в {store}', 'product' => '{name} — авторское украшение в {store}', 'information' => '{name} — {store}', 'manufacturer' => '{name} — украшения в {store}', 'special' => 'Украшения со скидкой — {store}', 'contact' => 'Контакты — {store}');

		return $primary !== '' ? $primary : $this->render($this->template($type, 'title', $defaults[$type]), $name);
	}

	public function description($primary, $content, $name, $type) {
		$primary = $this->clean($primary);
		$content = $this->clean($content);

		if ($primary !== '') {
			return $this->truncate($primary, 170);
		}

		if ($content !== '') {
			return $this->truncate($content, 170);
		}

		$defaults = array('category' => 'Выберите {name} ручной работы: авторские изделия, аккуратная упаковка, онлайн-оплата и доставка по России.', 'product' => '{name}: авторское украшение ручной работы. Информация о материалах, цене, упаковке, оплате и доставке.', 'information' => '{name}: актуальная информация магазина {store}.', 'manufacturer' => 'Украшения {name} в каталоге {store}: цены, наличие, онлайн-оплата и доставка по России.', 'special' => 'Специальные предложения и украшения ручной работы со скидкой в магазине {store}.', 'contact' => 'Контакты {store}: телефон, email, форма связи и информация по вопросам заказа, оплаты и доставки.');

		return $this->render($this->template($type, 'description', $defaults[$type]), $name);
	}

	public function heading($name, $type) {
		$defaults = array('home' => 'Ручные украшения {store}');

		return $this->render($this->template($type, 'h1', $defaults[$type]), $name);
	}

	private function template($type, $field, $default) {
		$value = $this->clean($this->config->get('config_seo_' . $type . '_' . $field . '_template'));
		return $value !== '' ? $value : $default;
	}

	private function render($template, $name) {
		return strtr($template, array('{name}' => $this->clean($name), '{store}' => $this->clean($this->config->get('config_name'))));
	}

	private function clean($value) {
		return trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8'))));
	}

	private function truncate($value, $length) {
		return utf8_strlen($value) > $length ? rtrim(utf8_substr($value, 0, $length - 1)) . '…' : $value;
	}
}
