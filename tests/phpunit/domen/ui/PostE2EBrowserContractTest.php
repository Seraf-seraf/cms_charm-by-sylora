<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/BrowserTestCase.php';

final class PostE2EBrowserContractTest extends BrowserTestCase {
	public function testReversePriceRangeIsNormalizedWithoutJavaScript(): void {
		$catalogUrl = $this->catalogUrl();
		$html = $this->request($catalogUrl . (str_contains($catalogUrl, '?') ? '&' : '?') . 'price_min=900&price_max=100');
		$xpath = new DOMXPath($this->document($html));

		self::assertSame('100', self::attribute($xpath, '//input[@name="price_min"]', 'value'));
		self::assertSame('900', self::attribute($xpath, '//input[@name="price_max"]', 'value'));

		$canonical = html_entity_decode(self::attribute($xpath, '//link[@rel="canonical"]', 'href'));
		self::assertStringContainsString('price_min=100', $canonical);
		self::assertStringContainsString('price_max=900', $canonical);
		self::assertStringNotContainsString('price_min=900', $canonical);
	}

	public function testInvalidAndNegativePriceRulesRemainUnchanged(): void {
		$catalogUrl = $this->catalogUrl();
		$html = $this->request($catalogUrl . (str_contains($catalogUrl, '?') ? '&' : '?') . 'price_min=-10&price_max=invalid');
		$xpath = new DOMXPath($this->document($html));

		self::assertSame('0', self::attribute($xpath, '//input[@name="price_min"]', 'value'));
		self::assertSame('0', self::attribute($xpath, '//input[@name="price_max"]', 'value'));
	}

	public function testSitemapHasCanonicalAndNotFoundDoesNot(): void {
		$sitemap = new DOMXPath($this->document($this->request($this->getRouteUrl('information/sitemap'))));
		self::assertNotSame('', self::attribute($sitemap, '//link[@rel="canonical"]', 'href'));

		$headers = array();
		$notFoundHtml = $this->request($this->getRouteUrl('error/not_found'), $headers);
		$notFound = new DOMXPath($this->document($notFoundHtml));
		$status = $headers[0] ?? '';

		self::assertIsString($status);
		self::assertStringContainsString('404', $status);
		self::assertSame(0, $notFound->query('//link[@rel="canonical"]')->length);
		self::assertSame('noindex, nofollow', self::attribute($notFound, '//meta[@name="robots"]', 'content'));
	}

	private function catalogUrl(): string {
		$search = new DOMXPath($this->document($this->request($this->getRouteUrl('product/search'))));
		$categoryId = self::attribute($search, '//select[@name="category_id"]/option[@value != "0"]', 'value');

		return $this->getRouteUrl('product/category', array('path' => $categoryId));
	}

	private function request(string $url, ?array &$headers = null): string {
		$context = stream_context_create(array('http' => array('timeout' => 10, 'ignore_errors' => true)));
		$content = file_get_contents($url, false, $context);
		$headers = $http_response_header ?? array();

		self::assertIsString($content, $url);

		return $content;
	}

	private function document(string $html): DOMDocument {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		self::assertTrue($loaded);

		return $document;
	}

	private function attribute(DOMXPath $xpath, string $query, string $attribute): string {
		$nodes = $xpath->query($query);
		$node = $nodes instanceof DOMNodeList ? $nodes->item(0) : null;

		self::assertInstanceOf(DOMElement::class, $node, $query);

		return $node->getAttribute($attribute);
	}
}
