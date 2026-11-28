<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!class_exists('Controller')) {
	abstract class Controller {}
}

require_once dirname(__DIR__, 4) . '/upload/catalog/controller/product/product.php';

if (!class_exists('Url')) {
	require_once dirname(__DIR__, 4) . '/upload/system/library/url.php';
}

final class ProductBreadcrumbsControllerHarness extends ControllerProductProduct {
	public $load;
	public $request;
	public $language;
	public $url;
	public $model_catalog_category;
}

final class ProductBreadcrumbsLoaderStub {
	public array $loadedModels = array();

	public function model(string $route): void {
		$this->loadedModels[] = $route;
	}
}

final class ProductBreadcrumbsLanguageStub {
	private array $strings = array(
		'text_home' => 'Главная',
		'text_brand' => 'Бренд',
		'text_search' => 'Искать',
		'text_breadcrumb_catalog' => 'Каталог',
		'text_breadcrumb_search' => 'Поиск'
	);

	public function get(string $key): string {
		return $this->strings[$key] ?? $key;
	}
}

final class ProductBreadcrumbsCategoryModelStub {
	public function __construct(private array $categories) {}

	public function getCategoryBySeoKeyword(string $keyword): array {
		return $keyword === 'all-jewelry' ? ($this->categories[1] ?? array()) : array();
	}

	public function getCategoryByName(string $name): array {
		return $name === 'Все украшения' ? ($this->categories[1] ?? array()) : array();
	}

	public function getCategory(int $category_id): array {
		return $this->categories[$category_id] ?? array();
	}
}

final class ProductBreadcrumbsTest extends TestCase {
	private const PRODUCT_INFO = array(
		'product_id' => 1,
		'name' => 'Серьги «Розовый рассвет»'
	);

	public function testDirectProductUsesCatalogFallbackWithoutInventingCategory(): void {
		$result = $this->buildBreadcrumbs(array());

		self::assertSame(array('Каталог'), array_column($result['product'], 'text'));
		self::assertSame(
			array('Главная', 'Серьги «Розовый рассвет»'),
			array_column($result['default'], 'text')
		);
		self::assertSame(
			'https://shop.example/index.php?route=product/category&amp;path=1',
			$result['product'][0]['href']
		);
	}

	public function testCatalogCategoryRemainsVisibleWhenItMatchesTheSelectedPath(): void {
		$result = $this->buildBreadcrumbs(array('path' => '1'));

		self::assertSame(array('Каталог', 'Украшения'), array_column($result['product'], 'text'));
		self::assertSame(
			array('Главная', 'Украшения', 'Серьги «Розовый рассвет»'),
			array_column($result['default'], 'text')
		);

		$category_query = $this->queryParameters($result['product'][1]['href']);
		$product_query = $this->queryParameters($result['default'][2]['href']);

		self::assertSame('product/category', $category_query['route']);
		self::assertSame('1', $category_query['path']);
		self::assertSame('1', $product_query['path']);
		self::assertSame('1', $product_query['product_id']);
	}

	public function testCategoryBreadcrumbsFollowTheDynamicParentChainAndPreserveListingState(): void {
		$result = $this->buildBreadcrumbs(array(
			'path' => '1_2_3',
			'sort' => 'p.price',
			'order' => 'DESC',
			'page' => '3',
			'limit' => '20'
		));

		self::assertSame(
			array('Каталог', 'Украшения', 'Серьги', 'Гвоздики'),
			array_column($result['product'], 'text')
		);
		self::assertSame(
			array('Главная', 'Украшения', 'Серьги', 'Гвоздики', 'Серьги «Розовый рассвет»'),
			array_column($result['default'], 'text')
		);

		$category_query = $this->queryParameters($result['product'][3]['href']);

		self::assertSame('product/category', $category_query['route']);
		self::assertSame('1_2_3', $category_query['path']);
		self::assertSame('p.price', $category_query['sort']);
		self::assertSame('DESC', $category_query['order']);
		self::assertSame('3', $category_query['page']);
		self::assertSame('20', $category_query['limit']);
	}

	public function testBrokenCategoryParentChainFallsBackToCatalog(): void {
		$result = $this->buildBreadcrumbs(array('path' => '1_4_3'));

		self::assertSame(array('Каталог'), array_column($result['product'], 'text'));
		self::assertSame(
			array('Главная', 'Серьги «Розовый рассвет»'),
			array_column($result['default'], 'text')
		);
	}

	public function testMissingAndInvalidCategoryPathsFallBackWithoutWarningsOrErrors(): void {
		$requests = array(
			array(),
			array('path' => ''),
			array('path' => '1_not-a-category'),
			array('path' => '1_999'),
			array('path' => array('1'))
		);

		set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
			throw new ErrorException($message, 0, $severity, $file, $line);
		});

		try {
			foreach ($requests as $request_parameters) {
				$result = $this->buildBreadcrumbs($request_parameters);

				self::assertSame(array('Каталог'), array_column($result['product'], 'text'));
			}
		} finally {
			restore_error_handler();
		}
	}

	public function testSearchBreadcrumbEncodesUntrustedQueryInEveryGeneratedLink(): void {
		$search = '"><script>alert(1)</script>&sort=p.name';
		$result = $this->buildBreadcrumbs(array('search' => $search));
		$search_href = $result['product'][1]['href'];
		$product_href = $result['default'][2]['href'];

		self::assertStringContainsString('&amp;search=', $search_href);
		self::assertStringNotContainsString('<script', $search_href);
		self::assertStringNotContainsString('" on', $search_href);
		self::assertSame($search, $this->queryParameters($search_href)['search']);
		self::assertSame($search, $this->queryParameters($product_href)['search']);
	}

	private function buildBreadcrumbs(array $request_parameters): array {
		$controller = (new ReflectionClass(ProductBreadcrumbsControllerHarness::class))
			->newInstanceWithoutConstructor();
		$controller->request = (object)array('get' => $request_parameters);
		$controller->load = new ProductBreadcrumbsLoaderStub();
		$controller->language = new ProductBreadcrumbsLanguageStub();
		$controller->url = new Url('https://shop.example/');
		$controller->model_catalog_category = new ProductBreadcrumbsCategoryModelStub(array(
			1 => array('category_id' => 1, 'parent_id' => 0, 'name' => 'Украшения'),
			2 => array('category_id' => 2, 'parent_id' => 1, 'name' => 'Серьги'),
			3 => array('category_id' => 3, 'parent_id' => 2, 'name' => 'Гвоздики'),
			4 => array('category_id' => 4, 'parent_id' => 1, 'name' => 'Браслеты')
		));

		$method = new ReflectionMethod(ControllerProductProduct::class, 'buildProductBreadcrumbs');

		return $method->invoke($controller, self::PRODUCT_INFO['product_id'], self::PRODUCT_INFO);
	}

	private function queryParameters(string $href): array {
		$decoded_href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$query = parse_url($decoded_href, PHP_URL_QUERY);
		parse_str(is_string($query) ? $query : '', $parameters);

		return $parameters;
	}
}
