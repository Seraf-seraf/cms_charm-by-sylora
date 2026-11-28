<?php

class CatalogProductTest extends OpenCartSeleniumTest {
	/**
	 * @before
	 */
	protected function setupTest() {
		$this->setBrowser('firefox');
		$this->setBrowserUrl(HTTP_SERVER);
	}

	public function testSearch() {
		$this->url('index.php?route=common/home');
		$this->byCssSelector('header #search input[name="search"]')->click();
		$this->keys('Розовый рассвет');
		$this->byCssSelector('header #search button')->click();

		$this->waitUntil(function() {
			return strpos($this->url(), 'product/search') !== false;
		}, 3000);

		$element = $this->byCssSelector('.catalog-card__title a');
		$this->assertEquals('Серьги Розовый рассвет', trim($element->text()));
	}

	public function testAddToCartButton() {
		$this->openSyntheticProduct();
		$this->clickOnElement('button-cart');

		$this->waitUntil(function() {
			return $this->byCssSelector('#content .cart-notice[role="status"]')->displayed();
		}, 5000);

		$this->url('index.php?route=checkout/cart');
		$element = $this->byCssSelector('#checkout-cart .cart-item__top h2 a');
		$this->assertEquals('Серьги Розовый рассвет', trim($element->text()));
		$this->assertEquals('1', $this->byCssSelector('#checkout-cart .cart-item input[name^="quantity["]')->attribute('value'));
	}

	public function testQuantityField() {
		$this->openSyntheticProduct();
		$inputElement = $this->byId('input-quantity');
		$inputElement->clear();
		$inputElement->click();
		$this->keys('3');
		$this->clickOnElement('button-cart');

		$this->waitUntil(function() {
			return $this->byCssSelector('#content .cart-notice[role="status"]')->displayed();
		}, 5000);

		$this->url('index.php?route=checkout/cart');
		$this->assertEquals('3', $this->byCssSelector('#checkout-cart .cart-item input[name^="quantity["]')->attribute('value'));
		$this->assertEquals('Серьги Розовый рассвет', trim($this->byCssSelector('#checkout-cart .cart-item__top h2 a')->text()));
	}

	public function testWishListButton() {
		$this->openSyntheticProduct();
		$element = $this->byCssSelector('#product-product button[data-wishlist-add]');
		$element->click();

		$this->waitUntil(function() use ($element) {
			return $element->attribute('aria-pressed') === 'true'
				&& $this->byCssSelector('#site-notifications [role="status"]')->displayed();
		}, 5000);

		$this->assertStringEndsWith('/heart.svg', $element->byCssSelector('img.atelier-icon--heart')->attribute('src'));
	}

	public function testCompareButton() {
		$this->openSyntheticProduct();
		$productId = $this->byCssSelector('#product-product[data-product-id]')->attribute('data-product-id');
		$this->execute(array(
			'script' => 'window.compare.add(arguments[0]);',
			'args' => array($productId),
		));

		$this->waitUntil(function() {
			return $this->byCssSelector('.alert.alert-success.alert-dismissible')->displayed();
		}, 5000);

		$this->url('index.php?route=product/compare');
		$this->waitUntil(function() {
			return strpos($this->url(), 'route=product/compare') !== false;
		}, 3000);

		$this->assertEquals($productId, $this->byCssSelector('#product-compare [data-cart-add]')->attribute('data-cart-add'));
		$this->assertEquals('Серьги Розовый рассвет', trim($this->byCssSelector('#product-compare table tbody tr:first-child td:nth-child(2) a strong')->text()));
	}

	private function openSyntheticProduct() {
		$this->url('index.php?route=product/search&search=' . rawurlencode('Розовый рассвет'));
		$this->waitUntil(function() {
			return strpos($this->url(), 'product/search') !== false;
		}, 3000);
		$productId = $this->byCssSelector('.catalog-card [data-wishlist-add]')->attribute('data-wishlist-add');
		$this->url('index.php?route=product/product&product_id=' . rawurlencode($productId));
		$this->waitUntil(function() {
			return strpos($this->url(), 'product/product') !== false;
		}, 3000);
	}
}
