<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HomeUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testStandaloneHomeLinksHaveMinimumTouchTarget(): void {
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$header = $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/header.twig');

		self::assertMatchesRegularExpression(
			'/\.sylora-link\s*\{[^}]*display: inline-flex;[^}]*align-items: center;[^}]*min-height: 44px;/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.sylora-section__head > a\s*\{[^}]*display: inline-flex;[^}]*align-items: center;[^}]*min-height: 44px;/s',
			$css
		);
		self::assertMatchesRegularExpression('/stylesheet\.min\.css\?v=[^"\s]+/', $header);
	}

	public function testHeroUsesAdminManagedAutomaticSlideshow(): void {
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/home.twig');
		$controller = $this->read('upload/catalog/controller/common/home.php');
		$slideshow = $this->read('upload/catalog/view/theme/charm_by_sylora/template/extension/module/slideshow.twig');
		$storefront = $this->read('upload/catalog/view/javascript/storefront.js');

		self::assertStringContainsString("getLayoutModules(\$layout_id, 'content_top')", $controller);
		self::assertStringContainsString("\$code[0] !== 'slideshow'", $controller);
		self::assertStringContainsString("load->controller('extension/module/slideshow', \$setting)", $controller);
		self::assertStringContainsString('{% if hero_banner %}', $template);
		self::assertStringContainsString('{{ hero_banner }}', $template);
		self::assertStringContainsString('data-slideshow', $slideshow);
		self::assertStringNotContainsString('<script', $slideshow);
		self::assertStringContainsString('autoplay: 5000', $storefront);
		self::assertStringContainsString('autoplayDisableOnInteraction: false', $storefront);
		self::assertStringContainsString('loop: true', $storefront);
		self::assertMatchesRegularExpression('/\.sylora-hero__banner \.swiper-pager,[^{]+\{\s*display: none;/s', $css);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
