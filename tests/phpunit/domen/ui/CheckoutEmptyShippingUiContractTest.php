<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CheckoutEmptyShippingUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testCharmThemeShowsContactAndAddressActionsForEmptyShipping(): void {
		$theme = $this->read('upload/catalog/view/theme/charm_by_sylora/template/checkout/shipping_method.twig');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$language = $this->loadLanguage('upload/catalog/language/ru-ru/ru-ru.php');

		self::assertSame('Связаться с нами', $language['button_contact'] ?? null);
		self::assertSame('Изменить адрес', $language['button_change_address'] ?? null);
		self::assertStringContainsString('class="shipping-methods__empty-actions"', $theme);
		self::assertStringContainsString('href="{{ contact }}"', $theme);
		self::assertStringContainsString('class="btn btn-default shipping-methods__contact"', $theme);
		self::assertStringContainsString('href="#collapse-shipping-address" data-toggle="collapse" data-parent="#accordion"', $theme);
		self::assertStringContainsString('{{ error_warning }}', $theme);
		self::assertMatchesRegularExpression(
			'/#checkout-checkout \.shipping-methods__empty-actions\s*\{[^}]*display: grid;[^}]*gap: 18px;/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/#checkout-checkout \.shipping-methods__empty-actions \.btn\s*\{[^}]*width: 100%;[^}]*min-height: 48px;/s',
			$css
		);
	}

	public function testDefaultThemeAndLocalizedWarningKeepTheContactPlaceholder(): void {
		$defaultTheme = $this->read('upload/catalog/view/theme/default/template/checkout/shipping_method.twig');
		$controller = $this->read('upload/catalog/controller/checkout/shipping_method.php');
		$language = $this->loadLanguage('upload/catalog/language/ru-ru/checkout/checkout.php');
		$warning = $language['error_no_shipping'] ?? '';
		$formattedWarning = sprintf($warning, '/contact');

		self::assertStringContainsString('{{ error_warning }}', $defaultTheme);
		self::assertStringContainsString(
			'$data[\'error_warning\'] = sprintf($this->language->get(\'error_no_shipping\'), $data[\'contact\']);',
			$controller
		);
		self::assertStringContainsString('<a href="%s">', $warning);
		self::assertStringContainsString('<a href="/contact">свяжитесь с нами</a>', $formattedWarning);
		self::assertStringNotContainsString('%s', $formattedWarning);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}

	private function loadLanguage(string $path): array {
		$language = array();
		$_ = &$language;
		require $this->root . '/' . $path;

		return $language;
	}
}
