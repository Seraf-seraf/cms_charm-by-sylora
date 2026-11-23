<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/BrowserTestCase.php';

final class CheckoutSummaryBrowserTest extends BrowserTestCase {
	public function testSummaryRecoversAndHidesStaleDeliveryTotals(): void {
		$result = $this->runBrowserScenario(__DIR__ . '/support/checkout_summary_browser.mjs', 'summary');

		self::assertTrue($result['failed'] ?? false);
		self::assertTrue($result['retried'] ?? false);
		self::assertTrue($result['delayed'] ?? false);
		self::assertTrue($result['pending'] ?? false);
		self::assertTrue($result['refreshed'] ?? false);
	}
}
