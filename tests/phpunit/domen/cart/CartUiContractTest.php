<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CartUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testCartControllersProvideResponsiveImageData(): void {
		foreach (array('common/cart.php', 'checkout/cart.php') as $controller) {
			$source = $this->read('upload/catalog/controller/' . $controller);

			self::assertStringContainsString('resizeWithSources(', $source, $controller);
			self::assertStringContainsString("'image'", $source, $controller);
			self::assertStringContainsString("'thumb'     => \$image['src']", $source, $controller);
			self::assertStringContainsString("'image'     => \$image", $source, $controller);
		}
	}

	public function testCartTemplatesUseCorrectItemModifiersAndDynamicImages(): void {
		$miniCart = $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/cart.twig');
		$cart = $this->read('upload/catalog/view/theme/charm_by_sylora/template/checkout/cart.twig');

		self::assertMatchesRegularExpression('/for product in products.*?<article class="mini-cart__item">/s', $miniCart);
		self::assertMatchesRegularExpression('/for voucher in vouchers.*?<article class="mini-cart__item mini-cart__item--voucher">/s', $miniCart);
		self::assertStringContainsString('product.image.sources', $miniCart);
		self::assertStringContainsString('product.image.width', $miniCart);
		self::assertStringContainsString('product.image.height', $miniCart);
		self::assertStringContainsString('product.image.sources', $cart);
		self::assertStringContainsString('product.image.width', $cart);
		self::assertStringContainsString('product.image.height', $cart);
		self::assertStringNotContainsString('width="47"', $miniCart . $cart);

		$stylesheet = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		self::assertStringContainsString('#cart .mini-cart li > .mini-cart__items', $stylesheet);
		self::assertStringContainsString('overflow-x: hidden;', $stylesheet);
		self::assertStringContainsString('grid-template-columns: 64px minmax(0, 1fr) 44px;', $stylesheet);
	}

	public function testRussianCartActionsHaveAccessibleTranslations(): void {
		$language = array();
		$_ = &$language;
		require $this->root . '/upload/catalog/language/ru-ru/checkout/cart.php';

		$expected = array(
			'text_cart_action' => 'Перейти в корзину',
			'text_continue_action' => 'Продолжить покупки',
			'text_cart_intro' => 'Проверьте состав заказа, количество товаров и итоговую стоимость перед оформлением.',
			'text_product_details' => 'Выбранные параметры товара',
			'text_summary' => 'Итого по заказу',
			'text_cart_help' => 'Стоимость доставки будет рассчитана после заполнения адреса на следующем шаге.',
		);

		foreach ($expected as $key => $value) {
			self::assertSame($value, $language[$key] ?? null, $key);
			self::assertNotSame($key, $language[$key] ?? null, $key);
		}
	}

	public function testRelatedVisualContractsRemainDataDrivenAndAccessible(): void {
		$contactController = $this->read('upload/catalog/controller/information/contact.php');
		$product = $this->read('upload/catalog/view/theme/charm_by_sylora/template/product/product.twig');
		$basicCaptcha = $this->read('upload/catalog/view/theme/charm_by_sylora/template/extension/captcha/basic.twig');
		$googleCaptcha = $this->read('upload/catalog/view/theme/charm_by_sylora/template/extension/captcha/google.twig');
		$smartCaptcha = $this->read('upload/catalog/view/theme/charm_by_sylora/template/extension/captcha/smartcaptcha.twig');

		self::assertStringContainsString("resizeWithSources(\$this->config->get('config_image'), 320, 320)", $contactController);
		self::assertStringContainsString('title="{{ button_wishlist }}"', $product);
		self::assertStringContainsString('aria-label="{{ button_wishlist }}"', $product);
		self::assertStringNotContainsString('title="В избранное"', $product);
		self::assertStringContainsString('<legend class="sr-only">{{ text_captcha }}</legend>', $basicCaptcha);
		self::assertStringContainsString('<legend class="sr-only">{{ text_captcha }}</legend>', $googleCaptcha);
		self::assertStringNotContainsString('captcha-section__title', $smartCaptcha);
		self::assertStringContainsString('aria-label="{{ text_captcha }}"', $smartCaptcha);
		self::assertStringContainsString('<label class="control-label"', $smartCaptcha);
		self::assertStringContainsString('error_captcha', $smartCaptcha);
	}

	private function read(string $relativePath): string {
		$content = file_get_contents($this->root . '/' . $relativePath);

		self::assertIsString($content, $relativePath);

		return $content;
	}
}
