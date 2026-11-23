<?php

declare(strict_types=1);

namespace CartTests;

require_once dirname(__DIR__) . '/support/BrowserTestCase.php';

final class CartQuantityLifecycleBrowserTest extends \BrowserTestCase {
	private const SYNTHETIC_PRODUCT_NAME = 'Серьги Розовый рассвет';
	private const SYNTHETIC_PRODUCT_MODEL = 'SYLORA-TEST-001';
	private const SYNTHETIC_SPECIAL_PRICE_MINOR = '210000';
	private const UPDATED_QUANTITY = '2';

	public function testSyntheticProductCanBeUpdatedAndRemovedFromCartPage(): void {
		$result = $this->runBrowserScenario(__DIR__ . '/support/cart_quantity_lifecycle_browser.mjs', 'cart-quantity-lifecycle');
		$initial = $result['initialCart'] ?? array();
		$updated = $result['updatedCart'] ?? array();
		$removed = $result['removedCart'] ?? array();

		self::assertTrue($result['addNoticeVisible'] ?? false);
		self::assertStringContainsString(self::SYNTHETIC_PRODUCT_NAME, $result['addNoticeText'] ?? '');
		self::assertSame(self::SYNTHETIC_PRODUCT_NAME, $result['productName'] ?? null);
		self::assertNotSame('', $result['productId'] ?? '');
		self::assertSame(self::SYNTHETIC_PRODUCT_NAME, $initial['name'] ?? null);
		self::assertSame(self::SYNTHETIC_PRODUCT_MODEL, $initial['model'] ?? null);
		self::assertSame($result['productId'] ?? null, $initial['productId'] ?? null);
		self::assertNotSame('', $initial['cartId'] ?? '');
		self::assertSame('1', $initial['quantity'] ?? null);
		self::assertSame(self::SYNTHETIC_SPECIAL_PRICE_MINOR, $initial['unitPriceMinor'] ?? null);
		self::assertSame(self::SYNTHETIC_SPECIAL_PRICE_MINOR, $initial['lineTotalMinor'] ?? null);

		self::assertSame(self::SYNTHETIC_PRODUCT_NAME, $updated['name'] ?? null);
		self::assertSame(self::SYNTHETIC_PRODUCT_MODEL, $updated['model'] ?? null);
		self::assertSame(self::UPDATED_QUANTITY, $updated['quantity'] ?? null);
		self::assertSame(self::SYNTHETIC_SPECIAL_PRICE_MINOR, $updated['unitPriceMinor'] ?? null);
		self::assertSame('420000', $updated['lineTotalMinor'] ?? null);
		self::assertTrue($updated['lineTotalMatchesQuantity'] ?? false);
		if (($updated['summaryTotalMinor'] ?? '') !== '') {
			self::assertSame($updated['lineTotalMinor'], $updated['summaryTotalMinor']);
		}

		self::assertTrue($removed['emptyStateVisible'] ?? false);
		self::assertSame(0, $removed['productCount'] ?? null);
		self::assertSame(0, $removed['voucherCount'] ?? null);
		self::assertSame('checkout/cart', $removed['route'] ?? null);
		self::assertSame(array(), $result['externalRequests'] ?? null);
		self::assertFalse($result['forbiddenCheckoutRouteVisited'] ?? true);
		self::assertTrue($result['cleanup']['browserProcessExited'] ?? false);
		self::assertTrue($result['cleanup']['profileRemoved'] ?? false);

		$mutations = $result['cartMutationRequests'] ?? array();
		self::assertCount(3, $mutations);
		self::assertSame(
			array('checkout/cart/add', 'checkout/cart/edit', 'checkout/cart/remove'),
			array_column($mutations, 'route')
		);
		self::assertSame(array('POST', 'POST', 'POST'), array_column($mutations, 'method'));
		self::assertSame($result['productId'] ?? null, $mutations[0]['productId'] ?? null);
		self::assertSame('1', $mutations[0]['quantity'] ?? null);
		self::assertSame('2', $mutations[1]['quantity'] ?? null);
		self::assertSame($initial['cartId'] ?? null, $mutations[1]['cartId'] ?? null);
		self::assertSame($initial['cartId'] ?? null, $mutations[2]['cartId'] ?? null);

		$widths = array(320, 390, 1440);
		foreach (array('cartCaptures', 'emptyCaptures') as $captureKey) {
			$captures = $result[$captureKey] ?? array();
			self::assertCount(count($widths), $captures);
			self::assertSame($widths, array_column($captures, 'viewportWidth'));

			foreach ($captures as $capture) {
				self::assertFalse($capture['horizontalOverflow'] ?? true);
				self::assertSame($captureKey === 'cartCaptures', $capture['productVisible'] ?? false);
				self::assertSame($captureKey === 'emptyCaptures', $capture['emptyStateVisible'] ?? false);

				$captureDirectory = getenv('SYLORA_CART_LIFECYCLE_CAPTURE_DIR');
				if (is_string($captureDirectory) && $captureDirectory !== '') {
					self::assertSame($capture['viewportWidth'] ?? null, $capture['screenshotWidth'] ?? null);
					self::assertFileExists($capture['screenshotPath'] ?? '');
					$dimensions = getimagesize($capture['screenshotPath']);
					self::assertIsArray($dimensions);
					self::assertSame($capture['viewportWidth'] ?? null, $dimensions[0] ?? null);
				}
			}
		}

		$captureDirectory = getenv('SYLORA_CART_LIFECYCLE_CAPTURE_DIR');
		if (is_string($captureDirectory) && $captureDirectory !== '') {
			self::assertSame($captureDirectory, $result['artifactDirectory'] ?? null);
			self::assertFileExists($result['reportPath'] ?? '');
		}
	}
}
