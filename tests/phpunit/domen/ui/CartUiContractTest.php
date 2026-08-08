<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CartUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testInteractiveCartControlsHaveMinimumTouchTarget(): void {
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$header = $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/header.twig');

		self::assertMatchesRegularExpression(
			'/\.cart-page \.is-icon-only\s*\{[^}]*width: 44px;[^}]*min-width: 44px;[^}]*height: 44px;[^}]*min-height: 44px;/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.cart-modules \.accordion-toggle\s*\{[^}]*display: flex;[^}]*min-height: 44px;/s',
			$css
		);
		self::assertStringContainsString('stylesheet.min.css?v=20260808-cart-targets-v1', $header);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
