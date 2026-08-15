<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PostE2EUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testHeaderExposesAccessibleAccountActions(): void {
		$controller = $this->read('upload/catalog/controller/common/header.php');
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/header.twig');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');

		self::assertStringContainsString("\$data['logged'] = \$this->customer->isLogged();", $controller);
		self::assertStringContainsString("\$data['login'] = \$this->url->link('account/login', '', true);", $controller);
		self::assertStringContainsString("\$data['account'] = \$this->url->link('account/account', '', true);", $controller);
		self::assertSame(1, substr_count($template, 'class="site-account"'));
		self::assertSame(1, substr_count($template, 'class="site-nav__account"'));
		self::assertStringContainsString("logged ? 'Личный кабинет' : 'Войти'", $template);
		self::assertSame(2, substr_count($template, 'aria-label="{{ account_label }}"'));
		self::assertMatchesRegularExpression('/\.site-account\s*\{[^}]*min-width: 44px;[^}]*min-height: 44px;/s', $css);
		self::assertStringContainsString('.site-account:focus-visible', $css);
	}

	public function testBrandAndCatalogLayoutContractsAreStable(): void {
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$category = $this->read('upload/catalog/view/theme/charm_by_sylora/template/product/category.twig');

		self::assertMatchesRegularExpression('/\.site-brand\s*\{[^}]*flex: none;[^}]*min-width: max-content;[^}]*white-space: nowrap;/s', $css);
		self::assertMatchesRegularExpression('/\.catalog-card__body\s*\{[^}]*padding: 16px;/s', $css);
		self::assertMatchesRegularExpression('/\.catalog-card \.caption\s*\{[^}]*padding: 0 0 12px;/s', $css);
		self::assertMatchesRegularExpression('/\.catalog-card__actions\s*\{[^}]*padding: 0;/s', $css);
		self::assertMatchesRegularExpression('/\.catalog-filters__grid\s*\{[^}]*grid-template-columns: minmax\(0, 1fr\);/s', $css);
		self::assertStringContainsString('class="row catalog-page-layout"', $category);
		self::assertMatchesRegularExpression('/\.catalog-page-layout > #column-left,[^{]+\{[^}]*float: none;[^}]*width: 100%;/s', $css);
		self::assertMatchesRegularExpression('/@media \(max-width: 420px\).*?\.catalog-filters__price\s*\{[^}]*grid-template-columns: minmax\(0, 1fr\);/s', $css);
		self::assertStringContainsString('overflow-wrap: anywhere;', $css);
	}

	public function testAvailabilityLibraryMatchesOpenCartLoaderContract(): void {
		require_once $this->root . '/upload/system/library/product_availability.php';

		$availability = new product_availability();

		self::assertInstanceOf(ProductAvailability::class, $availability);
		self::assertTrue($availability->resolve(array('quantity' => 1))['can_buy']);
	}

	public function testShippingCardsKeepNativeRadioContract(): void {
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/checkout/shipping_method.twig');
		$cart = $this->read('upload/catalog/view/javascript/cart.js');
		$payment = $this->read('upload/catalog/view/theme/charm_by_sylora/template/checkout/payment_method.twig');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');

		self::assertStringContainsString('type="radio" name="shipping_method" value="{{ quote.code }}"', $template);
		self::assertStringContainsString('type="radio" name="shipping_method" value="', $cart);
		self::assertStringContainsString('shipping-method-card__marker', $template . $cart);
		self::assertStringNotContainsString('shipping-method-card', $payment);
		self::assertMatchesRegularExpression('/\.shipping-method-card\s*\{[^}]*grid-template-columns: 22px minmax\(0, 1fr\) auto;/s', $css);
		self::assertStringContainsString('.shipping-method-card:active', $css);
		self::assertStringContainsString('.shipping-method-card__control:checked', $css);
		self::assertStringContainsString('.shipping-method-card__control:focus-visible', $css);
		self::assertStringContainsString('.shipping-method-card__control:disabled', $css);
		self::assertStringContainsString('@media (hover: hover) and (pointer: fine)', $css);
		self::assertStringNotContainsString('display: none;', $this->shippingControlRule($css));
	}

	public function testPriceAndSeoContractsUseNormalizedCanonicalUrls(): void {
		$category = $this->read('upload/catalog/controller/product/category.php');
		$storefront = $this->read('upload/catalog/view/javascript/storefront.js');
		$sitemap = $this->read('upload/catalog/controller/information/sitemap.php');
		$notFound = $this->read('upload/catalog/controller/error/not_found.php');

		self::assertStringContainsString('$canonical_url = $filter_url;', $category);
		self::assertMatchesRegularExpression('/if \(\$price_min !== \'\' && \$price_max !== \'\' && \$price_min > \$price_max\).*?\$tmp = \$price_min;.*?\$price_min = \$price_max;.*?\$price_max = \$tmp;/s', $category);
		self::assertMatchesRegularExpression('/if \(!isNaN\(minValue\) && !isNaN\(maxValue\) && minValue > maxValue\).*?minInput\.value = maxValue;.*?maxInput\.value = minValue;/s', $storefront);
		self::assertStringContainsString("addLink(\$this->url->link('information/sitemap', '', true), 'canonical')", $sitemap);
		self::assertStringContainsString("' 404 Not Found'", $notFound);
		self::assertStringContainsString("setRobots('noindex, nofollow')", $notFound);
		self::assertStringNotContainsString("addLink(", $notFound);
	}

	public function testLegalPagesAndFooterUseOneLegalName(): void {
		$legalName = 'Индивидуальный предприниматель Глава крестьянского (фермерского) хозяйства Кравчук Серафим Сергеевич';

		foreach (array('returns.txt', 'offer.txt', 'privacy-policy.txt', 'contacts-requisites.txt') as $file) {
			self::assertStringContainsString($legalName, $this->read('legal-pages/' . $file), $file);
		}

		self::assertStringNotContainsString(
			'Индивидуальный предприниматель Кравчук Серафим Сергеевич',
			$this->read('legal-pages/returns.txt')
		);
		self::assertStringContainsString("config_sylora_legal_name", $this->read('upload/catalog/controller/common/footer.php'));
		self::assertStringContainsString('{{ legal_name }}', $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/footer.twig'));
	}

	private function shippingControlRule(string $css): string {
		$matched = preg_match('/\.shipping-method-card__control\s*\{([^}]*)\}/s', $css, $matches);

		self::assertSame(1, $matched);

		return $matches[1];
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content, $path);

		return $content;
	}
}
