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

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
