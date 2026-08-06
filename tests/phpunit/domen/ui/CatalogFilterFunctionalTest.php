<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/BrowserTestCase.php';

final class CatalogFilterFunctionalTest extends BrowserTestCase {
	public function testHttpSearchRouteFiltersRealCatalogData(): void {
		$unfilteredHtml = $this->request($this->getRouteUrl('product/search', array('limit' => 100)));
		$unfilteredProducts = $this->productLinks($unfilteredHtml);
		self::assertNotEmpty($unfilteredProducts, 'Тестовый каталог не содержит товаров.');

		$candidate = $this->findCategorySubset($unfilteredHtml, $unfilteredProducts);

		if ($candidate === null) {
			self::fail('Не найдена категория с непустым строгим подмножеством товаров.');
		}

		$filteredHtml = $this->request($this->getRouteUrl('product/search', array('category_id' => $candidate['category_id'], 'limit' => 100)));
		self::assertSame($candidate['products'], $this->productLinks($filteredHtml));
		self::assertSame($candidate['category_id'], $this->selectedCategory($filteredHtml));
	}

	public function testBrowserFormFiltersRealCatalogData(): void {
		$result = $this->runBrowserScenario(__DIR__ . '/support/catalog_regression_browser.mjs', 'filter');

		self::assertTrue($result['submitted'] ?? false);
		self::assertSame($result['expectedCategory'] ?? null, $result['selectedCategory'] ?? null);
		self::assertSame($result['expectedCategory'] ?? null, $result['urlCategory'] ?? null);
		self::assertSame($result['expectedProducts'] ?? null, $result['actualProducts'] ?? null);
	}

	public function testRealPagesKeepImageAndAlignmentContracts(): void {
		$result = $this->runBrowserScenario(__DIR__ . '/support/catalog_regression_browser.mjs', 'visual');

		self::assertTrue($result['hero']['equalFrames'] ?? false);
		self::assertSame(array('cover'), $result['hero']['objectFits'] ?? null);
		self::assertSame('none', $result['hero']['beforeContent'] ?? null);
		self::assertSame('none', $result['hero']['afterContent'] ?? null);
		self::assertGreaterThanOrEqual(0.9, $result['product']['widthOccupancy'] ?? 0);
		self::assertSame('contain', $result['product']['objectFit'] ?? null);
		self::assertTrue($result['product']['metaLabelSingleLine'] ?? false);
		self::assertTrue($result['catalog']['equalMedia'] ?? false);
		self::assertSame(array('contain'), $result['catalog']['objectFits'] ?? null);
		self::assertTrue($result['catalog']['actionsAligned'] ?? false);
	}

	private function request(string $url): string {
		$context = stream_context_create(array('http' => array('timeout' => 10, 'ignore_errors' => true)));
		$content = file_get_contents($url, false, $context);

		self::assertIsString($content, 'HTTP route не вернул HTML: ' . $url);
		return $content;
	}

	/**
	 * @param list<string> $unfilteredProducts
	 * @return array{category_id: string, products: list<string>}|null
	 */
	private function findCategorySubset(string $html, array $unfilteredProducts): ?array {
		$xpath = new DOMXPath($this->document($html));
		$options = $xpath->query('//select[@name="category_id"]/option[@value != "0"]');
		$unfilteredKeys = array_map(array($this, 'productKey'), $unfilteredProducts);

		if (!$options instanceof DOMNodeList) {
			return null;
		}

		foreach ($options as $option) {
			if (!$option instanceof DOMElement) {
				continue;
			}

			$categoryId = $option->getAttribute('value');
			$products = $this->productLinks($this->request($this->getRouteUrl('product/search', array('category_id' => $categoryId, 'limit' => 100))));

			$productKeys = array_map(array($this, 'productKey'), $products);

			if ($products !== array() && count($products) < count($unfilteredProducts) && array_diff($productKeys, $unfilteredKeys) === array()) {
				return array('category_id' => $categoryId, 'products' => $products);
			}
		}

		return null;
	}

	/** @return list<string> */
	private function productLinks(string $html): array {
		$xpath = new DOMXPath($this->document($html));
		$nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " catalog-card__title ")]/a');
		$links = array();

		if ($nodes instanceof DOMNodeList) {
			foreach ($nodes as $node) {
				if ($node instanceof DOMElement) {
					$links[] = $node->getAttribute('href');
				}
			}
		}

		return $links;
	}

	private function productKey(string $href): string {
		$query = parse_url($href, PHP_URL_QUERY);
		$parameters = array();

		if (is_string($query)) {
			parse_str($query, $parameters);
		}

		if (isset($parameters['product_id']) && is_scalar($parameters['product_id'])) {
			return 'product:' . (string)$parameters['product_id'];
		}

		$path = parse_url($href, PHP_URL_PATH);

		return is_string($path) ? $path : $href;
	}

	private function selectedCategory(string $html): string {
		$xpath = new DOMXPath($this->document($html));
		$nodes = $xpath->query('//select[@name="category_id"]/option[@selected]');
		$node = $nodes instanceof DOMNodeList ? $nodes->item(0) : null;

		return $node instanceof DOMElement ? $node->getAttribute('value') : '';
	}

	private function document(string $html): DOMDocument {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		self::assertTrue($loaded, 'Получен некорректный HTML.');

		return $document;
	}
}
