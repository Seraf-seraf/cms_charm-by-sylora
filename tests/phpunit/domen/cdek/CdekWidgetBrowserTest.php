<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/BrowserTestCase.php';

final class CdekWidgetBrowserTest extends BrowserTestCase {
	public function testPickupWidgetFailureCanBeRetriedAndSelected(): void {
		$result = $this->runBrowserScenario(__DIR__ . '/support/cdek_widget_browser.mjs', 'retry');

		self::assertTrue($result['networkPolicy']['enforced'] ?? false, 'CDP must intercept requests before the local page is navigated.');
		self::assertSame(0, $result['networkPolicy']['externalHttpRequestCount'] ?? null, 'External HTTP(S) attempts must be blocked and fail this test.');
		self::assertSame(array(), $result['networkPolicy']['externalHttpRequests'] ?? null);
		self::assertSame(0, $result['networkPolicy']['forbiddenRequestCount'] ?? null);
		self::assertSame(array(), $result['networkPolicy']['forbiddenRequests'] ?? null);
		self::assertSame(array(), $result['networkPolicy']['interceptionErrors'] ?? null, 'CDP interception must not fail open.');

		self::assertSame(1, $result['failed']['attempts'] ?? null);
		self::assertTrue($result['failed']['visible'] ?? false);
		self::assertSame('Повторить', $result['failed']['retry'] ?? null);
		self::assertFalse($result['failed']['nested'] ?? true);
		self::assertSame(2, $result['attempts'] ?? null);
		self::assertTrue($result['opened'] ?? false);
		self::assertSame('[TEST-1] Тестовая улица 1', $result['choice'] ?? null);
		self::assertSame('Изменить пункт выдачи', $result['change'] ?? null);
		self::assertTrue($result['officeInCard'] ?? false);
		self::assertFalse($result['courierHasButton'] ?? true);
	}
}
