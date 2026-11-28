<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testDatetimePickerUsesExternalScriptAndMatchingSelectors(): void {
		$controller = $this->read('upload/catalog/controller/product/product.php');
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/product/product.twig');
		$script = $this->read('upload/catalog/view/javascript/product.js');

		self::assertStringContainsString('$data[\'has_datetime_option\'] = $has_datetime_option;', $controller);
		self::assertStringContainsString('data-datepicker="{{ datepicker }}"', $template);
		self::assertStringContainsString('<script src="catalog/view/javascript/product.js?', $template);
		self::assertStringNotContainsString('datetimepicker({', $template);
		self::assertSame(1, substr_count($script, "$('.date').datetimepicker({"));
		self::assertStringContainsString("$('.datetime').datetimepicker({", $script);
		self::assertStringContainsString("$('.time').datetimepicker({", $script);
	}

	public function testReviewRatingUsesVisibleThemeColor(): void {
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$header = $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/header.twig');

		self::assertMatchesRegularExpression(
			'/\.product-review__rating-option\s*\{[^}]*color: var\(--color-muted\);/s',
			$css
		);
		self::assertDoesNotMatchRegularExpression(
			'/\.product-review__rating-option\s*\{[^}]*color: var\(--color-border\);/s',
			$css
		);
		self::assertMatchesRegularExpression('/stylesheet\.min\.css\?v=[^"\s]+/', $header);
	}

	public function testMainProductImageUsesResponsiveGallerySource(): void {
		$controller = $this->read('upload/catalog/controller/product/product.php');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');

		self::assertStringContainsString('private const GALLERY_IMAGE_SIZE = 1200;', $controller);
		self::assertStringContainsString('resizeWithSources($image_filename, $gallery_width, $gallery_height)', $controller);
		self::assertMatchesRegularExpression(
			'/\.product-page__media,[^{]+\{[^}]*min-width: 0;/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.product-page \.thumbnail img\s*\{[^}]*object-fit: contain;/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/@media \(max-width: 767px\).*?#product-product \.product-gallery__main \.thumbnail\s*\{[^}]*aspect-ratio: 7 \/ 8;/s',
			$css
		);
	}

	public function testProductTitleHasNoExtraEyebrowAndWishlistActionRemainsAvailable(): void {
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/product/product.twig');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');

		self::assertStringContainsString('<h1>{{ heading_title }}</h1>', $template);
		self::assertStringNotContainsString('class="sylora-eyebrow">Украшение ручной работы</p>', $template);
		self::assertStringContainsString('data-wishlist-add="{{ product_id }}"', $template);
		self::assertStringNotContainsString('#product-product .product-page__summary > .sylora-eyebrow', $css);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
