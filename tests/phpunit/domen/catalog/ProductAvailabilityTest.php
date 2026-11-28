<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/upload/system/library/product_availability.php';

final class ProductAvailabilityTest extends TestCase {
	private ProductAvailability $availability;

	protected function setUp(): void {
		$this->availability = new ProductAvailability();
	}

	public function testPositiveQuantityIsInStockAndBuyable(): void {
		self::assertSame(array(
			'state' => ProductAvailability::STATE_IN_STOCK,
			'text' => 'В наличии',
			'css_class' => 'is-in',
			'can_buy' => true,
			'schema_org' => 'https://schema.org/InStock'
		), $this->availability->resolve(array('quantity' => 1, 'stock_status_id' => 5)));
	}

	public function testZeroQuantityWithPreorderStatusIsPreorderAndBuyable(): void {
		self::assertSame(array(
			'state' => ProductAvailability::STATE_PREORDER,
			'text' => 'Под заказ',
			'css_class' => 'is-preorder',
			'can_buy' => true,
			'schema_org' => 'https://schema.org/PreOrder'
		), $this->availability->resolve(array('quantity' => 0, 'stock_status_id' => 6)));
	}

	public function testUnavailableProductIsOutOfStockAndNotBuyable(): void {
		self::assertSame(array(
			'state' => ProductAvailability::STATE_OUT_OF_STOCK,
			'text' => 'Нет в наличии',
			'css_class' => 'is-out',
			'can_buy' => false,
			'schema_org' => 'https://schema.org/OutOfStock'
		), $this->availability->resolve(array('quantity' => 0, 'stock_status_id' => 5)));
	}

	public function testNegativeQuantityUsesSamePreorderRules(): void {
		self::assertTrue($this->availability->resolve(array('quantity' => -1, 'stock_status_id' => 8))['can_buy']);
		self::assertFalse($this->availability->resolve(array('quantity' => -1, 'stock_status_id' => 5))['can_buy']);
	}
}
