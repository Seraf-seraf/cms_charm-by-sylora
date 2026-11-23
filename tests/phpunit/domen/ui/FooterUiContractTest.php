<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FooterUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testLegacyCatalogUrlUsesCurrentCatalogRoute(): void {
		$footerController = $this->read('upload/catalog/controller/common/footer.php');
		$settingController = $this->read('upload/admin/controller/setting/setting.php');

		self::assertStringContainsString("if (\$url === '/all-jewelry')", $footerController);
		self::assertStringContainsString("\$url = \$this->getCatalogUrl();", $footerController);
		self::assertStringContainsString("array('text' => 'Каталог', 'url' => '/search')", $settingController);
		self::assertStringNotContainsString("array('text' => 'Каталог', 'url' => '/all-jewelry')", $settingController);
	}

	public function testFooterLinksHaveMinimumTouchTarget(): void {
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$header = $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/header.twig');

		self::assertMatchesRegularExpression(
			'/\.site-footer a\s*\{[^}]*min-width: 44px;[^}]*min-height: 44px;/s',
			$css
		);
		self::assertMatchesRegularExpression('/stylesheet\.min\.css\?v=[^"\s]+/', $header);
	}

	public function testCartAndCheckoutUseCompactFooterWithoutDroppingConfiguredLinks(): void {
		$controller = $this->read('upload/catalog/controller/common/footer.php');
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/footer.twig');
		$language = array();
		$_ = &$language;
		require $this->root . '/upload/catalog/language/ru-ru/common/footer.php';
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');

		self::assertStringContainsString(
			"\$data['checkout_page'] = in_array(\$route, array('checkout/cart', 'checkout/checkout'), true);",
			$controller
		);
		self::assertStringContainsString("if (\$title === 'Charm by Sylora')", $controller);
		self::assertStringContainsString("\$this->config->get('config_name')", $controller);
		self::assertSame('Разделы сайта и контакты', $language['text_footer_navigation'] ?? null);
		self::assertStringContainsString('site-footer--checkout', $template);
		self::assertStringContainsString('<details class="site-footer__details">', $template);
		self::assertStringContainsString('<summary>{{ text_footer_navigation }}</summary>', $template);
		self::assertStringContainsString('site-footer__grid--{{ footer_column_count }}', $template);
		self::assertStringContainsString('{{ powered }}', $template);
		self::assertMatchesRegularExpression(
			'/\.site-footer__details > summary\s*\{[^}]*min-height: 44px;/s',
			$css
		);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
