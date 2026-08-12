<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CartUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testInteractiveCartControlsHaveMinimumTouchTarget(): void {
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$header = $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/header.twig');

		self::assertMatchesRegularExpression(
			'/\.cart-page \.is-icon-only\s*\{[^}]*width: 44px;[^}]*min-width: 44px;[^}]*height: 44px;[^}]*min-height: 44px;/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.cart-modules \.accordion-toggle\s*\{[^}]*display: flex;[^}]*min-height: 44px;/s',
			$css
		);
		self::assertMatchesRegularExpression('/stylesheet\.min\.css\?v=[^"\s]+/', $header);
	}

	public function testCartActionsUseNaturalRussianCopy(): void {
		$cartLanguage = $this->loadLanguage('upload/catalog/language/ru-ru/checkout/cart.php');
		$shippingLanguage = $this->loadLanguage('upload/catalog/language/ru-ru/extension/total/shipping.php');

		self::assertSame('Что вы хотели бы сделать ещё?', $cartLanguage['text_next'] ?? null);
		self::assertSame('Рассчитать стоимость доставки', $shippingLanguage['heading_title'] ?? null);
	}

	public function testCartHeaderDoesNotRenderWeightAndSeoCartReloadsAfterRemoval(): void {
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/checkout/cart.twig');
		$javascript = $this->read('upload/catalog/view/javascript/common.js');

		self::assertStringContainsString('<h1>{{ heading_title }}</h1>', $template);
		self::assertStringNotContainsString('{% if weight %}', $template);
		$removeFunction = strstr($javascript, "'remove': function(key, product_id, quantity)");

		self::assertIsString($removeFunction);
		self::assertStringContainsString(
			"if ($('#checkout-cart').length || getURLVar('route') == 'checkout/cart'",
			$removeFunction
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function loadLanguage(string $path): array {
		$language = array();
		$_ = &$language;
		require $this->root . '/' . $path;

		return $language;
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
