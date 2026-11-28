<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CheckoutBuyerUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testBuyerEntryIsLocalizedAndProgressivelyDisclosesExistingOptions(): void {
		$language = array();
		$_ = &$language;
		require $this->root . '/upload/catalog/language/ru-ru/checkout/checkout.php';

		self::assertSame('Войти', $language['text_checkout_buyer_login_mobile'] ?? null);
		self::assertSame('или продолжить без регистрации', $language['text_checkout_buyer_guest_mobile'] ?? null);
		self::assertSame('или создать аккаунт', $language['text_checkout_buyer_account_mobile'] ?? null);
		self::assertSame('Авторизация', $language['text_checkout_buyer_login_desktop'] ?? null);
		self::assertSame('или продолжение как гость', $language['text_checkout_buyer_guest_desktop'] ?? null);
		self::assertSame('или создать учетную запись', $language['text_checkout_buyer_account_desktop'] ?? null);
		self::assertSame('Электронная почта', $language['text_checkout_buyer_email'] ?? null);
		self::assertSame('Введите email', $language['text_checkout_buyer_email_placeholder'] ?? null);

		$controller = $this->read('upload/catalog/controller/checkout/login.php');
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/checkout/login.twig');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$checkoutTemplate = $this->read('upload/catalog/view/theme/charm_by_sylora/template/checkout/checkout.twig');

		self::assertStringContainsString("\$data['text_checkout_buyer_login_mobile'] = \$this->language->get('text_checkout_buyer_login_mobile');", $controller);
		self::assertStringContainsString("\$data['checkout_guest'] = (\$this->config->get('config_checkout_guest') && !\$this->config->get('config_customer_price') && !\$this->cart->hasDownload());", $controller);
		self::assertStringContainsString("if (\$data['checkout_guest'])", $controller);
		self::assertStringContainsString("id=\"collapse-checkout-option\"", $checkoutTemplate);
		self::assertStringContainsString('id="button-buyer-continue"', $template);
		self::assertStringContainsString('id="button-buyer-login"', $template);
		self::assertStringContainsString('id="checkout-buyer-options"', $template);
		self::assertStringContainsString('id="input-email"', $template);
		self::assertStringContainsString('name="account" value="register"', $template);
		self::assertStringContainsString('name="account" value="guest"', $template);
		self::assertStringContainsString('id="button-account"', $template);
		self::assertStringContainsString('id="input-password"', $template);
		self::assertStringContainsString('id="button-login"', $template);
		self::assertStringContainsString('.checkout-buyer:not(.checkout-buyer--expanded) .checkout-buyer__email { display: none; }', $css);
		self::assertStringContainsString('.panel-title a[href="#collapse-checkout-option"]', $css);
		self::assertStringContainsString('#checkout-checkout .checkout-accordion > .panel:has(#collapse-checkout-option.in) .panel-title .atelier-icon--chevron-down', $css);
		self::assertStringContainsString('#checkout-checkout .checkout-buyer__login-toggle:hover', $css);
		self::assertStringContainsString('#checkout-checkout .checkout-head__intro--desktop', $css);
		self::assertStringContainsString('#checkout-checkout .checkout-summary__note--desktop', $css);
		self::assertStringContainsString('@media (max-width: 767px)', $css);
		self::assertStringContainsString('@media (min-width: 768px)', $css);
	}

	public function testBuyerDisclosurePreservesLoginAndAccountRequestsAndEmail(): void {
		$javascript = $this->read('upload/catalog/view/javascript/checkout.js');
		$loginController = $this->read('upload/catalog/controller/checkout/login.php');
		$registerController = $this->read('upload/catalog/controller/checkout/register.php');
		$guestController = $this->read('upload/catalog/controller/checkout/guest.php');

		self::assertStringContainsString("'#button-buyer-continue, #button-buyer-login'", $javascript);
		self::assertStringContainsString("$('#button-account').button('loading')", $javascript);
		self::assertStringContainsString("url: 'index.php?route=checkout/login/save'", $javascript);
		self::assertStringContainsString("url: 'index.php?route=checkout/register/save'", $javascript);
		self::assertStringContainsString("url: 'index.php?route=checkout/guest/save'", $javascript);
		self::assertStringContainsString("$('#collapse-payment-address .panel-body #input-payment-email').val(accountEmail);", $javascript);
		self::assertStringContainsString("\$json['error']['warning']", $loginController);
		self::assertStringContainsString("\$json['error']['warning']", $registerController);
		self::assertStringContainsString("\$json['error']['email']", $guestController);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
