<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SeoCatalogAndStoreMigrationTest extends TestCase {
	public function testMigrationCreatesCatalogWithoutReplacingProductCategories(): void {
		$migration = file_get_contents(
			dirname(__DIR__, 4) . '/database/migrations/2026_08_12_000022_seo_catalog_and_store.php'
		);

		self::assertIsString($migration);
		self::assertStringContainsString("name = 'Все украшения'", $migration);
		self::assertStringContainsString("keyword = 'all-jewelry'", $migration);
		self::assertStringContainsString('INSERT IGNORE INTO `" . $productCategoryTable', $migration);
		self::assertStringNotContainsString('DELETE FROM `" . $productCategoryTable', $migration);
		self::assertStringContainsString("'config_email' => 'info@charm-by-sylora.ru'", $migration);
		self::assertStringContainsString("'config_sylora_tax_id' => '771377623413'", $migration);
		self::assertStringContainsString("'config_sylora_registration_id' => 'ОГРНИП 325774600851993'", $migration);
	}

	public function testSeoAuditMigrationUpdatesOnlyTargetedStoreContent(): void {
		$migration = file_get_contents(
			dirname(__DIR__, 4) . '/database/migrations/2026_08_29_000023_seo_audit_fixes.php'
		);

		self::assertIsString($migration);
		self::assertStringContainsString("upsertSetting(\$db, \$prefix, 'config_meta_title'", $migration);
		self::assertStringContainsString("keyword = 'delivery-payment'", $migration);
		self::assertStringContainsString('Основной подключенный способ доставки — СДЭК', $migration);
		self::assertStringNotContainsString('DELETE FROM', $migration);
		self::assertStringNotContainsString('TRUNCATE', $migration);
	}
}
