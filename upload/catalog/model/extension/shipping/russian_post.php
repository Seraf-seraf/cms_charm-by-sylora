<?php
class ModelExtensionShippingRussianPost extends Model {
	public function getQuote($address) {
		$this->load->language('extension/shipping/russian_post');
		if ((int)$address['country_id'] !== 176) {
			return array();
		}

		require_once DIR_SYSTEM . 'library/russian_post_delivery.php';
		$delivery = new RussianPostDelivery($this->registry);
		$package = $delivery->getPackage();
		$fingerprint = $delivery->getFingerprint($address, $package);
		$selection = isset($this->session->data['russian_post_delivery']) ? $this->session->data['russian_post_delivery'] : array();
		$verified = !empty($selection['verified']) && isset($selection['fingerprint']) && hash_equals($selection['fingerprint'], $fingerprint);
		$cost = $verified ? (float)$selection['cost'] : 0.0;
		$title = $verified ? sprintf($this->language->get('text_selected'), $selection['index'], $selection['address']) : $this->language->get('text_choose_office');

		return array(
			'code' => 'russian_post',
			'title' => $this->language->get('text_title'),
			'quote' => array('office' => array(
				'code' => 'russian_post.office',
				'title' => $title . ($verified && $selection['terms'] ? '. ' . $selection['terms'] : ''),
				'cost' => $cost,
				'tax_class_id' => 0,
				'text' => $verified ? $this->currency->format($cost, $this->session->data['currency']) : $this->language->get('text_not_calculated')
			)),
			'sort_order' => (int)$this->config->get('shipping_russian_post_sort_order'),
			'error' => false
		);
	}
}
