<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AccountUiContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testAccountFormControlsHaveMinimumTouchTarget(): void {
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$header = $this->read('upload/catalog/view/theme/charm_by_sylora/template/common/header.twig');

		self::assertMatchesRegularExpression(
			'/\.btn\s*\{[^}]*min-height: 44px;/s',
			$css
		);
		self::assertMatchesRegularExpression(
			'/#account-login \.account-auth__actions > a\s*\{[^}]*min-width: 44px;[^}]*min-height: 44px;/s',
			$css
		);
		self::assertMatchesRegularExpression('/stylesheet\.min\.css\?v=[^"\s]+/', $header);
	}

	public function testCharmAccountAndContactTemplatesDoNotUseFontAwesomeIcons(): void {
		$templates = array(
			'upload/catalog/view/theme/charm_by_sylora/template/account/address_form.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/account/address_list.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/account/wishlist.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/account/return_form.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/account/order_list.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/information/contact.twig',
		);
		$iconPattern = <<<'REGEX'
/<i\b[^>]*\bclass="[^"]*\bfa(?:-[a-z0-9-]+)?\b/i
REGEX;

		foreach ($templates as $path) {
			self::assertDoesNotMatchRegularExpression($iconPattern, $this->read($path), $path);
		}
	}

	public function testAccountLoginUsesConfirmedRussianCopy(): void {
		$language = $this->read('upload/catalog/language/ru-ru/account/login.php');
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/login.twig');

		$copy = array(
			'heading_title' => 'Войти',
			'text_account' => 'Личный кабинет',
			'text_i_am_returning_customer' => 'Вход в личный кабинет',
			'text_new_customer' => 'Впервые здесь?',
			'text_register_account' => 'Создайте аккаунт, чтобы сохранять адреса и историю покупок.',
			'text_register' => 'Зарегистрироваться',
			'text_create_account' => 'Создать аккаунт',
			'entry_email' => 'Электронная почта',
			'entry_password' => 'Пароль',
			'text_forgotten' => 'Забыли пароль?',
			'text_email_placeholder' => 'Укажите адрес',
			'text_password_placeholder' => 'Введите пароль',
		);

		foreach ($copy as $key => $value) {
			$pattern = '/\\$_\\[\\x27' . preg_quote($key, '/') . '\\x27\\]\\s*=\\s*\\x27' . preg_quote($value, '/') . '\\x27;/u';
			self::assertMatchesRegularExpression($pattern, $language, $key);
			self::assertStringContainsString('{{ ' . $key . ' }}', $template, $key);
		}
		self::assertMatchesRegularExpression(
			'/\\$_\\[\\x27error_login\\x27\\]\\s*=\\s*\\x27Проверьте электронную почту и пароль\\.\\x27;/u',
			$language
		);

		self::assertStringContainsString('{{ button_login }}', $template);
	}

	public function testAccountLoginKeepsFormAndNavigationRoutes(): void {
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/login.twig');
		$controller = $this->read('upload/catalog/controller/account/login.php');

		self::assertStringContainsString('action="{{ action }}" method="post"', $template);
		self::assertStringContainsString('name="email"', $template);
		self::assertStringContainsString('name="password"', $template);
		self::assertStringContainsString('name="redirect" value="{{ redirect }}"', $template);
		self::assertSame(2, substr_count($template, 'href="{{ register }}"'));
		self::assertStringContainsString('href="{{ forgotten }}"', $template);

		self::assertStringContainsString("\$data['action'] = \$this->url->link('account/login', '', true);", $controller);
		self::assertStringContainsString("\$data['register'] = \$this->url->link('account/register', '', true);", $controller);
		self::assertStringContainsString("\$data['forgotten'] = \$this->url->link('account/forgotten', '', true);", $controller);
		self::assertStringContainsString("\$data['text_create_account'] = \$this->language->get('text_create_account');", $controller);
		self::assertStringContainsString('&& $this->validate()', $controller);
		self::assertStringContainsString('protected function validate()', $controller);
		self::assertGreaterThan(
			strpos($template, '</header>'),
			strpos($template, '{% if error_warning %}')
		);
	}

	public function testAccountRegistrationMatchesAtelierAndKeepsOpenCartFields(): void {
		$language = $this->read('upload/catalog/language/ru-ru/account/register.php');
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/register.twig');
		$controller = $this->read('upload/catalog/controller/account/register.php');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');

		foreach (array(
			'heading_title' => 'Создать аккаунт',
			'text_account' => 'Личный кабинет',
			'entry_email' => 'Электронная почта',
			'text_form_value_placeholder' => 'Введите значение',
			'button_register' => 'Зарегистрироваться',
		) as $key => $value) {
			$pattern = '/\\$_\\[\\x27' . preg_quote($key, '/') . '\\x27\\]\\s*=\\s*\\x27' . preg_quote($value, '/') . '\\x27;/u';
			self::assertMatchesRegularExpression($pattern, $language, $key);
		}

		foreach (array('firstname', 'lastname', 'email', 'telephone', 'password', 'confirm', 'newsletter', 'agree') as $field) {
			self::assertStringContainsString('name="' . $field . '"', $template, $field);
		}

		self::assertStringContainsString('name="custom_field[', $template);
		self::assertStringContainsString('{{ captcha }}', $template);
		self::assertStringContainsString('{{ text_account_already }}', $template);
		self::assertStringContainsString('value="{{ button_register }}"', $template);
		self::assertStringContainsString("\$data['action'] = \$this->url->link('account/register', '', true);", $controller);
		self::assertStringContainsString("'account/register'", $controller);
		self::assertStringContainsString('#account-register .account-auth__register-title', $css);
		self::assertStringContainsString('grid-template-columns: minmax(0, 280px);', $css);
	}

	public function testForgottenPasswordMatchesAtelierAndKeepsRecoveryRoute(): void {
		$language = $this->read('upload/catalog/language/ru-ru/account/forgotten.php');
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/forgotten.twig');
		$controller = $this->read('upload/catalog/controller/account/forgotten.php');

		foreach (array(
			'heading_title' => 'Восстановить пароль',
			'text_email' => 'Укажите почту, которую использовали при регистрации.',
			'text_form_value_placeholder' => 'Введите значение',
			'text_back_to_login' => 'Вернуться ко входу',
			'entry_email' => 'Электронная почта',
			'error_email' => 'Электронная почта не найдена. Проверьте адрес и попробуйте снова.',
		) as $key => $value) {
			$pattern = '/\\$_\\[\\x27' . preg_quote($key, '/') . '\\x27\\]\\s*=\\s*\\x27' . preg_quote($value, '/') . '\\x27;/u';
			self::assertMatchesRegularExpression($pattern, $language, $key);
		}

		self::assertStringContainsString('action="{{ action }}" method="post"', $template);
		self::assertStringContainsString('name="email"', $template);
		self::assertStringContainsString('aria-describedby="forgotten-email-help"', $template);
		self::assertStringContainsString('href="{{ back }}" class="btn btn-default">{{ text_back_to_login }}', $template);
		self::assertStringContainsString("\$data['action'] = \$this->url->link('account/forgotten', '', true);", $controller);
		self::assertStringContainsString("\$data['back'] = \$this->url->link('account/login', '', true);", $controller);
	}

	public function testProfileAndPasswordFormsUseTheAccountShellAndKeepOpenCartFields(): void {
		$editController = $this->read('upload/catalog/controller/account/edit.php');
		$editTemplate = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/edit.twig');
		$editLanguage = $this->read('upload/catalog/language/ru-ru/account/edit.php');
		$passwordController = $this->read('upload/catalog/controller/account/password.php');
		$passwordTemplate = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/password.twig');
		$passwordLanguage = $this->read('upload/catalog/language/ru-ru/account/password.php');

		foreach (array(
			'heading_title' => 'Личные данные',
			'text_your_details' => 'Измените контактную информацию.',
			'entry_email' => 'Электронная почта',
			'button_save' => 'Сохранить',
			'error_email' => 'Введите корректный email.',
		) as $key => $value) {
			$pattern = '/\\$_\\[\\x27' . preg_quote($key, '/') . '\\x27\\]\\s*=\\s*\\x27' . preg_quote($value, '/') . '\\x27;/u';
			self::assertMatchesRegularExpression($pattern, $editLanguage, $key);
		}

		foreach (array(
			'heading_title' => 'Изменить пароль',
			'entry_password' => 'Новый пароль',
			'entry_confirm' => 'Подтвердите пароль',
			'button_save' => 'Сохранить',
			'error_confirm' => 'Пароли не совпадают. Проверьте и повторите ввод.',
		) as $key => $value) {
			$pattern = '/\\$_\\[\\x27' . preg_quote($key, '/') . '\\x27\\]\\s*=\\s*\\x27' . preg_quote($value, '/') . '\\x27;/u';
			self::assertMatchesRegularExpression($pattern, $passwordLanguage, $key);
		}

		self::assertStringContainsString("\$data['account_navigation'] = \$this->load->controller('account/navigation', array('active_route' => 'account/edit'));", $editController);
		self::assertStringContainsString("\$data['account_navigation'] = \$this->load->controller('account/navigation', array('active_route' => 'account/password'));", $passwordController);
		self::assertStringContainsString('{{ account_navigation }}', $editTemplate);
		self::assertStringContainsString('{{ account_navigation }}', $passwordTemplate);
		self::assertStringContainsString("\$data['action'] = \$this->url->link('account/edit', '', true);", $editController);
		self::assertStringContainsString("\$data['action'] = \$this->url->link('account/password', '', true);", $passwordController);
		self::assertStringContainsString('class="account-profile-form__intro">{{ text_your_details }}', $editTemplate);
		self::assertStringContainsString('name="custom_field[', $editTemplate);
		foreach (array('firstname', 'lastname', 'email', 'telephone') as $field) {
			self::assertStringContainsString('name="' . $field . '"', $editTemplate, $field);
		}
		self::assertStringContainsString('name="password"', $passwordTemplate);
		self::assertStringContainsString('name="confirm"', $passwordTemplate);
		self::assertStringContainsString('class="alert alert-danger" role="alert">{{ error_confirm }}', $passwordTemplate);
		self::assertStringContainsString('aria-describedby="password-confirm-error"', $passwordTemplate);
		self::assertStringContainsString('account-form-actions__back', $editTemplate);
		self::assertStringContainsString('account-form-actions__back', $passwordTemplate);
	}

	public function testReturnFormUsesAtelierLabelsAndKeepsItsStoreFields(): void {
		$language = $this->read('upload/catalog/language/ru-ru/account/return.php');
		$commonLanguage = $this->read('upload/catalog/language/ru-ru/ru-ru.php');
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/return_form.twig');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');

		$copy = array(
			'text_product' => 'Информация о товаре',
			'entry_model' => 'Модель',
			'entry_opened' => 'Товар был вскрыт?',
			'entry_fault_detail' => 'Комментарий',
		);

		foreach ($copy as $key => $value) {
			$pattern = '/\$_\[\x27' . preg_quote($key, '/') . '\x27\]\s*=\s*\x27' . preg_quote($value, '/') . '\x27;/u';
			self::assertMatchesRegularExpression($pattern, $language, $key);
			self::assertStringContainsString('{{ ' . $key . ' }}', $template, $key);
		}

		self::assertStringContainsString('name="product_id" value="{{ product_id }}"', $template);
		self::assertStringContainsString('name="agree" value="1"', $template);
		self::assertStringContainsString('value="{{ button_submit }}"', $template);
		self::assertStringContainsString("\$_['button_submit']         = 'Отправить заявку';", $commonLanguage);
		self::assertStringContainsString('href="{{ back }}"', $template);
		self::assertSame(2, substr_count($template, 'class="pull-right return-form__actions"'));
		self::assertStringContainsString('max-width: 765px;', $css);
		self::assertStringContainsString('width: min(280px, 100%);', $css);
	}

	public function testReturnListUsesCompactSummaryAndDetailPageKeepsCustomerData(): void {
		$listTemplate = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/return_list.twig');
		$detailTemplate = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/return_info.twig');
		$controller = $this->read('upload/catalog/controller/account/return.php');
		$russian = $this->read('upload/catalog/language/ru-ru/account/return.php');
		$english = $this->read('upload/catalog/language/en-gb/account/return.php');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');

		self::assertStringContainsString('text_atelier_return_summary|format(return.return_id, return.order_id)', $listTemplate);
		self::assertStringContainsString('{{ text_status }} {{ return.status }}', $listTemplate);
		self::assertStringContainsString('href="{{ return.href }}"', $listTemplate);
		self::assertStringContainsString('{% if pagination %}', $listTemplate);
		self::assertStringContainsString("'name'       => \$result['firstname'] . ' ' . \$result['lastname']", $controller);
		self::assertStringContainsString("'date_added' => date(\$this->language->get('date_format_short'), strtotime(\$result['date_added']))", $controller);

		foreach (array('return.return_id', 'return.order_id', 'return.status') as $dynamicListField) {
			self::assertStringContainsString($dynamicListField, $listTemplate, $dynamicListField);
		}

		foreach (array('{{ firstname }} {{ lastname }}', '{{ email }}', '{{ telephone }}', '{{ date_added }}', '{{ reason }}', '{{ product }}', '{{ model }}', '{{ quantity }}', 'for history in histories') as $preservedDetailData) {
			self::assertStringContainsString($preservedDetailData, $detailTemplate, $preservedDetailData);
		}
		self::assertSame(4, substr_count($detailTemplate, 'class="return-detail__card"'), 'return details follow the four-section Atelier layout');
		self::assertStringContainsString('<{{ detail_heading_tag }}>{{ text_atelier_return_customer }}</{{ detail_heading_tag }}>', $detailTemplate);
		self::assertStringContainsString('<{{ subheading_tag }}>{{ text_atelier_return_products }}</{{ subheading_tag }}>', $detailTemplate);
		self::assertStringContainsString('<{{ subheading_tag }}>{{ text_atelier_return_history }}</{{ subheading_tag }}>', $detailTemplate);
		self::assertStringContainsString('class="return-detail__facts"', $detailTemplate);

		foreach (array('text_atelier_return_summary', 'text_atelier_return_detail_title', 'text_atelier_return_information', 'text_atelier_return_customer', 'text_atelier_return_products', 'text_atelier_return_history') as $languageKey) {
			self::assertMatchesRegularExpression('/\\$_\\[\\x27' . preg_quote($languageKey, '/') . '\\x27\\]\\s*=\\s*\\x27[^\\x27]+\\x27;/u', $russian, $languageKey);
			self::assertMatchesRegularExpression('/\\$_\\[\\x27' . preg_quote($languageKey, '/') . '\\x27\\]\\s*=\\s*\\x27[^\\x27]+\\x27;/u', $english, $languageKey);
		}

		self::assertStringContainsString('max-width: 764px;', $css);
		self::assertStringContainsString('#account-return .return-card__summary,', $css);
		self::assertStringContainsString('#account-return .return-list__continue', $css);
		self::assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr));', $css);
		self::assertStringContainsString('#account-return .return-detail__facts { grid-template-columns: minmax(0, 1fr); gap: 4px; }', $css);
	}

	public function testOrderListAndDetailUseAtelierCardsWithoutDroppingOrderDataOrActions(): void {
		$controller = $this->read('upload/catalog/controller/account/order.php');
		$model = $this->read('upload/catalog/model/account/order.php');
		$listTemplate = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/order_list.twig');
		$detailTemplate = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/order_info.twig');
		$russian = $this->read('upload/catalog/language/ru-ru/account/order.php');
		$english = $this->read('upload/catalog/language/en-gb/account/order.php');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');

		self::assertSame(2, substr_count($controller, "'active_route' => 'account/order'"));
		self::assertStringContainsString('{{ account_navigation }}', $listTemplate);
		self::assertStringContainsString('{{ account_navigation }}', $detailTemplate);
		self::assertStringContainsString('class="order-history-card"', $listTemplate);
		self::assertStringContainsString('href="{{ order.view }}"', $listTemplate);
		self::assertStringContainsString('href="{{ continue }}"', $listTemplate);
		self::assertStringContainsString('{% if pagination %}', $listTemplate);
		self::assertStringContainsString('{{ pagination }}', $listTemplate);
		self::assertStringContainsString('{{ results }}', $listTemplate);

		foreach (array('for product in products', 'product.option', 'product.reorder', 'product.return', 'for voucher in vouchers', 'for total in totals', 'for history in histories', 'text_no_results', 'payment_address', 'shipping_address', 'order_status', 'text_current_status', 'history.status', 'history.comment', '{{ comment }}') as $preservedData) {
			self::assertStringContainsString($preservedData, $detailTemplate, $preservedData);
		}

		$expectedStatusAssignment = <<<'SOURCE'
$data['order_status'] = $this->model_account_order->getOrderStatusName((int)$order_info['order_status_id']);
SOURCE;
		self::assertStringContainsString($expectedStatusAssignment, $controller);
		self::assertStringContainsString('public function getOrderStatusName($order_status_id)', $model);
		self::assertStringContainsString('language_id = ', $model);
		self::assertStringContainsString('config_language_id', $model);
		self::assertMatchesRegularExpression('/\$_\[\x27text_current_status\x27\]\s*=\s*\x27Статус заказа\x27;/u', $russian);
		self::assertMatchesRegularExpression('/\$_\[\x27text_current_status\x27\]\s*=\s*\x27Order status\x27;/u', $english);

		foreach (array('text_order_information', 'text_delivery', 'text_order_products', 'text_order_totals', 'button_order_details', 'button_back_to_order_history') as $languageKey) {
			self::assertMatchesRegularExpression('/\$_\[\x27' . preg_quote($languageKey, '/') . '\x27\]\s*=\s*\x27[^\x27]+\x27;/u', $russian, $languageKey);
		}

		self::assertStringContainsString('max-width: 800px;', $css);
		self::assertStringContainsString('.order-history-card', $css);
		self::assertStringContainsString('.order-detail-cards', $css);
		self::assertMatchesRegularExpression('/#account-order \.order-history-card__summary\s*\{[^}]*gap:\s*0;[^}]*line-height:\s*1\.4;/s', $css);
		self::assertStringContainsString('#account-order .order-history-card__details { margin-top: 5px; }', $css);
		self::assertStringContainsString('#account-order .order-history-list__actions { display: grid; gap: 12px; width: min(280px, 100%); margin-top: 18px; }', $css);

		$desktopOrderGeometryPattern = <<<'PATTERN'
~@media\s*\(min-width:\s*1201px\)\s*\{\s*#account-order\.account-order-list \.atelier-account-page-content,\s*#account-order\.order-info-page \.order-detail-page__content\s*\{\s*--account-order-content-width:\s*800px;\s*--account-order-content-offset:\s*41px;\s*--account-order-content-padding:\s*18px;\s*box-sizing:\s*border-box;\s*width:\s*min\(100%,\s*var\(--account-order-content-width\)\);\s*max-width:\s*var\(--account-order-content-width\);\s*margin-left:\s*var\(--account-order-content-offset\);\s*padding:\s*2px\s+var\(--account-order-content-padding\)\s+var\(--account-order-content-padding\);\s*\}\s*\}~s
PATTERN;
		self::assertMatchesRegularExpression($desktopOrderGeometryPattern, $css, 'desktop order wrappers match Figma 85:732 and 85:767 geometry');
	}

	public function testNewsletterMatchesAtelierCopyAndKeepsSubscriptionFormContract(): void {
		$controller = $this->read('upload/catalog/controller/account/newsletter.php');
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/newsletter.twig');
		$russian = $this->read('upload/catalog/language/ru-ru/account/newsletter.php');
		$english = $this->read('upload/catalog/language/en-gb/account/newsletter.php');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');

		$copy = array(
			'heading_title' => 'Рассылка',
			'entry_newsletter' => 'Получать новости и предложения магазина?',
			'button_save' => 'Сохранить',
		);

		foreach ($copy as $key => $value) {
			$pattern = '/\$_\[\x27' . preg_quote($key, '/') . '\x27\]\s*=\s*\x27' . preg_quote($value, '/') . '\x27;/u';
			self::assertMatchesRegularExpression($pattern, $russian, $key);
			self::assertStringContainsString('{{ ' . $key . ' }}', $template, $key);
		}

		self::assertStringContainsString("\$_['heading_title']    = 'Newsletter';", $english);
		self::assertStringContainsString('action="{{ action }}" method="post"', $template);
		self::assertStringContainsString('name="newsletter" value="1"', $template);
		self::assertStringContainsString('name="newsletter" value="0"', $template);
		self::assertStringContainsString('href="{{ back }}"', $template);
		self::assertStringContainsString("\$data['action'] = \$this->url->link('account/newsletter', '', true);", $controller);
		self::assertStringContainsString("\$data['account_navigation'] = \$this->load->controller('account/navigation', array('active_route' => 'account/newsletter'));", $controller);
		self::assertStringContainsString('{{ account_navigation }}', $template);
		self::assertStringContainsString('$this->model_account_customer->editNewsletter($this->request->post[\'newsletter\']);', $controller);
		self::assertStringContainsString('class="newsletter-option"', $template);
		self::assertStringContainsString('newsletter-form__save"', $template);
		self::assertStringContainsString('#account-newsletter .newsletter-form__options', $css);
		self::assertStringContainsString('#account-newsletter .newsletter-form__save { width: 100%;', $css);

		$newsletterOptionStylePattern = <<<'PATTERN'
~#account-newsletter \.newsletter-option > span\s*\{\s*font:\s*300 21px\s*/\s*1\.2 'Atelier Cormorant', Georgia, serif;\s*\}~s
PATTERN;
		self::assertMatchesRegularExpression($newsletterOptionStylePattern, $css, 'newsletter choices use the Atelier Cormorant 21px Figma style');
	}

	public function testAddressCardActionsStayOutsideCardAndDefaultCheckboxKeepsScalarPostContract(): void {
		$listTemplate = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/address_list.twig');
		$formTemplate = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/address_form.twig');
		$controller = $this->read('upload/catalog/controller/account/address.php');
		$model = $this->read('upload/catalog/model/account/address.php');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
		$russian = $this->read('upload/catalog/language/ru-ru/account/address.php');
		$english = $this->read('upload/catalog/language/en-gb/account/address.php');

		$cardEnd = strpos($listTemplate, '</article>');
		$actionsStart = strpos($listTemplate, '<form action="{{ result.delete }}"');
		self::assertIsInt($cardEnd);
		self::assertIsInt($actionsStart);
		self::assertLessThan($actionsStart, $cardEnd, 'address edit/delete form follows the address card');
		self::assertStringContainsString('href="{{ result.update }}"', $listTemplate);
		self::assertStringContainsString('href="{{ result.delete }}"', $listTemplate);
		self::assertStringContainsString('data-confirm="{{ text_confirm }}"', $listTemplate);
		self::assertStringContainsString('.address-list__item { display: grid; gap: 12px;', $css);

		$defaultControlPattern = <<<'PATTERN'
~<input type="hidden" name="default" value="0"\s*/>\s*<label class="address-form__checkbox" for="input-default">\s*<input type="checkbox" name="default" value="1" id="input-default"\{% if default %\} checked="checked"\{% endif %\} />\s*<span>\{\{ entry_default \}\}</span>~s
PATTERN;
		self::assertMatchesRegularExpression($defaultControlPattern, $formTemplate);
		self::assertStringContainsString("isset(\$this->request->post['default'])", $controller);
		self::assertSame(2, substr_count($model, "!empty(\$data['default'])"));
		self::assertSame('0', $this->parseDefaultPost('default=0'));
		self::assertSame('1', $this->parseDefaultPost('default=0&default=1'));
		self::assertStringContainsString("\$_['entry_default']      = 'Использовать как основной адрес';", $russian);
		self::assertStringContainsString("\$_['entry_default'] = 'Use as the primary address';", $english);
		self::assertStringContainsString('#account-address .address-form .buttons {', $css);
		self::assertStringContainsString('#account-address .address-form .buttons > .pull-right { order: -1; }', $css);
		self::assertStringContainsString('#account-address .address-form .buttons .pull-right > .btn-primary { width: min(280px, 100%); }', $css);
		self::assertStringContainsString('--address-form-desktop-width: 765px;', $css);
		self::assertMatchesRegularExpression(
			'/@media \\(min-width: 768px\\) \\{\\s*#account-address form\\.address-form \\{ max-width: var\\(--address-form-desktop-width\\); \\}/s',
			$css,
			'desktop address form matches the 765px Figma surface width'
		);
	}

	public function testWishlistEmptyActionSitsOutsideMessageAndFilledStateHasSubtitleDivider(): void {
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/wishlist.twig');
		$russian = $this->read('upload/catalog/language/ru-ru/account/wishlist.php');
		$english = $this->read('upload/catalog/language/en-gb/account/wishlist.php');
		$css = $this->read('upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');

		$emptyCardEnd = strpos($template, "</div>\n      <a href=\"{{ catalog }}\" class=\"btn btn-primary wishlist-empty__catalog\">");
		$catalogAction = strpos($template, '<a href="{{ catalog }}" class="btn btn-primary wishlist-empty__catalog">');
		self::assertIsInt($emptyCardEnd);
		self::assertIsInt($catalogAction);
		self::assertLessThan($catalogAction, $emptyCardEnd, 'catalog action follows the empty message card');
		self::assertStringContainsString('{{ text_saved_items }}', $template);
		self::assertLessThan(strpos($template, '<div class="wishlist-grid">'), strpos($template, '<div class="wishlist-intro">'));
		self::assertStringContainsString('href="{{ catalog }}"', $template);
		self::assertStringContainsString("\$_['text_saved_items'] = 'Сохранённые изделия';", $russian);
		self::assertStringContainsString("\$_['text_saved_items'] = 'Saved items';", $english);
		self::assertStringContainsString('wishlist-empty__catalog { display: flex;', $css);
		self::assertStringContainsString('wishlist-intro { max-width: 800px;', $css);
		self::assertStringContainsString('border-bottom: 1px solid var(--account-section-rule);', $css);
	}

	private function parseDefaultPost(string $query): string {
		parse_str($query, $parsed);

		return $parsed['default'];
	}

	public function testAccountNavigationRendersNativeDisclosureWithLocalizedLabels(): void {
		$template = $this->read('upload/catalog/view/theme/charm_by_sylora/template/account/navigation.twig');
		$russian = $this->read('upload/catalog/language/ru-ru/account/navigation.php');
		$english = $this->read('upload/catalog/language/en-gb/account/navigation.php');

		self::assertSame(2, substr_count($template, '<details '));
		self::assertStringContainsString('<summary>{{ text_more }}</summary>', $template);
		self::assertStringContainsString('<summary>{{ current_label }}</summary>', $template);
		self::assertStringContainsString('aria-current="page"', $template);
		self::assertStringContainsString("['text_atelier_nav_more'] = 'Ещё';", $russian);
		self::assertStringContainsString("['text_atelier_nav_more'] = 'More';", $english);
	}

	private function read(string $path): string {
		$content = file_get_contents($this->root . '/' . $path);

		self::assertIsString($content);

		return $content;
	}
}
