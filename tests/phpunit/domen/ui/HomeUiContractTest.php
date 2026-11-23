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
			'/\.sylora-link,\s*\.sylora-section__head > a,[^{]+\{[^}]*display: inline-flex;[^}]*align-items: center;[^}]*min-height: 44px;/s',
			$css
		);
		self::assertMatchesRegularExpression('/stylesheet\.min\.css\?v=[^"\s]+/', $header);
	}

	public function testHeroUsesAdminManagedAutomaticSlideshow(): void {
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
		self::assertStringContainsString('class="swiper-pagination', $slideshow);
		self::assertStringContainsString('class="swiper-button-next', $slideshow);
		self::assertStringContainsString('class="swiper-button-prev', $slideshow);
	}

	public function testHomeCategorySectionUsesFigmaHeading(): void {
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/home.twig');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');

		self::assertStringContainsString('<h2>Выберите украшение</h2>', $template);
		self::assertMatchesRegularExpression('/@media \(min-width: 1200px\)\s*\{.*?#common-home \.sylora-hero h1 \{ max-width: 560px; \}/s', $css);
	}

	public function testHeroMediaUsesResponsiveFigmaFrameWithoutReplacingTheConfiguredSlideshow(): void {
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/extension/module/slideshow.twig');

		self::assertStringContainsString('aspect-ratio: 624 / 560;', $css);
		self::assertStringContainsString('aspect-ratio: 354 / 386;', $css);
		self::assertStringContainsString('object-fit: cover;', $css);
		self::assertStringContainsString('#common-home .sylora-actions { margin-top: 4px; }', $css);
		self::assertStringContainsString('#common-home .sylora-hero + .sylora-section { padding-top: 44px; }', $css);
		self::assertStringContainsString('#common-home .sylora-hero__media { transform: translateX(8px); }', $css);
		self::assertStringContainsString('class="sylora-hero__copy"', $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/home.twig'));
		self::assertStringContainsString('#common-home .sylora-hero__copy { display: contents; }', $css);
		self::assertStringContainsString('data-slideshow', $template);
		self::assertStringContainsString('{{ banner.link }}', $template);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
