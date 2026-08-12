<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testDatetimePickerIsRenderedOnlyForMatchingProductOptions(): void {
		$controller = $this->read('upload/catalog/controller/product/product.php');
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/product/product.twig');

		self::assertStringContainsString('$data[\'has_datetime_option\'] = $has_datetime_option;', $controller);
		self::assertStringContainsString('{% if has_datetime_option %}', $template);
		self::assertSame(1, substr_count($template, "$('.date').datetimepicker({"));
		self::assertStringContainsString("$('.time').datetimepicker({", $template);
		self::assertMatchesRegularExpression(
			'/\{% if has_datetime_option %\}.*?\$\(\'\.date\'\)\.datetimepicker.*?\$\(\'\.time\'\)\.datetimepicker.*?\{% endif %\}/s',
			$template
		);
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

	public function testMainProductImageFitsViewportWithoutThumbnailUpscaling(): void {
		$controller = $this->read('upload/catalog/controller/product/product.php');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');

		self::assertStringContainsString('private const GALLERY_IMAGE_SIZE = 1200;', $controller);
		self::assertStringContainsString('resizeWithSources($image_filename, $gallery_width, $gallery_height)', $controller);
		self::assertMatchesRegularExpression(
			'/\.product-page__media\s*\{[^}]*max-width: 720px;/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.product-gallery__main \.thumbnail\s*\{[^}]*height: min\(720px, calc\(100vh - 140px\)\);/s',
			$css
		);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
