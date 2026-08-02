<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/upload/system/library/catalog_schema.php';

final class CatalogSchemaTest extends PHPUnit\Framework\TestCase {
	public function testBuildsCollectionItemsAndBreadcrumbsWithPagePositions(): void {
		$schema = new Catalog_schema();
		$json = $schema->build(
			'Серьги &amp; подвески',
			'https://example.test/earrings?page=2&amp;sort=name',
			array(
				array('text' => '<i>Главная</i>', 'href' => 'https://example.test/'),
				array('text' => 'Серьги', 'href' => 'https://example.test/earrings')
			),
			array(
				array(
					'name' => 'Длинное название украшения',
					'href' => 'https://example.test/product&amp;id=42',
					'image' => array('src' => 'https://example.test/image.webp')
				)
			),
			2,
			12
		);

		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

		self::assertSame('https://schema.org', $data['@context']);
		self::assertSame('CollectionPage', $data['@graph'][0]['@type']);
		self::assertSame('Серьги & подвески', $data['@graph'][0]['name']);
		self::assertSame('https://example.test/earrings?page=2&sort=name', $data['@graph'][0]['url']);
		self::assertSame('ItemList', $data['@graph'][1]['@type']);
		self::assertSame(13, $data['@graph'][1]['itemListElement'][0]['position']);
		self::assertSame('https://example.test/product&id=42', $data['@graph'][1]['itemListElement'][0]['url']);
		self::assertSame('https://example.test/image.webp', $data['@graph'][1]['itemListElement'][0]['item']['image']);
		self::assertSame('BreadcrumbList', $data['@graph'][2]['@type']);
		self::assertSame('Главная', $data['@graph'][2]['itemListElement'][0]['name']);
	}

	public function testSkipsIncompleteProductsAndBreadcrumbs(): void {
		$schema = new Catalog_schema();
		$json = $schema->build(
			'Каталог',
			'https://example.test/search',
			array(array('text' => '', 'href' => 'https://example.test/')),
			array(
				array('name' => 'Без ссылки'),
				array('href' => 'https://example.test/no-name')
			),
			0,
			0
		);

		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

		self::assertCount(2, $data['@graph']);
		self::assertSame(0, $data['@graph'][1]['numberOfItems']);
		self::assertSame(array(), $data['@graph'][1]['itemListElement']);
	}

	public function testListingControllersAndTemplatesExposeDynamicSchema(): void {
		$root = dirname(__DIR__, 4);

		foreach (array('category', 'search', 'special') as $route) {
			$controller = file_get_contents($root . '/upload/catalog/controller/product/' . $route . '.php');
			$template = file_get_contents($root . '/upload/catalog/view/theme/charm_by_sylora/template/product/' . $route . '.twig');

			self::assertIsString($controller);
			self::assertIsString($template);
			self::assertStringContainsString("load->library('catalog_schema')", $controller, $route);
			self::assertStringContainsString("['catalog_schema']", $controller, $route);
			self::assertStringContainsString('<script type="application/ld+json">{{ catalog_schema|raw }}</script>', $template, $route);
		}
	}
}
