<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CurrentCategorySeoUrlsMigrationTest extends TestCase {
	private string $migration;

	protected function setUp(): void {
		$path = dirname(__DIR__, 4) . '/database/migrations/2026_08_08_000019_current_category_seo_urls.php';
		$content = file_get_contents($path);

		self::assertIsString($content);
		$this->migration = $content;
	}

	public function testCurrentCategoriesHaveStableSeoKeywords(): void {
		$expected = array(
			'Ожерелья и колье' => 'ozherelya-i-kolie',
			'Браслеты' => 'braslety',
			'Серьги' => 'sergi',
			'Кольца' => 'kolca',
			'Брелоки' => 'breloki',
			'Комплекты' => 'komplekty',
			'Броши' => 'broshi',
		);

		foreach ($expected as $name => $keyword) {
			self::assertStringContainsString("'" . $name . "' => '" . $keyword . "'", $this->migration);
		}
	}

	public function testMigrationPreservesExistingUrlsAndRejectsKeywordConflicts(): void {
		self::assertStringContainsString('if ($existingResult->num_rows)', $this->migration);
		self::assertStringContainsString("SEO keyword '", $this->migration);
		self::assertStringContainsString('$mysqli->rollback();', $this->migration);
		self::assertStringNotContainsString('DELETE FROM `seo_url`', $this->migration);
	}
}
