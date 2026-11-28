<?php

declare(strict_types=1);

namespace CartTests;

require_once dirname(__DIR__) . '/support/BrowserTestCase.php';

final class CartCountryZoneBrowserTest extends \BrowserTestCase {
	public function testSelectingCountryRendersZoneWithoutReferenceError(): void {
		$result = $this->runBrowserScenario(__DIR__ . '/support/cart_country_zone_browser.mjs', 'country-zone');

		self::assertSame(array('', '9901'), $result['countryRequests'] ?? null);
		self::assertSame('9901', $result['selectedCountry'] ?? null);
		self::assertSame('9901', $result['selectedZone'] ?? null);
		self::assertSame('No regions available', $result['emptyCountryZoneText'] ?? null);
		self::assertSame('Synthetic Audit Region', $result['selectedZoneName'] ?? null);
		self::assertTrue($result['countryEnabled'] ?? false);
		self::assertTrue($result['postcodeRequired'] ?? false);
		self::assertSame(array(), $result['cartScriptErrors'] ?? null);
	}
}
