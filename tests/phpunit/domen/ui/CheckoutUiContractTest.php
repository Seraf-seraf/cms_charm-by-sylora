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
		self::assertStringContainsString('stylesheet.min.css?v=20260808-checkout-controls-v1', $header);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
