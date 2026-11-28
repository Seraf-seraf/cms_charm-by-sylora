<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CurrentCategoryColorTypeFiltersMigrationTest extends TestCase {
	private string $migration;

	protected function setUp(): void {
		$path = dirname(__DIR__, 4) . '/database/migrations/2026_08_08_000021_current_category_color_type_filters.php';
		$content = file_get_contents($path);

		self::assertIsString($content);
		$this->migration = $content;
	}

	public function testMigrationUsesProductionColorAndProductTypeAttributes(): void {
		self::assertStringContainsString("'Цвет' => array(", $this->migration);
		self::assertStringContainsString("'Тип изделия' => array(", $this->migration);
		self::assertStringContainsString("'основные цвета'", $this->migration);

		foreach (array('Белый', 'Розовый', 'Голубой', 'Синий', 'Зелёный', 'Фиолетовый', 'Жёлтый', 'Серебристый', 'Прозрачный') as $color) {
			self::assertStringContainsString("'" . $color . "' => array(", $this->migration);
		}

		foreach (array('Брелок-подвеска', 'Колье') as $productType) {
			self::assertStringContainsString("'" . $productType . "' => array(", $this->migration);
		}
	}

	public function testMigrationAddsNativeFiltersWithoutReplacingCatalogData(): void {
		self::assertStringContainsString('FROM `product_attribute` pa', $this->migration);
		self::assertStringContainsString('INSERT IGNORE INTO `category_filter`', $this->migration);
		self::assertStringContainsString('INSERT IGNORE INTO `product_filter`', $this->migration);
		self::assertStringNotContainsString('DELETE FROM `product_filter`', $this->migration);
		self::assertStringNotContainsString('UPDATE `product_attribute`', $this->migration);
		self::assertStringContainsString('$mysqli->rollback();', $this->migration);
	}
}
