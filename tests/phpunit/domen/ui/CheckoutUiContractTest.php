<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CheckoutUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testCheckoutIntroIsTranslatedAndControlsHaveMinimumTouchTarget(): void {
		$language = array();
		$_ = &$language;
		require $this->root . '/upload/catalog/language/ru-ru/checkout/checkout.php';

		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$header = $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/header.twig');
		$defaultHeader = $this->read('upload/catalog/view/theme/default/template/common/header.twig');
		$defaultStylesheet = $this->read('upload/catalog/view/theme/default/stylesheet/stylesheet.css');

		self::assertSame(
			'Выберите удобный вариант оформления заказа: без регистрации или с созданием учетной записи.',
			$language['text_checkout_intro'] ?? null
		);
		self::assertSame(
			'Покупатель, адрес, способ доставки, оплата и подтверждение.',
			$language['text_checkout_intro_mobile'] ?? null
		);
		self::assertSame(
			'Укажите данные, выберите доставку и способ оплаты.',
			$language['text_checkout_intro_desktop'] ?? null
		);
		self::assertSame(
			'Стоимость доставки зависит от выбранного способа.',
			$language['text_checkout_summary_note_mobile'] ?? null
		);
		self::assertSame(
			'Проверьте данные перед подтверждением заказа.',
			$language['text_checkout_summary_note_desktop'] ?? null
		);
		self::assertMatchesRegularExpression(
			'/\.form-control\s*\{[^}]*min-height: 44px;/s',
			$css
		);
		self::assertSame(1, preg_match('/stylesheet\.min\.css\?v=([\w.-]+)"/', $header, $version));
		self::assertStringContainsString('stylesheet.css?v=' . $version[1] . '"', $defaultHeader);
		self::assertStringContainsString("stylesheet.css?v=" . $version[1] . "'", $defaultStylesheet);
	}

	public function testCheckoutHeaderMatchesAtelierWithoutReplacingStoreIntro(): void {
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/checkout/checkout.twig');

		self::assertStringContainsString('<h1>{{ heading_title }}</h1>', $template);
		self::assertStringContainsString('<p class="checkout-head__intro">', $template);
		self::assertStringContainsString('{{ text_checkout_intro_mobile }}', $template);
		self::assertStringContainsString('{{ text_checkout_intro_desktop }}', $template);
		self::assertStringContainsString('{{ text_checkout_summary_note_mobile }}', $template);
		self::assertStringContainsString('{{ text_checkout_summary_note_desktop }}', $template);
		self::assertStringContainsString('color: var(--checkout-muted);', $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css'));
		self::assertStringNotContainsString('Покупка', $template);
	}

	public function testShippingQuoteModalUsesThemeControls(): void {
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$shipping = $this->read('upload/catalog/view/theme/charm_by_sylora/template/extension/total/shipping.twig');
		$cart = $this->read('upload/catalog/view/javascript/cart.js');
		$cdek = $this->read('upload/catalog/view/javascript/shipping/cdek_official.js');

		self::assertMatchesRegularExpression(
			'/#modal-shipping \.modal-content\s*\{[^}]*border: 1px solid var\(--color-border\);[^}]*box-shadow:/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.shipping-method-card\s*\{[^}]*grid-template-columns: auto minmax\(0, 1fr\) auto;[^}]*min-height: 52px;/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.shipping-method-card__control\s*\{[^}]*accent-color: var\(--color-text\);/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/#modal-shipping \.modal-footer\s*\{[^}]*flex-wrap: wrap;[^}]*gap: 8px;/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.shipping-method-card:has\(\.shipping-method-card__control:focus-visible\)\s*\{[^}]*outline: 2px solid var\(--color-focus-ring\);/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.shipping-method-card:has\(\.shipping-method-card__control:disabled\)\s*\{[^}]*cursor: not-allowed;[^}]*opacity: \.55;/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/@media \(max-width: 420px\)\s*\{\s*#modal-shipping \.modal-footer\s*\{[^}]*grid-template-columns: minmax\(0, 1fr\);/s',
			$css
		);
		self::assertStringNotContainsString('<style data-cdek>', $cdek);
		self::assertStringContainsString('type="application/json"', $shipping);
		self::assertStringNotContainsString('syncShippingButton', $shipping);
		self::assertStringContainsString("$('input[name=\\'shipping_method\\']:checked').length === 0", $cart);
		self::assertStringContainsString('syncShippingButton();', $cart);
		self::assertMatchesRegularExpression(
			'/\.btn:disabled, \.btn\.disabled\s*\{[^}]*cursor: not-allowed;[^}]*opacity: \.55;/s',
			$css
		);
	}

	public function testCheckoutPanelsUseFiveConceptualStages(): void {
		$language = array();
		$_ = &$language;
		require $this->root . '/upload/catalog/language/ru-ru/checkout/checkout.php';

		$controller = $this->read('upload/catalog/controller/checkout/checkout.php');
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/checkout/checkout.twig');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$expectedHeadings = array(
			'text_checkout_option' => '%s Покупатель',
			'text_checkout_account' => 'Данные аккаунта',
			'text_checkout_payment_address' => 'Адрес плательщика',
			'text_checkout_shipping_address' => '%s Адрес доставки',
			'text_checkout_shipping_method' => '%s Способ доставки',
			'text_checkout_payment_method' => '%s Оплата',
			'text_checkout_confirm' => '%s Подтверждение'
		);

		foreach ($expectedHeadings as $key => $heading) {
			self::assertSame($heading, $language[$key] ?? null);
		}

		$fixedStageNumbers = array(
			'text_checkout_option' => '01',
			'text_checkout_shipping_address' => '02',
			'text_checkout_shipping_method' => '03'
		);

		foreach ($fixedStageNumbers as $key => $number) {
			$assignment = sprintf(
				'$data[\'%1$s\'] = sprintf($this->language->get(\'%1$s\'), %2$s);',
				$key,
				var_export($number, true)
			);

			self::assertStringContainsString($assignment, $controller);
		}

		self::assertStringContainsString("\$data['text_checkout_account'] = \$this->language->get('text_checkout_account');", $controller);
		self::assertStringContainsString("\$data['text_checkout_payment_address'] = \$this->language->get('text_checkout_payment_address');", $controller);

		$conditionalStageAssignments = array(
			"\$data['text_checkout_payment_method'] = sprintf(\$this->language->get('text_checkout_payment_method'), '04');",
			"\$data['text_checkout_confirm'] = sprintf(\$this->language->get('text_checkout_confirm'), '05');",
			"\$data['text_checkout_payment_method'] = sprintf(\$this->language->get('text_checkout_payment_method'), '02');",
			"\$data['text_checkout_confirm'] = sprintf(\$this->language->get('text_checkout_confirm'), '03');"
		);

		foreach ($conditionalStageAssignments as $assignment) {
			self::assertStringContainsString($assignment, $controller);
		}

		self::assertStringContainsString('id="collapse-checkout-option"', $template);
		self::assertStringContainsString('id="collapse-payment-address"', $template);
		self::assertStringContainsString('checkout-account-panel--conditional', $template);
		self::assertStringContainsString('checkout-account-panel--conditional:has(#collapse-payment-address .panel-body:empty)', $css);
		self::assertStringContainsString('{{ text_checkout_option }}', $template);
		self::assertStringContainsString('{{ text_checkout_account }}', $template);
		self::assertStringContainsString('{{ text_checkout_payment_address }}', $template);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
