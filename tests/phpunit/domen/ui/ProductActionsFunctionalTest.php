<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/BrowserTestCase.php';

final class ProductActionsFunctionalTest extends BrowserTestCase {
	private const SEARCH_TERM = 'Розовый рассвет';
	private const SYNTHETIC_PRODUCT_NAME = 'Серьги Розовый рассвет';
	private const QUANTITY_THREE = 3;

	public function testHeaderSearchSubmitsTextAndFindsSyntheticProduct(): void {
		$result = $this->runBrowserScenario(__DIR__ . '/support/product_actions_browser.mjs', 'search');

		self::assertSame(self::SEARCH_TERM, $result['search'] ?? null);
		self::assertTrue($result['searchOpened'] ?? false);
		self::assertSame(1, $result['matchCount'] ?? null);
		self::assertSame(self::SYNTHETIC_PRODUCT_NAME, $result['productName'] ?? null);
		self::assertNotSame('', $result['productId'] ?? '');
		self::assertStringEndsWith('/search.svg', $result['searchIcon'] ?? '');
		self::assertNotSame('', $result['productHref'] ?? '');
	}

	public function testProductPageAddToCartAppearsInCart(): void {
		$result = $this->runBrowserScenario(__DIR__ . '/support/product_actions_browser.mjs', 'add-to-cart');

		$this->assertCartResult($result, 1);
	}

	public function testProductPageQuantityThreeIsReflectedInCartTotal(): void {
		$result = $this->runBrowserScenario(__DIR__ . '/support/product_actions_browser.mjs', 'quantity-three');

		$this->assertCartResult($result, self::QUANTITY_THREE);
	}

	public function testProductPageWishlistUsesHeartControlAndShowsSavedState(): void {
		$result = $this->runBrowserScenario(__DIR__ . '/support/product_actions_browser.mjs', 'wishlist');

		self::assertTrue($result['pressed'] ?? false);
		self::assertTrue($result['noticeVisible'] ?? false);
		self::assertSame($result['productId'] ?? null, $result['buttonProductId'] ?? null);
		self::assertSame(self::SYNTHETIC_PRODUCT_NAME, $result['productName'] ?? null);
		self::assertStringEndsWith('/heart.svg', $result['heartIcon'] ?? '');
	}

	public function testProductBreadcrumbsFollowCategoryAndHandleMissingOrInvalidPath(): void {
		$result = $this->runBrowserScenario(__DIR__ . '/support/product_actions_browser.mjs', 'breadcrumbs');

		self::assertSame('1', $result['productId'] ?? null);
		self::assertSame('1', $result['selectedPath'] ?? null);
		self::assertNotSame('', $result['categoryName'] ?? '');
		self::assertSame(array('Каталог', $result['categoryName'] ?? ''), $result['breadcrumbs'] ?? null);
		self::assertTrue($result['categorySelectionPreserved'] ?? false);
		self::assertFalse($result['hasPhpError'] ?? true);

		self::assertSame(200, $result['withoutPathStatus'] ?? null);
		self::assertSame('1', $result['withoutPathProductId'] ?? null);
		self::assertSame(array('Каталог'), $result['withoutPathBreadcrumbs'] ?? null);
		self::assertFalse($result['withoutPathHasPhpError'] ?? true);

		self::assertSame(200, $result['invalidScalarPathStatus'] ?? null);
		self::assertSame(array('Каталог'), $result['invalidScalarPathBreadcrumbs'] ?? null);
		self::assertFalse($result['invalidScalarPathHasPhpError'] ?? true);

		self::assertSame(404, $result['invalidArrayPathStatus'] ?? null);
		self::assertFalse($result['invalidArrayPathHasPhpError'] ?? true);
	}

	public function testCompareClientActionShowsSyntheticProductOnComparePage(): void {
		// Showcase fixtures have no manufacturer, so exercise the existing compare client API and verify its rendered result.
		$result = $this->runBrowserScenario(__DIR__ . '/support/product_actions_browser.mjs', 'compare');

		self::assertTrue($result['notificationVisible'] ?? false);
		self::assertSame($result['productId'] ?? null, $result['comparedProductId'] ?? null);
		self::assertSame(self::SYNTHETIC_PRODUCT_NAME, $result['expectedProductName'] ?? null);
		self::assertSame(self::SYNTHETIC_PRODUCT_NAME, $result['comparedProductName'] ?? null);
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function assertCartResult(array $result, int $quantity): void {
		self::assertTrue($result['noticeVisible'] ?? false);
		self::assertSame($result['expectedProductId'] ?? null, $result['productId'] ?? null);
		self::assertSame(self::SYNTHETIC_PRODUCT_NAME, $result['expectedProductName'] ?? null);
		self::assertSame(self::SYNTHETIC_PRODUCT_NAME, $result['name'] ?? null);
		self::assertSame((string)$quantity, $result['quantity'] ?? null);
		self::assertMatchesRegularExpression('/^\d+$/', $result['unitPriceDigits'] ?? '');
		self::assertMatchesRegularExpression('/^\d+$/', $result['lineTotalDigits'] ?? '');
		self::assertSame(
			(int)$result['unitPriceDigits'] * $quantity,
			(int)$result['lineTotalDigits']
		);
	}
}
