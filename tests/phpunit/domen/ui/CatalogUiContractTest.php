<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CatalogUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testAllCatalogContextsUseOneProductCardPartial(): void {
		$templates = array(
			'product/category.twig',
			'product/search.twig',
			'product/special.twig',
			'common/home.twig',
			'product/product.twig',
		);

		foreach ($templates as $template) {
			$source = $this->themeTemplate($template);

			self::assertSame(1, substr_count($source, "{% include 'product/product_card.twig' %}"), $template);
			self::assertStringNotContainsString('<article class="product-layout product-grid catalog-card">', $source, $template);
		}
	}

	public function testProductCardIsSemanticDataDrivenAndHasRequiredActions(): void {
		$card = $this->themeTemplate('product/product_card.twig');

		self::assertStringContainsString('<article class="product-layout product-grid catalog-card">', $card);
		self::assertStringContainsString('<footer class="catalog-card__actions">', $card);
		self::assertStringContainsString('<{{ card_heading }}', $card);
		self::assertStringContainsString('catalog-card__media', $card);
		self::assertStringContainsString('catalog-card__wishlist', $card);
		self::assertStringContainsString('catalog-card__secondary', $card);
		self::assertStringContainsString('{% if has_quick_view %}', $card);
		self::assertStringContainsString('<dialog class="quick-view"', $card);
		self::assertStringContainsString('text_additional_image|format(product.name)', $card);
		self::assertStringContainsString('{{ button_cart }}', $card);
		self::assertStringContainsString('{{ button_wishlist }}', $card);
		self::assertStringContainsString('{{ button_details }}', $card);
		self::assertStringContainsString('{{ button_quick_view }}', $card);
		self::assertStringContainsString('{{ text_out_of_stock }}', $card);
		self::assertStringNotContainsString('index.php?', $card);
		self::assertDoesNotMatchRegularExpression('/[А-Яа-яЁё]/u', $card);
	}

	public function testToolbarFiltersPaginationAndBreakpointsHaveExplicitContracts(): void {
		foreach (array('product/category.twig', 'product/search.twig', 'product/special.twig') as $template) {
			$source = $this->themeTemplate($template);

			self::assertStringContainsString('catalog-toolbar__field', $source, $template);
			self::assertStringContainsString('<label for="input-sort">{{ text_sort }}</label>', $source, $template);
			self::assertStringContainsString('<label for="input-limit">{{ text_limit }}</label>', $source, $template);
			self::assertStringContainsString('<nav class="catalog-pagination" aria-label="{{ text_pagination_navigation }}">', $source, $template);
			self::assertStringNotContainsString('input-group', $source, $template);
		}

		$category = $this->themeTemplate('product/category.twig');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');

		self::assertStringContainsString('<div class="catalog-shell">', $category);
		self::assertStringContainsString('<aside class="catalog-shell__sidebar">', $category);
		self::assertStringContainsString('<span>{{ text_filters }}</span>', $category);
		self::assertStringContainsString("window.matchMedia('(min-width: 992px)')", $category);
		self::assertStringContainsString('filterDetails.open = true;', $category);
		self::assertStringContainsString('grid-template-columns: 280px minmax(0, 1fr);', $css);
		self::assertStringContainsString('grid-template-columns: repeat(3, minmax(0, 1fr));', $css);
		self::assertMatchesRegularExpression('/\.catalog-toolbar\s*\{[^}]*flex-wrap: wrap;/s', $css);
		self::assertMatchesRegularExpression('/\.catalog-toolbar__controls\s*\{[^}]*flex-wrap: wrap;[^}]*max-width: 100%;/s', $css);
		self::assertMatchesRegularExpression('/@media \(max-width: 1199px\).*?grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/s', $css);
		self::assertMatchesRegularExpression('/@media \(max-width: 767px\).*?\.catalog-grid\s*\{\s*grid-template-columns: minmax\(0, 1fr\);/s', $css);
		self::assertMatchesRegularExpression('/\.catalog-card__wishlist\s*\{[^}]*position: absolute;[^}]*top: 12px;[^}]*right: 12px;/s', $css);

		$search = $this->themeTemplate('product/search.twig');
		self::assertStringContainsString('<form class="catalog-search-panel" action="{{ search_url }}" method="get"', $search);
		self::assertStringContainsString('<input type="hidden" name="route" value="product/search" />', $search);
		self::assertStringContainsString('<button type="submit" id="button-search"', $search);
		self::assertStringContainsString('<label class="sr-only" for="input-category">{{ text_all_categories }}</label>', $search);
		self::assertStringContainsString('id="input-category"', $search);
		self::assertStringNotContainsString('value="{{ category_2.category_id }}>', $search);
		self::assertStringNotContainsString('value="{{ category_3.category_id }}>', $search);
		self::assertStringContainsString('value="{{ category_2.category_id }}">', $search);
		self::assertStringContainsString('value="{{ category_3.category_id }}">', $search);
		self::assertMatchesRegularExpression('/\.catalog-card__image img\s*\{[^}]*object-fit: contain;/s', $css);
		self::assertMatchesRegularExpression('/\.catalog-card__actions\s*\{[^}]*margin-top: auto;/s', $css);
		self::assertMatchesRegularExpression('/\.catalog-card\s*\{[^}]*display: flex;[^}]*height: 100%;/s', $css);
		self::assertMatchesRegularExpression('/\.catalog-card__media\s*\{[^}]*width: 100%;[^}]*max-height: 480px;[^}]*aspect-ratio: 4 \/ 5;[^}]*overflow: hidden;/s', $css);
		self::assertMatchesRegularExpression('/\.catalog-card__image\s*\{[^}]*position: relative;[^}]*width: 100%;[^}]*height: 100%;/s', $css);
		self::assertMatchesRegularExpression('/\.catalog-pagination \.pagination\s*\{[^}]*display: flex;[^}]*gap: 8px;/s', $css);
		self::assertMatchesRegularExpression('/\.catalog-pagination \.pagination > li > a,[^{]+\{[^}]*min-width: 44px;[^}]*min-height: 44px;[^}]*border-radius: 8px;/s', $css);
		self::assertMatchesRegularExpression('/\.catalog-pagination \.pagination > \.active > a,[\s\S]*?\{[^}]*background: var\(--color-accent\);[^}]*color: var\(--color-on-accent\);/s', $css);
		self::assertMatchesRegularExpression('/main \.btn,\s*main select\.form-control,\s*main textarea\.form-control,\s*main input\.form-control\s*\{[^}]*min-height: 44px;/s', $css);
		self::assertMatchesRegularExpression('/\.catalog-filters__price input\s*\{[^}]*min-height: 44px;/s', $css);
		self::assertMatchesRegularExpression('/\.catalog-filters__option\s*\{[^}]*align-items: center;[^}]*min-height: 44px;/s', $css);
		self::assertStringContainsString('product-meta__label', $this->themeTemplate('product/product.twig'));
		self::assertMatchesRegularExpression('/stylesheet\.min\.css\?v=[^"\s]+/', $this->themeTemplate('common/header.twig'));
	}

	public function testSharedRussianLanguageContainsCatalogLabels(): void {
		$language = array();
		$_ = &$language;
		require $this->root . '/upload/catalog/language/ru-ru/ru-ru.php';

		foreach (array(
			'button_details', 'button_quick_view', 'button_close', 'button_filter_apply', 'button_filter_reset',
			'text_out_of_stock', 'text_additional_image', 'text_sort', 'text_limit', 'text_filters',
			'text_pagination_navigation', 'text_catalog_search', 'text_catalog_search_label',
			'text_catalog_search_placeholder', 'text_all_categories', 'text_search_subcategories',
			'text_search_description', 'text_catalog_search_empty', 'button_open_catalog',
		) as $key) {
			self::assertArrayHasKey($key, $language);
			self::assertNotSame('', $language[$key]);
		}
	}

	public function testCatalogRussianLanguageContainsAddedSortLabels(): void {
		foreach (array('category.php', 'search.php', 'special.php') as $file) {
			$language = array();
			$_ = &$language;
			require $this->root . '/upload/catalog/language/ru-ru/product/' . $file;

			self::assertSame('Популярные', $language['text_popular_desc'] ?? null, $file);
			self::assertSame('Сначала новые', $language['text_date_desc'] ?? null, $file);
		}
	}

	private function themeTemplate(string $path): string {
		return $this->read('upload/catalog/view/theme/charm_by_sylora/template/' . $path);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content, $path);

		return $content;
	}
}
