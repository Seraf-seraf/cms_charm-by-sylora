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

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
