<?php

class ProductAvailability {
	public const STATE_IN_STOCK = 'in_stock';
	public const STATE_PREORDER = 'preorder';
	public const STATE_OUT_OF_STOCK = 'out_of_stock';

	private const PREORDER_STOCK_STATUS_IDS = array(6, 8);

	/**
	 * @param array<string, mixed> $product
	 * @return array<string, mixed>
	 */
	public function resolve(array $product): array {
		$quantity = (int)($product['quantity'] ?? 0);
		$stock_status_id = (int)($product['stock_status_id'] ?? 0);
		$is_preorder = ($quantity <= 0 && in_array($stock_status_id, self::PREORDER_STOCK_STATUS_IDS, true));

		if ($quantity > 0) {
			return array(
				'state' => self::STATE_IN_STOCK,
				'text' => 'В наличии',
				'css_class' => 'is-in',
				'can_buy' => true,
				'schema_org' => 'https://schema.org/InStock'
			);
		}

		if ($is_preorder) {
			return array(
				'state' => self::STATE_PREORDER,
				'text' => 'Под заказ',
				'css_class' => 'is-preorder',
				'can_buy' => true,
				'schema_org' => 'https://schema.org/PreOrder'
			);
		}

		return array(
			'state' => self::STATE_OUT_OF_STOCK,
			'text' => 'Нет в наличии',
			'css_class' => 'is-out',
			'can_buy' => false,
			'schema_org' => 'https://schema.org/OutOfStock'
		);
	}
}
