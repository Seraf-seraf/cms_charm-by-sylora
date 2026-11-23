<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/BrowserTestCase.php';

final class CheckoutBuyerBrowserTest extends BrowserTestCase {
	private const RUNNER = __DIR__ . '/support/checkout_buyer_browser.mjs';
	private const EMAIL = 'buyer-flow@example.invalid';

	public function testBuyerStartStateAndRegisterGuestLoginFlows(): void {
		$result = $this->runBrowserScenario(self::RUNNER, 'buyer');
		$screenshots = $result['screenshots'] ?? array();

		self::assertSame(array(320, 390, 1440), array_column($screenshots, 'viewportWidth'));
		self::assertSame(array(320, 390, 1440), array_column($screenshots, 'imageWidth'));
		self::assertCount(3, $screenshots);

		foreach ($screenshots as $screenshot) {
			self::assertIsString($screenshot['path'] ?? null);
			self::assertFileExists($screenshot['path']);
			self::assertGreaterThan(0, $screenshot['imageHeight'] ?? 0);
		}

		$initialStates = $result['initialStates'] ?? array();
		self::assertSame('Войти или продолжить без регистрации', $initialStates['390']['prompt'] ?? null);
		self::assertSame('Авторизация или продолжение как гость', $initialStates['1440']['prompt'] ?? null);
		self::assertSame('Покупатель, адрес, способ доставки, оплата и подтверждение.', $initialStates['390']['introText'] ?? null);
		self::assertSame('Укажите данные, выберите доставку и способ оплаты.', $initialStates['1440']['introText'] ?? null);
		self::assertSame('Стоимость доставки зависит от выбранного способа.', $initialStates['390']['summaryNoteText'] ?? null);
		self::assertSame('Проверьте данные перед подтверждением заказа.', $initialStates['1440']['summaryNoteText'] ?? null);
		self::assertSame('rgb(108, 97, 88)', $initialStates['390']['introColor'] ?? null);
		self::assertSame('rgb(108, 97, 88)', $initialStates['1440']['introColor'] ?? null);
		self::assertSame('rgb(108, 97, 88)', $initialStates['390']['summaryNoteColor'] ?? null);
		self::assertSame('rgb(108, 97, 88)', $initialStates['1440']['summaryNoteColor'] ?? null);
		self::assertSame('none', $initialStates['320']['buyerChevronDisplay'] ?? null);
		self::assertSame('none', $initialStates['390']['buyerChevronDisplay'] ?? null);
		self::assertSame('none', $initialStates['1440']['buyerChevronDisplay'] ?? null);
		self::assertTrue($initialStates['320']['buyerPanelExpanded'] ?? false);
		self::assertTrue($initialStates['390']['buyerPanelExpanded'] ?? false);
		self::assertTrue($initialStates['1440']['buyerPanelExpanded'] ?? false);
		self::assertFalse($initialStates['320']['accountPanelVisible'] ?? true);
		self::assertFalse($initialStates['390']['accountPanelVisible'] ?? true);
		self::assertFalse($initialStates['1440']['accountPanelVisible'] ?? true);
		self::assertTrue($initialStates['320']['emailVisible'] ?? false);
		self::assertTrue($initialStates['390']['emailVisible'] ?? false);
		self::assertFalse($initialStates['1440']['emailVisible'] ?? true);
		self::assertTrue($initialStates['390']['continueVisible'] ?? false);
		self::assertTrue($initialStates['1440']['continueVisible'] ?? false);
		self::assertFalse($initialStates['320']['horizontalOverflow'] ?? true);
		self::assertFalse($initialStates['390']['horizontalOverflow'] ?? true);
		self::assertFalse($initialStates['1440']['horizontalOverflow'] ?? true);
		self::assertGreaterThanOrEqual(($initialStates['320']['emailWidth'] ?? 0) - 2, $initialStates['320']['continueWidth'] ?? 0);
		self::assertGreaterThanOrEqual(($initialStates['390']['emailWidth'] ?? 0) - 2, $initialStates['390']['continueWidth'] ?? 0);

		self::assertTrue($result['loginLink']['passwordVisible'] ?? false);
		self::assertTrue($result['loginLink']['focusedPassword'] ?? false);
		self::assertSame(self::EMAIL, $result['registerFlow']['email'] ?? null);
		self::assertTrue($result['registerFlow']['registrationFieldsVisible'] ?? false);
		self::assertTrue($result['registerFlow']['accountPanelVisible'] ?? false);
		self::assertSame(self::EMAIL, $result['guestFlow']['email'] ?? null);
		self::assertTrue($result['guestFlow']['guestAddressFieldsVisible'] ?? false);
		self::assertTrue($result['guestFlow']['accountPanelVisible'] ?? false);
		self::assertSame(array(), $result['forbiddenRequests'] ?? null);
		self::assertSame(array(), $result['observedExternalNetworkRequests'] ?? null);
		self::assertSame(3, $result['requestCounts']['capturedScreenshots'] ?? null);
		self::assertTrue($result['cleanup']['browserProcessExited'] ?? false);
		self::assertTrue($result['cleanup']['profileRemoved'] ?? false);

		$reportPath = $result['reportPath'] ?? '';
		self::assertIsString($reportPath);
		self::assertFileExists($reportPath);
	}
}
