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
			'/@media \(max-width: 1200px\).*?\.mobile-nav-toggle\s*\{\s*display: inline-flex;/s',
			$stylesheet
		);
	}

	public function testCompactMenuHasSeparateAccessibleOpenAndCloseControls(): void {
		$header = file_get_contents(
			dirname(__DIR__, 4) . '/upload/catalog/view/theme/charm_by_sylora/template/common/header.twig'
		);

		self::assertIsString($header);
		self::assertStringContainsString('aria-label="Открыть меню" aria-expanded="false" aria-controls="site-nav"', $header);
		self::assertMatchesRegularExpression(
			'/<nav class="site-nav" id="site-nav"[^>]*>.*?<button class="site-nav__close popup-close" type="button" aria-label="Закрыть меню">.*?<\/nav>/s',
			$header
		);
	}

	public function testDesktopHeaderMatchesFigmaActionsAndKeepsSearchAvailable(): void {
		$header = file_get_contents(
			dirname(__DIR__, 4) . '/upload/catalog/view/theme/charm_by_sylora/template/common/header.twig'
		);
		$searchTemplate = file_get_contents(
			dirname(__DIR__, 4) . '/upload/catalog/view/theme/charm_by_sylora/template/common/search.twig'
		);
		$stylesheet = file_get_contents(
			dirname(__DIR__, 4) . '/upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css'
		);
		$script = file_get_contents(
			dirname(__DIR__, 4) . '/upload/catalog/view/javascript/storefront.js'
		);

		self::assertIsString($header);
		self::assertIsString($searchTemplate);
		self::assertIsString($stylesheet);
		self::assertIsString($script);
		self::assertStringContainsString('class="site-nav__cart" href="{{ shopping_cart }}"', $header);
		self::assertStringContainsString('aria-expanded="false"', $searchTemplate);
		self::assertMatchesRegularExpression('/@media \(min-width: 1201px\).*?min-height: 174px;.*?\.site-nav__cart \{ display: none; \}/s', $stylesheet);
		self::assertStringContainsString("var desktopSearch = window.matchMedia('(min-width: 1201px)');", $script);
		self::assertStringContainsString("button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');", $script);
		self::assertStringContainsString('initializeHeaderSearch();', $script);
	}
}
