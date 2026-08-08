<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HeaderUiContractTest extends TestCase {
	public function testMobileMenuToggleSynchronizesVisibleState(): void {
		$template = file_get_contents(
			dirname(__DIR__, 4) . '/upload/catalog/view/theme/charm_by_sylora/template/common/header.twig'
		);

		self::assertIsString($template);
		self::assertStringContainsString("navButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');", $template);
		self::assertStringContainsString("isOpen ? 'Закрыть меню' : 'Открыть меню'", $template);
		self::assertStringContainsString("isOpen ? 'fa-times' : 'fa-bars'", $template);
		self::assertStringContainsString('syncNavButton(false);', $template);
		self::assertStringContainsString('syncNavButton(isOpen);', $template);
	}
}
