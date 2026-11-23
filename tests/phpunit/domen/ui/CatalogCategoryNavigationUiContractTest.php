<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CatalogCategoryNavigationUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testRootCatalogUsesCurrentCategoryContentAndLiveNavigation(): void {
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/product/category.twig');
		$controller = $this->read('upload/catalog/controller/product/category.php');
		$language = $this->read('upload/catalog/language/ru-ru/product/category.php');
		$headerController = $this->read('upload/catalog/controller/common/header.php');
		$categoryModel = $this->read('upload/catalog/model/catalog/category.php');

		self::assertStringContainsString("getCategoryBySeoKeyword('all-jewelry')", $controller);
		self::assertStringContainsString("getCategoryByName('Все украшения')", $controller);
		self::assertStringContainsString('$data[\'is_catalog_root\'] = $catalog_category && (int)$catalog_category[\'category_id\'] === $category_id;', $controller);
		self::assertStringContainsString("\$data['heading_title'] = \$category_info['name'];", $controller);
		self::assertStringContainsString("\$data['description'] = html_entity_decode(\$category_info['description'], ENT_QUOTES, 'UTF-8');", $controller);
		self::assertStringContainsString("\$data['thumb'] = \$this->model_tool_image->resize(\$category_info['image']", $controller);

		self::assertStringContainsString("\$_['text_catalog_root_title_mobile'] = 'Каталог';", $language);
		self::assertStringContainsString("\$_['text_catalog_root_title_desktop'] = 'Каталог украшений';", $language);
		self::assertStringContainsString("\$_['text_catalog_root_subtitle_mobile']", $language);
		self::assertStringContainsString("\$_['text_catalog_root_subtitle_desktop']", $language);
		self::assertStringContainsString('catalog-head__title catalog-head__title--mobile">{{ text_catalog_root_title_mobile }}', $template);
		self::assertStringContainsString('catalog-head__title catalog-head__title--desktop">{{ text_catalog_root_title_desktop }}', $template);
		self::assertStringContainsString('{% if description %}', $template);
		self::assertStringContainsString('<div class="catalog-head__subtitle">', $template);
		self::assertStringContainsString('<span class="catalog-head__subtitle--mobile">{{ text_catalog_root_subtitle_mobile }}</span>', $template);
		self::assertStringContainsString('{% if description %}{{ description }}{% else %}{{ text_catalog_root_subtitle_desktop }}{% endif %}', $template);
		self::assertStringContainsString('{% if is_catalog_root and thumb %}', $template);
		self::assertStringContainsString('class="catalog-root__thumb"', $template);
		self::assertStringContainsString('{% if not is_catalog_root and (thumb or description) %}', $template);

		self::assertStringContainsString('<nav class="catalog-category-chips" aria-label="{{ text_filter_categories }}">', $template);
		self::assertStringContainsString('{% for category in filter_categories %}', $template);
		self::assertStringContainsString('{% if category.total or category.selected %}', $template);
		self::assertStringContainsString('href="{{ category.href }}"{% if category.selected %} aria-current="page"{% endif %}>{{ category.name }}', $template);
		self::assertStringContainsString("\$data['filter_categories'][] = array(", $controller);
		self::assertStringContainsString("foreach (\$this->model_catalog_category->getCategories(0) as \$filter_category)", $controller);
		self::assertStringContainsString("\$category_href = 'path=' . (int)\$filter_category['category_id'];", $controller);
		self::assertStringContainsString("\$category_href .= '&sort=' . \$this->request->get['sort'];", $controller);
		self::assertStringContainsString("\$category_href .= '&order=' . \$this->request->get['order'];", $controller);
		self::assertStringContainsString("\$category_href .= '&limit=' . \$this->request->get['limit'];", $controller);
		self::assertStringContainsString("'selected' => (int)\$filter_category['category_id'] === \$category_id,", $controller);
		self::assertStringContainsString("'href' => \$this->url->link('product/category', \$category_href)", $controller);
		self::assertStringContainsString("\$data['filter_reset'] = \$this->url->link('product/category', 'path=' . \$this->request->get['path']);", $controller);

		self::assertStringContainsString('function getCatalogUrl()', $headerController);
		self::assertStringContainsString("\$this->model_catalog_category->getCategoryBySeoKeyword('all-jewelry')", $headerController);
		self::assertStringContainsString("\$this->url->link('product/category', 'path=' . (int)\$category['category_id'])", $headerController);
		self::assertStringContainsString('function getCategories($parent_id = 0)', $categoryModel);

		$toolbarPosition = strpos($template, '<div class="catalog-toolbar">');
		$chipsPosition = strpos($template, '<nav class="catalog-category-chips"');
		$gridPosition = strpos($template, '<div class="catalog-grid">');

		self::assertIsInt($toolbarPosition);
		self::assertIsInt($chipsPosition);
		self::assertIsInt($gridPosition);
		self::assertLessThan($toolbarPosition, $chipsPosition);
		self::assertLessThan($gridPosition, $toolbarPosition);
	}

	public function testResponsiveCatalogKeepsFiltersControlsActionsAndPagination(): void {
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/product/category.twig');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$productCard = $this->read('upload/catalog/view/theme/charm_by_sylora/template/product/product_card.twig');

		self::assertStringContainsString('<form class="catalog-filters__form" action="{{ filter_action }}" method="get">', $template);
		foreach (array('filter[]', 'path', 'price_min', 'price_max', 'availability', 'is_new', 'is_sale') as $fieldName) {
			self::assertStringContainsString('name="' . $fieldName . '"', $template, 'Filter field ' . $fieldName . ' must remain available.');
		}
		self::assertStringContainsString('<label for="input-sort">{{ text_catalog_root_sort }}</label>', $template);
		self::assertStringContainsString('<select id="input-sort" class="form-control" data-location-select>', $template);
		self::assertStringContainsString('<label for="input-limit">{{ text_limit }}</label>', $template);
		self::assertStringContainsString('<nav class="catalog-pagination" aria-label="{{ text_pagination_navigation }}">{{ pagination }}</nav>', $template);
		self::assertStringContainsString("{% include 'product/product_card.twig' %}", $template);
		self::assertStringContainsString('data-cart-add="{{ product.product_id }}"', $productCard);
		self::assertStringContainsString('data-wishlist-add="{{ product.product_id }}"', $productCard);
		self::assertStringContainsString('class="catalog-card__more" href="{{ product.href }}"', $productCard);

		self::assertStringContainsString('#product-category .catalog-category-chips { display: none; }', $css);
		self::assertMatchesRegularExpression('/#product-category \.catalog-category-chips\s*\{[^}]*display: flex;[^}]*overflow-x: auto;/s', $css);
		self::assertStringContainsString('class="catalog-filters__close" aria-label="{{ button_filter_close }}"', $template);
		self::assertStringContainsString('class="catalog-empty" aria-labelledby="catalog-empty-title"', $template);
		self::assertStringContainsString('<a href="{{ filter_reset }}">{{ button_filter_reset_all }}</a>', $template);
		self::assertStringContainsString('#product-category .catalog-shell:has(.catalog-filters[open]) .catalog-shell__sidebar { grid-column: 1 / -1; }', $css);
		self::assertMatchesRegularExpression('/#product-category \.catalog-toolbar__field select\.form-control\s*\{[^}]*min-height: 44px;/s', $css);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content, $path);

		return $content;
	}
}
