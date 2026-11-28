<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotFoundUiContractTest extends TestCase {
	public function testRussianNotFoundActionsHaveVisibleLabels(): void {
		$language = array();
		$_ = &$language;
		require dirname(__DIR__, 4) . '/upload/catalog/language/ru-ru/error/not_found.php';

		self::assertSame('Перейти в каталог', $language['text_catalog'] ?? null);
		self::assertSame('На главную', $language['text_home'] ?? null);
		self::assertSame('Связаться с нами', $language['text_contact'] ?? null);
	}
}
