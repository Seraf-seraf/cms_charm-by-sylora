<?php

declare(strict_types=1);

use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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

	public function testHeroUsesSelectedStoreProductAndKeepsSlideshowModule(): void {
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/home.twig');
		$controller = $this->read('upload/catalog/controller/common/home.php');
		$slideshow = $this->read('upload/catalog/view/theme/charm_by_sylora/template/extension/module/slideshow.twig');
		$storefront = $this->read('upload/catalog/view/javascript/storefront.js');

		self::assertStringContainsString("getSettingValue('theme_charm_by_sylora_hero_product_id', \$store_id)", $controller);
		self::assertStringContainsString('getProduct($product_id)', $controller);
		self::assertStringNotContainsString('extension/module/slideshow', $controller);
		self::assertStringContainsString('{% if hero_product %}', $template);
		self::assertStringContainsString('{{ hero_product.href|e }}', $template);
		self::assertStringContainsString('{{ hero_product.name|e }}', $template);
		self::assertStringContainsString('class="sylora-hero__fallback"', $template);
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

	public function testHeroMediaUsesResponsiveProductAndCollectionFrames(): void {
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/extension/module/slideshow.twig');

		self::assertStringContainsString('.sylora-hero__product picture { aspect-ratio: 572 / 460; }', $css);
		self::assertStringContainsString('.sylora-hero__product picture { aspect-ratio: 1; }', $css);
		self::assertStringContainsString('.sylora-hero__media > picture { aspect-ratio: 572 / 420; }', $css);
		self::assertStringContainsString('.sylora-hero__media > picture { aspect-ratio: 338 / 225; }', $css);
		self::assertStringContainsString("grid-template-areas: 'content' 'actions' 'media';", $css);
		self::assertStringContainsString('object-fit: cover;', $css);
		self::assertStringContainsString('#common-home .sylora-actions { margin-top: 4px; }', $css);
		self::assertStringContainsString('#common-home .sylora-hero + .sylora-section { padding-top: 44px; }', $css);
		self::assertStringContainsString('#common-home .sylora-hero__media { grid-column: 2; grid-row: 1; width: 100%; max-width: 604px; justify-self: end; }', $css);
		self::assertStringContainsString('class="sylora-hero__copy"', $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/home.twig'));
		self::assertStringContainsString('#common-home .sylora-hero__copy { display: contents; }', $css);
		self::assertStringContainsString('data-slideshow', $template);
		self::assertStringContainsString('{{ banner.link }}', $template);
	}

	/**
	 * @return iterable<string, array{list<string>, ?string}>
	 */
	public static function layoutSlotsProvider(): iterable {
		yield 'empty' => array(array(), null);
		yield 'top' => array(array('content_top'), 'col-sm-12');
		yield 'bottom' => array(array('content_bottom'), 'col-sm-12');
		yield 'left' => array(array('column_left'), 'col-sm-9');
		yield 'right' => array(array('column_right'), 'col-sm-9');
		yield 'all slots' => array(array('column_left', 'content_top', 'content_bottom', 'column_right'), 'col-sm-6');
	}

	/**
	 * @param list<string> $slots
	 */
	#[DataProvider('layoutSlotsProvider')]
	public function testHomeRendersLayoutOnlyWhenASlotHasContent(array $slots, ?string $expectedClass): void {
		$twig = new Environment(
			new FilesystemLoader($this->root . '/upload/catalog/view/theme/charm_by_sylora/template'),
			array('autoescape' => false)
		);
		$data = array(
			'column_left' => '',
			'content_top' => '',
			'content_bottom' => '',
			'column_right' => '',
			'footer' => '<footer>Footer fixture</footer>',
		);

		foreach ($slots as $slot) {
			$data[$slot] = '<div data-fixture-slot="' . $slot . '"></div>';
		}

		$html = $twig->render('common/home.twig', $data);
		$page = HTMLDocument::createFromString('<!doctype html>' . $html, overrideEncoding: 'UTF-8');

		self::assertSame('Авторские украшения ручной работы', $page->querySelector('#common-home .sylora-seo h2')?->textContent);
		self::assertStringContainsString('В интернет-магазине Charm by Sylora', $page->querySelector('#common-home .sylora-seo p')?->textContent ?? '');
		self::assertNotNull($page->querySelector('#common-home + footer'));

		if ($expectedClass === null) {
			self::assertNull($page->querySelector('#common-home .sylora-seo + *'));
			self::assertNull($page->getElementById('content'));

			return;
		}

		self::assertSame($expectedClass, $page->querySelector('#common-home .sylora-seo + .container > .row > #content')?->className);
		$renderedSlots = array();

		foreach ($page->querySelectorAll('#common-home [data-fixture-slot]') as $node) {
			$slot = $node->getAttribute('data-fixture-slot');
			$parent = str_starts_with($slot ?? '', 'column_') ? '.row' : '#content';
			self::assertTrue($node->matches($parent . ' > [data-fixture-slot]'));
			$renderedSlots[] = $slot;
		}

		self::assertSame($slots, $renderedSlots);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
