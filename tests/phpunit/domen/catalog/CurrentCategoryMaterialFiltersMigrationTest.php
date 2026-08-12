<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CurrentCategoryMaterialFiltersMigrationTest extends TestCase {
	private string $migration;

	protected function setUp(): void {
		$path = dirname(__DIR__, 4) . '/database/migrations/2026_08_08_000020_current_category_material_filters.php';
		$content = file_get_contents($path);

		self::assertIsString($content);
		$this->migration = $content;
	}

	public function testMigrationUsesCurrentCategoriesAndMaterialTaxonomy(): void {
		foreach (array('Ожерелья и колье', 'Браслеты', 'Серьги', 'Кольца', 'Брелоки', 'Комплекты', 'Броши') as $category) {
			self::assertStringContainsString("'" . $category . "'", $this->migration);
		}

		foreach (array('Акрил', 'Стекло', 'Металл', 'Натуральные камни', 'Перламутр') as $material) {
			self::assertStringContainsString("'" . $material . "' => array(", $this->migration);
		}
	}

	public function testMigrationUsesExistingAttributesWithoutReplacingData(): void {
		self::assertStringContainsString('FROM `product_attribute` pa', $this->migration);
		self::assertStringContainsString('INSERT IGNORE INTO `category_filter`', $this->migration);
		self::assertStringContainsString('INSERT IGNORE INTO `product_filter`', $this->migration);
		self::assertStringNotContainsString('DELETE FROM `product_filter`', $this->migration);
		self::assertStringNotContainsString('UPDATE `product_attribute`', $this->migration);
		self::assertStringContainsString('$mysqli->rollback();', $this->migration);
	}
}
