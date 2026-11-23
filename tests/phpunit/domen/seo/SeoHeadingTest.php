<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SeoHeadingTest extends TestCase {
	public function testHomeHeadingUsesTheAtelierDefaultWhenNoOverrideIsConfigured(): void {
		$seo = $this->createSeo(array('config_name' => 'Charm by Sylora'));

		self::assertSame('Украшения с тёплым характером', $seo->heading('Charm by Sylora', 'home'));
	}

	public function testHomeHeadingReplacesThePreviouslySeededDefaultAtRuntime(): void {
		$seo = $this->createSeo(array(
			'config_name' => 'Charm by Sylora',
			'config_seo_home_h1_template' => 'Ручные украшения {store}'
		));

		self::assertSame('Украшения с тёплым характером', $seo->heading('Charm by Sylora', 'home'));
	}

	public function testConfiguredHomeHeadingTemplateOverridesTheDefault(): void {
		$seo = $this->createSeo(array(
			'config_name' => 'Charm by Sylora',
			'config_seo_home_h1_template' => 'Авторские изделия {store}'
		));

		self::assertSame('Авторские изделия Charm by Sylora', $seo->heading('Charm by Sylora', 'home'));
	}

	private function createSeo(array $settings): Seo {
		$root = dirname(__DIR__, 4);
		require_once $root . '/upload/system/engine/registry.php';
		require_once $root . '/upload/system/library/config.php';
		require_once $root . '/upload/system/library/seo.php';

		$config = new Config();

		foreach ($settings as $key => $value) {
			$config->set($key, $value);
		}

		$registry = new Registry();
		$registry->set('config', $config);

		return new Seo($registry);
	}
}
