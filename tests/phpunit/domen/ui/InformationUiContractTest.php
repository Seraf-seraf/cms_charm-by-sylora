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
		self::assertStringContainsString('ABOUT_DESKTOP_CROP_WIDTH_PX = 1272', $controller);
		self::assertStringContainsString('ABOUT_DESKTOP_CROP_HEIGHT_PX = 600', $controller);
		self::assertStringContainsString('ABOUT_MOBILE_CROP_WIDTH_PX = 708', $controller);
		self::assertStringContainsString('ABOUT_MOBILE_CROP_HEIGHT_PX = 472', $controller);
		self::assertMatchesRegularExpression('~class="about-hero__intro".*?<h1[^>]*>.*?Украшения с тёплым характером.*?Смотреть каталог.*?Связаться.*?<picture class="about-hero__image"~s', $template);
		self::assertStringContainsString('media="(min-width: 768px)"', $template);
		self::assertStringContainsString('width="{{ about_image_data.desktop.width }}" height="{{ about_image_data.desktop.height }}"', $template);
		self::assertStringContainsString('width="{{ about_image_data.mobile.width }}" height="{{ about_image_data.mobile.height }}"', $template);
		self::assertStringNotContainsString('sylora-eyebrow">О мастере</p>', $template);
		self::assertStringContainsString('<h2>Украшения с тёплым характером</h2>', $template);
		self::assertStringContainsString('{{ about_image_data.mobile.src }}', $template);
		self::assertStringContainsString('{{ description }}', $template);
		self::assertStringContainsString('href="{{ catalog_href }}"', $template);
		self::assertStringContainsString('href="{{ contact_href }}"', $template);
	}
}
