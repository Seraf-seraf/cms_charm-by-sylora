<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InformationUiContractTest extends TestCase {
	public function testAboutHeroUsesAtelierCropAndKeepsConfiguredContent(): void {
		$root = dirname(__DIR__, 4);
		$controller = file_get_contents($root . '/upload/catalog/controller/information/information.php');
		$template = file_get_contents($root . '/upload/catalog/view/theme/charm_by_sylora/template/information/information.twig');

		self::assertIsString($controller);
		self::assertIsString($template);
		self::assertStringContainsString("resizeCoverWithSources(substr(\$data['about_image'], strlen('image/')), 840, 912)", $controller);
		self::assertStringContainsString('width="420" height="456"', $template);
		self::assertStringNotContainsString('sylora-eyebrow">О мастере</p>', $template);
		self::assertStringContainsString('<h2>Украшения с тёплым характером</h2>', $template);
		self::assertStringContainsString('{{ about_image_data.src }}', $template);
		self::assertStringContainsString('{{ description }}', $template);
		self::assertStringContainsString('href="{{ catalog_href }}"', $template);
		self::assertStringContainsString('href="{{ contact_href }}"', $template);
	}
}
