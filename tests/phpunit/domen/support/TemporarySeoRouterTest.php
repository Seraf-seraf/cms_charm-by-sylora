<?php

declare(strict_types=1);

require_once __DIR__ . '/BrowserTestCase.php';

final class TemporarySeoRouterTest extends BrowserTestCase {
	private const SEO_PRODUCT_PATH = '/earrings-pink-dawn';
	private const PRODUCT_NAME = 'Серьги Розовый рассвет';

	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname(__DIR__, 4);
	}

	public function testSeoRoutesQueryRoutesAndStaticSymlinkAssets(): void {
		$baseUrl = $this->baseUrl();
		$queryRoute = $this->request($this->getRouteUrl('product/product', array('product_id' => 1)));
		$seoProduct = $this->request($baseUrl . self::SEO_PRODUCT_PATH);
		$queryRouteOnSeoPath = $this->request($baseUrl . self::SEO_PRODUCT_PATH . '?route=product%2Fsearch&search=' . rawurlencode('Розовый рассвет'));
		$unknownSeoPath = $this->request($baseUrl . '/missing-seo-product-' . bin2hex(random_bytes(8)));
		$catalogAssetPath = '/catalog/view/javascript/jquery/jquery-3.7.1.min.js';
		$catalogAsset = $this->request($baseUrl . $catalogAssetPath);
		$imageAsset = $this->request($baseUrl . '/image/placeholder.png');
		$expectedCatalogAsset = file_get_contents($this->root . '/upload' . $catalogAssetPath);
		$expectedImageAsset = file_get_contents($this->root . '/upload/image/placeholder.png');

		self::assertSame(200, $queryRoute['status']);
		self::assertStringContainsString('id="product-product"', $queryRoute['body']);
		self::assertStringContainsString('data-product-id="1"', $queryRoute['body']);

		self::assertSame(200, $seoProduct['status']);
		self::assertStringContainsString('id="product-product"', $seoProduct['body']);
		self::assertStringContainsString(self::PRODUCT_NAME, $seoProduct['body']);

		self::assertSame(200, $queryRouteOnSeoPath['status']);
		self::assertStringContainsString('id="product-search"', $queryRouteOnSeoPath['body']);
		self::assertStringNotContainsString('id="product-product"', $queryRouteOnSeoPath['body']);

		self::assertSame(404, $unknownSeoPath['status']);
		self::assertStringContainsString('id="error-not-found"', $unknownSeoPath['body']);
		self::assertStringContainsString('Запрошенная Вами страница не найдена', $unknownSeoPath['body']);

		self::assertSame(200, $catalogAsset['status']);
		self::assertSame($expectedCatalogAsset, $catalogAsset['body']);
		self::assertSame(200, $imageAsset['status']);
		self::assertSame($expectedImageAsset, $imageAsset['body']);
	}

	private function baseUrl(): string {
		$url = parse_url($this->getRouteUrl('common/home'));

		if (!is_array($url) || !isset($url['scheme'], $url['host'], $url['port'])) {
			self::fail('Изолированный URL storefront содержит неполный origin.');
		}

		return $url['scheme'] . '://' . $url['host'] . ':' . $url['port'];
	}

	/**
	 * @return array{status: int, body: string}
	 */
	private function request(string $url): array {
		$context = stream_context_create(array(
			'http' => array(
				'timeout' => 10,
				'ignore_errors' => true,
			),
		));
		$body = file_get_contents($url, false, $context);
		$statusLine = $http_response_header[0] ?? '';

		if (!is_string($body) || preg_match('/^HTTP\/\S+ (\d{3})\b/', $statusLine, $matches) !== 1) {
			self::fail('Изолированный storefront вернул некорректный HTTP-ответ для ' . $url . '.');
		}

		return array(
			'status' => (int)$matches[1],
			'body' => $body,
		);
	}
}
