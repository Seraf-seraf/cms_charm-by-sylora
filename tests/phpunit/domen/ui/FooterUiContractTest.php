<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FooterUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testLegacyCatalogUrlUsesCurrentCatalogRoute(): void {
		$footerController = $this->read('upload/catalog/controller/common/footer.php');
		$settingController = $this->read('upload/admin/controller/setting/setting.php');

		self::assertStringContainsString("if (\$url === '/all-jewelry')", $footerController);
		self::assertStringContainsString("\$url = \$this->getCatalogUrl();", $footerController);
		self::assertStringContainsString("array('text' => 'Каталог', 'url' => '/search')", $settingController);
		self::assertStringNotContainsString("array('text' => 'Каталог', 'url' => '/all-jewelry')", $settingController);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
