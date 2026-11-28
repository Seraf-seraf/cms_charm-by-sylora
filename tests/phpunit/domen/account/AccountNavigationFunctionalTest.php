<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/TestBootstrap.php';

use PHPUnit\Framework\TestCase;

final class AccountNavigationFunctionalTest extends TestCase {
	private const MAIN_ROUTES = array(
		'account/edit', 'account/address', 'account/wishlist', 'account/order',
		'account/return', 'account/password', 'account/newsletter',
	);
	private const MORE_ROUTES = array(
		'account/account', 'account/download', 'account/recurring',
		'account/reward', 'account/transaction', 'account/logout',
	);
	private const PASSWORD = 'Synthetic-account-password';
	private const HTTP_TIMEOUT_SECONDS = 10;
	private static TestBootstrap $store;
	private static mysqli $database;
	private static string $email;
	private static int $customerId;
	private static int $layoutId;
	private static int $accountModuleId;
	private static int $accountSettingId;
	private static int $htmlModuleId;
	private static int $orderId;
	private static int $returnId;
	private static int $returnStatusId;
	private CurlHandle $http;

	public static function setUpBeforeClass(): void {
		self::$store = TestBootstrap::shared();
		if (DB_DATABASE !== 'charm_functional' || DB_PREFIX !== '' || parse_url(HTTP_SERVER, PHP_URL_HOST) !== '127.0.0.1') {
			throw new RuntimeException('Для сценария требуется изолированная тестовая витрина.');
		}

		$db = self::$database = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
		$db->set_charset('utf8mb4');
		self::$email = 'account-navigation-' . bin2hex(random_bytes(5)) . '@example.invalid';
		$salt = bin2hex(random_bytes(4));
		$passwordHash = sha1($salt . sha1($salt . sha1(self::PASSWORD)));

		$db->execute_query(
			"INSERT INTO customer (customer_group_id, language_id, firstname, lastname, email, telephone, fax, password, salt, custom_field, ip, status, safe, token, code, date_added)
			VALUES (1, 1, 'Тест', 'Навигация', ?, '80000000000', '', ?, ?, '{}', '', 1, 0, '', '', NOW())",
			array(self::$email, $passwordHash, $salt)
		);
		self::$customerId = (int)$db->insert_id;

		$db->query("INSERT INTO layout (name) VALUES ('Synthetic account layout')");
		self::$layoutId = (int)$db->insert_id;
		$db->execute_query("INSERT INTO layout_route (layout_id, store_id, route) VALUES (?, 0, 'account/%')", array(self::$layoutId));
		$db->query("INSERT INTO setting (store_id, code, `key`, value, serialized) VALUES (0, 'module_account', 'module_account_status', '1', 0)");
		self::$accountSettingId = (int)$db->insert_id;
		$db->query("INSERT INTO module (name, code, setting) VALUES ('Synthetic account instance', 'account', '{\"status\":1}')");
		self::$accountModuleId = (int)$db->insert_id;
		$db->execute_query(
			"INSERT INTO module (name, code, setting) VALUES ('Synthetic right module', 'html', ?)",
			array(json_encode(array(
				'status' => 1,
				'module_description' => array(1 => array('title' => '', 'description' => '<p id="synthetic-account-layout-module">Synthetic right module</p>')),
			), JSON_THROW_ON_ERROR))
		);
		self::$htmlModuleId = (int)$db->insert_id;
		$db->execute_query(
			"INSERT INTO layout_module (layout_id, code, position, sort_order) VALUES (?, 'account', 'column_right', 0), (?, ?, 'column_right', 1), (?, ?, 'column_right', 2)",
			array(self::$layoutId, self::$layoutId, 'account.' . self::$accountModuleId, self::$layoutId, 'html.' . self::$htmlModuleId)
		);

		$db->execute_query(
			"INSERT INTO `order` (customer_id, invoice_prefix, store_name, store_url, firstname, lastname, email, telephone, fax, custom_field,
				payment_firstname, payment_lastname, payment_company, payment_address_1, payment_address_2, payment_city, payment_postcode, payment_country,
				payment_country_id, payment_zone, payment_zone_id, payment_address_format, payment_custom_field, payment_method, payment_code,
				shipping_firstname, shipping_lastname, shipping_company, shipping_address_1, shipping_address_2, shipping_city, shipping_postcode, shipping_country,
				shipping_country_id, shipping_zone, shipping_zone_id, shipping_address_format, shipping_custom_field, shipping_method, shipping_code,
				comment, order_status_id, affiliate_id, commission, marketing_id, tracking, language_id, currency_id, currency_code,
				ip, forwarded_ip, user_agent, accept_language, date_added, date_modified)
			VALUES (?, '', 'Synthetic store', ?, 'Тест', 'Навигация', ?, '', '', '{}',
				'', '', '', '', '', '', '', '', 0, '', 0, '', '{}', '', '',
				'', '', '', '', '', '', '', '', 0, '', 0, '', '{}', '', '',
				'', 1, 0, 0, 0, '', 1, 1, 'RUB', '', '', '', '', NOW(), NOW())",
			array(self::$customerId, HTTP_SERVER, self::$email)
		);
		self::$orderId = (int)$db->insert_id;
		$db->query("INSERT INTO return_status (language_id, name) VALUES (1, 'Тестовый возврат')");
		self::$returnStatusId = (int)$db->insert_id;
		$db->execute_query(
			"INSERT INTO `return` (order_id, product_id, customer_id, firstname, lastname, email, telephone, product, model, quantity, opened,
				return_reason_id, return_action_id, return_status_id, comment, date_ordered, date_added, date_modified)
			VALUES (?, 0, ?, 'Тест', 'Навигация', ?, '', 'Synthetic item', 'FIXTURE', 1, 0, 0, 0, ?, '', CURDATE(), NOW(), NOW())",
			array(self::$orderId, self::$customerId, self::$email, self::$returnStatusId)
		);
		self::$returnId = (int)$db->insert_id;
	}

	public static function tearDownAfterClass(): void {
		$db = self::$database;
		$db->execute_query('DELETE FROM layout_module WHERE layout_id = ?', array(self::$layoutId));
		$db->execute_query('DELETE FROM layout_route WHERE layout_id = ?', array(self::$layoutId));
		$db->execute_query('DELETE FROM layout WHERE layout_id = ?', array(self::$layoutId));
		$db->execute_query('DELETE FROM module WHERE module_id IN (?, ?)', array(self::$accountModuleId, self::$htmlModuleId));
		$db->execute_query('DELETE FROM setting WHERE setting_id = ?', array(self::$accountSettingId));
		$db->execute_query('DELETE FROM `return` WHERE return_id = ?', array(self::$returnId));
		$db->execute_query('DELETE FROM return_status WHERE return_status_id = ? AND language_id = 1', array(self::$returnStatusId));
		$db->execute_query('DELETE FROM `order` WHERE order_id = ?', array(self::$orderId));
		$db->execute_query('DELETE FROM customer_login WHERE email = ?', array(self::$email));
		$db->execute_query('DELETE FROM customer_ip WHERE customer_id = ?', array(self::$customerId));
		$db->execute_query('DELETE FROM customer WHERE customer_id = ?', array(self::$customerId));
		$db->close();
	}

	protected function setUp(): void {
		$handle = curl_init();
		self::assertInstanceOf(CurlHandle::class, $handle);
		$this->http = $handle;
		curl_setopt_array($this->http, array(
			CURLOPT_COOKIEFILE => '',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
		));
	}

	public function testTargetSectionsReplaceBothAccountModuleFormsAndKeepTheOtherModule(): void {
		$this->logIn();
		$pages = array(
			array('account/edit', array(), 'account/edit'),
			array('account/address', array(), 'account/address'),
			array('account/address/add', array(), 'account/address'),
			array('account/wishlist', array(), 'account/wishlist'),
			array('account/order', array(), 'account/order'),
			array('account/order/info', array('order_id' => self::$orderId), 'account/order'),
			array('account/return', array(), 'account/return'),
			array('account/return/add', array(), 'account/return'),
			array('account/return/info', array('return_id' => self::$returnId), 'account/return'),
			array('account/password', array(), 'account/password'),
			array('account/newsletter', array(), 'account/newsletter'),
		);

		foreach ($pages as [$route, $query, $activeRoute]) {
			$page = $this->request($route, $query);
			self::assertSame(0, $this->countAccountModules($page), $route);
			self::assertSame(1, $page->query('//*[@id="column-right"]//*[@id="synthetic-account-layout-module"]')->length, $route);
			self::assertSame(1, $page->query('//*[@id="content" and @class="col-sm-9"]')->length, $route);
			self::assertSame(1, $page->query('//*[@id="content"]//h1')->length, $route);
			self::assertSame(array($activeRoute, $activeRoute), $this->routes($page, '//header[@class="atelier-account-navigation"]//a[@aria-current="page"]'), $route);
		}
	}

	public function testDisclosureLinksKeepAllAccountDestinationsAndNewsletterCanBeSaved(): void {
		$this->logIn();
		$page = $this->request('account/newsletter');
		$shell = '//header[@class="atelier-account-navigation"]';
		self::assertSame(self::MAIN_ROUTES, $this->routes($page, $shell . '//nav//a[not(ancestor::details)]'));
		self::assertSame(self::MORE_ROUTES, $this->routes($page, $shell . '//nav//details//a'));
		self::assertSame(array_merge(self::MAIN_ROUTES, self::MORE_ROUTES), $this->routes($page, $shell . '/details//a'));
		self::assertSame('Ещё', trim($page->evaluate('string(' . $shell . '//nav//details/summary)')));
		self::assertSame('Подписка', trim($page->evaluate('string(' . $shell . '/details/summary)')));
		self::assertSame(array('account/account'), $this->routes($page, '//a[@class="newsletter-form__back"]'));

		$confirmation = $this->request('account/newsletter', array(), array('newsletter' => '1'));
		self::assertSame(1, $confirmation->query('//*[@id="account-account"]')->length);
		self::assertStringContainsString('Настройки рассылки сохранены.', $confirmation->evaluate('string(//*[@id="account-account"])'));
		$page = $this->request('account/newsletter');
		self::assertSame(1, $page->query('//input[@name="newsletter" and @value="1" and @checked]')->length);

		foreach (self::MORE_ROUTES as $route) {
			$page = $this->request($route);
			self::assertSame(0, $page->query('//*[@id="account-login"]')->length, $route);
			parse_str((string)parse_url(curl_getinfo($this->http, CURLINFO_EFFECTIVE_URL), PHP_URL_QUERY), $query);
			self::assertSame($route, $query['route'] ?? null);
		}

		self::assertSame(1, $this->request('account/newsletter')->query('//*[@id="account-login"]')->length);
	}

	public function testAccountOnlyLayoutRestoresWidthAndFallbackStaysAvailable(): void {
		$login = $this->request('account/login');
		self::assertSame(0, $login->query('//header[@class="atelier-account-navigation"]')->length);

		$guestReturn = $this->request('account/return/add');
		self::assertSame(0, $guestReturn->query('//header[@class="atelier-account-navigation"]')->length);
		self::assertSame(1, $guestReturn->query('//form[contains(@class, "return-form")]')->length);
		// An enabled account instance renders through both the global and instance paths.
		self::assertSame(3, $this->countAccountModules($guestReturn));

		$this->logIn();
		$dashboard = $this->request('account/account');
		self::assertSame(0, $this->countAccountModules($dashboard));
		self::assertSame(1, $dashboard->query('//*[@id="column-right"]//*[@id="synthetic-account-layout-module"]')->length);
		self::assertSame(array('account/logout'), $this->routes($dashboard, '//*[@id="content"]//a[contains(@href, "route=account/logout")]'));
		self::assertSame(0, $dashboard->query('//header[@class="atelier-account-navigation"]')->length);

		$moduleCode = 'html.' . self::$htmlModuleId;
		self::$database->execute_query('DELETE FROM layout_module WHERE layout_id = ? AND code = ?', array(self::$layoutId, $moduleCode));
		try {
			foreach (array('account/account', 'account/newsletter') as $route) {
				$page = $this->request($route);
				self::assertSame(0, $page->query('//*[@id="column-right"]')->length, $route);
				self::assertSame(1, $page->query('//*[@id="content" and @class="col-sm-12"]')->length, $route);
			}
		} finally {
			self::$database->execute_query("INSERT INTO layout_module (layout_id, code, position, sort_order) VALUES (?, ?, 'column_right', 2)", array(self::$layoutId, $moduleCode));
		}
	}

	private function logIn(): void {
		$page = $this->request('account/login', array(), array('email' => self::$email, 'password' => self::PASSWORD));
		self::assertSame(1, $page->query('//*[@id="account-account"]')->length, 'Тестовый покупатель должен успешно войти.');
	}

	private function request(string $route, array $query = array(), ?array $data = null): DOMXPath {
		curl_setopt($this->http, CURLOPT_URL, self::$store->getRouteUrl($route, $query));
		if ($data === null) {
			curl_setopt($this->http, CURLOPT_HTTPGET, true);
		} else {
			curl_setopt($this->http, CURLOPT_POSTFIELDS, http_build_query($data));
		}
		$html = curl_exec($this->http);
		self::assertIsString($html, curl_error($this->http));
		self::assertSame(200, curl_getinfo($this->http, CURLINFO_RESPONSE_CODE), $route);

		$document = new DOMDocument();
		$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
		return new DOMXPath($document);
	}

	private function countAccountModules(DOMXPath $page): int {
		return $page->query('//*[@id="column-right"]//nav[contains(concat(" ", normalize-space(@class), " "), " account-module ")]')->length;
	}

	private function routes(DOMXPath $page, string $expression): array {
		$routes = array();
		foreach ($page->query($expression) as $link) {
			parse_str((string)parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
			$routes[] = $query['route'] ?? null;
		}

		return $routes;
	}
}
