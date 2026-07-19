<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/BrowserTestCase.php';
require_once __DIR__ . '/support/CookieConsentBrowserResult.php';

final class CookieConsentTest extends BrowserTestCase {
	public function testMetricaIsNotRequestedBeforeConsent(): void {
		$result = CookieConsentBrowserResult::fromArray($this->runBrowserScenario(
			__DIR__ . '/support/cookie_consent_browser.mjs',
			'initial'
		));

		self::assertFalse($result->bannerHidden);
		self::assertFalse($result->dataLayerExists);
		self::assertSame(0, $result->metricaRequestCount);
		self::assertSame(0, $result->ymCookieCount);
		self::assertSame('', $result->consent);
	}

	public function testMetricaLoadsAfterExplicitConsent(): void {
		$result = CookieConsentBrowserResult::fromArray($this->runBrowserScenario(
			__DIR__ . '/support/cookie_consent_browser.mjs',
			'accept'
		));

		self::assertTrue($result->bannerHidden);
		self::assertTrue($result->dataLayerExists);
		self::assertSame('v1:analytics', $result->consent);
		self::assertGreaterThan(0, $result->metricaRequestCount);
		self::assertGreaterThan(0, $result->metricaScriptCount);
	}

	public function testRefusalRemovesMetricaCookiesAndPersists(): void {
		$result = CookieConsentBrowserResult::fromArray($this->runBrowserScenario(
			__DIR__ . '/support/cookie_consent_browser.mjs',
			'refuse'
		));

		self::assertTrue($result->bannerHidden);
		self::assertSame('v1:essential', $result->consent);
		self::assertSame(0, $result->ymCookieCount);
		self::assertSame(0, $result->metricaRequestAfterRefusalCount);
	}
}
