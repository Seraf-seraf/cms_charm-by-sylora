<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SitemapFeedContractTest extends TestCase {
	public function testUrlsAreDecodedBeforeXmlEscaping(): void {
		$controller = file_get_contents(
			dirname(__DIR__, 4) . '/upload/catalog/controller/extension/feed/google_sitemap.php'
		);

		self::assertIsString($controller);
		self::assertMatchesRegularExpression(
			'/private function escapeXml\(\$value\).*?html_entity_decode\(\$value, ENT_XML1 \| ENT_QUOTES, \'UTF-8\'\).*?htmlspecialchars\(\$decoded_value, ENT_XML1 \| ENT_QUOTES, \'UTF-8\'\)/s',
			$controller
		);
		self::assertStringNotContainsString('htmlspecialchars($value, ENT_XML1 | ENT_QUOTES', $controller);
	}
}
