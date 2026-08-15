<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HeaderUiContractTest extends TestCase {
	public function testMobileMenuToggleIsHiddenByDefaultAndShownInCompactLayout(): void {
		$stylesheet = file_get_contents(
			dirname(__DIR__, 4) . '/upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css'
		);

		self::assertIsString($stylesheet);
		self::assertMatchesRegularExpression('/\.mobile-nav-toggle\s*\{\s*display: none;/s', $stylesheet);
		self::assertMatchesRegularExpression(
			'/\.site-header__inner\.is-responsive-overflowing \.mobile-nav-toggle\s*\{\s*display: inline-flex;/s',
			$stylesheet
		);
		self::assertMatchesRegularExpression(
			'/@media \(max-width: 1050px\).*?\.mobile-nav-toggle\s*\{\s*display: inline-flex;/s',
			$stylesheet
		);
	}

	public function testMobileMenuToggleSynchronizesVisibleState(): void {
		$script = file_get_contents(
			dirname(__DIR__, 4) . '/upload/catalog/view/javascript/storefront.js'
		);

		self::assertIsString($script);
		self::assertStringContainsString("navButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');", $script);
		self::assertStringContainsString("isOpen ? 'Закрыть меню' : 'Открыть меню'", $script);
		self::assertStringContainsString("isOpen ? 'fa-times' : 'fa-bars'", $script);
		self::assertStringContainsString('syncNavButton(false);', $script);
		self::assertStringContainsString('syncNavButton(isOpen);', $script);
	}
}
