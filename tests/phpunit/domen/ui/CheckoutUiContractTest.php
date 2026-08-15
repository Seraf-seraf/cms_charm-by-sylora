<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CheckoutUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testCheckoutIntroIsTranslatedAndControlsHaveMinimumTouchTarget(): void {
		$language = array();
		$_ = &$language;
		require $this->root . '/upload/catalog/language/ru-ru/checkout/checkout.php';

		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$header = $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/header.twig');

		self::assertSame(
			'Выберите удобный вариант оформления заказа: без регистрации или с созданием учетной записи.',
			$language['text_checkout_intro'] ?? null
		);
		self::assertMatchesRegularExpression(
			'/\.checkout-accordion \.form-control\s*\{[^}]*min-height: 44px;/s',
			$css
		);
		self::assertMatchesRegularExpression('/stylesheet\.min\.css\?v=[^"\s]+/', $header);
	}

	public function testShippingQuoteModalUsesThemeControls(): void {
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$shipping = $this->read('upload/catalog/view/theme/charm_by_sylora/template/extension/total/shipping.twig');
		$cart = $this->read('upload/catalog/view/javascript/cart.js');
		$cdek = $this->read('upload/catalog/view/javascript/shipping/cdek_official.js');

		self::assertMatchesRegularExpression(
			'/#modal-shipping \.modal-content\s*\{[^}]*border-radius: 12px;[^}]*box-shadow:/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.shipping-method-card\s*\{[^}]*grid-template-columns: 22px minmax\(0, 1fr\) auto;[^}]*min-height: 56px;/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.shipping-method-card__control\s*\{[^}]*position: absolute;[^}]*width: 1px;[^}]*height: 1px;/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/#modal-shipping \.modal-body button:not\(\.close\),[^\{]+\{[^}]*border-radius: 8px;[^}]*font-family: var\(--font-display\);/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.shipping-method-card:has\(\.shipping-method-card__control:focus-visible\)\s*\{[^}]*outline: 3px solid var\(--color-focus-ring\);/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.shipping-method-card:has\(\.shipping-method-card__control:disabled\)\s*\{[^}]*cursor: not-allowed;[^}]*opacity: \.55;/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.cdek_btn\s*\{[^}]*align-items: center;[^}]*flex: 0 0 auto;[^}]*white-space: nowrap;/s',
			$css
		);
		self::assertStringNotContainsString('<style data-cdek>', $cdek);
		self::assertStringContainsString('type="application/json"', $shipping);
		self::assertStringNotContainsString('syncShippingButton', $shipping);
		self::assertStringContainsString("$('input[name=\\'shipping_method\\']:checked').length === 0", $cart);
		self::assertStringContainsString('syncShippingButton();', $cart);
		self::assertMatchesRegularExpression(
			'/\.modal-footer \.btn-primary\[disabled\]\s*\{[^}]*color: #ffffff;[^}]*opacity: 1;/s',
			$css
		);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
