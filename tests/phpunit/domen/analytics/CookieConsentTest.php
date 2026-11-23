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
		self::assertSame('Файлы cookie', $result->bannerTitle);
		self::assertSame('Необходимые файлы помогают работе магазина. С вашего согласия мы используем аналитические cookie, чтобы улучшать сайт.', $result->bannerDescription);
		self::assertSame('Политика конфиденциальности', $result->privacyText);
		self::assertTrue($result->privacyInOwnParagraph);
		self::assertSame('Принять все', $result->acceptText);
		self::assertSame('Только необходимые', $result->rejectText);
		self::assertSame('Настройки аналитики', $result->settingsText);
		self::assertTrue($result->settingsInSiteFooter);
		self::assertFalse($result->settingsInCatalogCard);
		self::assertSame(1, $result->extensionScriptCount);
		self::assertFalse($result->dataLayerExists);
		self::assertFalse($result->metricaTagMockLoaded);
		self::assertSame(0, $result->metricaRequestCount);
		self::assertSame(0, $result->blockedExternalRequestCount);
		self::assertSame(0, $result->observedExternalRequestCount);
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
		self::assertTrue($result->metricaTagMockLoaded);
		self::assertSame('v1:analytics', $result->consent);
		self::assertSame(1, $result->metricaRequestCount);
		self::assertSame(0, $result->blockedExternalRequestCount);
		self::assertSame(0, $result->observedExternalRequestCount);
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
		self::assertSame(0, $result->blockedExternalRequestCount);
		self::assertSame(0, $result->observedExternalRequestCount);
	}

	public function testReopeningSettingsKeepsSavedChoicesAndCopy(): void {
		foreach (array(
			array('settings-analytics', 'v1:analytics', true, 1),
			array('settings-essential', 'v1:essential', false, 0)
		) as $scenario) {
			$result = CookieConsentBrowserResult::fromArray($this->runBrowserScenario(
				__DIR__ . '/support/cookie_consent_browser.mjs',
				$scenario[0]
			));

			self::assertFalse($result->bannerHidden);
			self::assertSame('Настройки cookie', $result->bannerTitle);
			self::assertSame('Вы можете изменить выбор в любой момент. Аналитические cookie используются только с вашего согласия.', $result->bannerDescription);
			self::assertSame('Политика конфиденциальности', $result->privacyText);
			self::assertTrue($result->privacyInOwnParagraph);
			self::assertSame($scenario[1], $result->consent);
			self::assertSame($scenario[2], $result->dataLayerExists);
			self::assertSame($scenario[2], $result->metricaTagMockLoaded);
			self::assertSame($scenario[3], $result->metricaRequestCount);
			self::assertSame(0, $result->ymCookieCount);
			self::assertSame(0, $result->blockedExternalRequestCount);
			self::assertSame(0, $result->observedExternalRequestCount);
		}
	}
}
