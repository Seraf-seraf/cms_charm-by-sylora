<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CheckoutFragmentNonceTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
		require_once $this->root . '/upload/system/library/security/checkout_fragment_nonce.php';
	}

	public function testOnlyExactHtmlFragmentRouteAndMethodPairsReceiveNoStore(): void {
		$allowed = array(
			'checkout/login' => 'GET',
			'checkout/guest' => 'GET',
			'checkout/register' => 'GET',
			'checkout/guest_shipping' => 'GET',
			'checkout/payment_address' => 'GET',
			'checkout/shipping_address' => 'GET',
			'checkout/shipping_method' => 'GET',
			'checkout/payment_method' => 'GET',
			'checkout/confirm' => 'GET',
			'extension/payment/opayo/getForm' => 'GET',
			'extension/payment/cardinity/threeDSecureForm' => 'POST',
			'extension/payment/cardinity/threeDSecureFormV2' => 'POST',
		);

		foreach ($allowed as $route => $method) {
			self::assertTrue(CheckoutFragmentNonce::isNoStoreHtmlResponse($route, $method), $route . ' ' . $method);
			self::assertFalse(CheckoutFragmentNonce::isNoStoreHtmlResponse($route, $method === 'GET' ? 'POST' : 'GET'), $route . ' wrong method');
		}

		foreach (array(
			'checkout/guest/save',
			'checkout/checkout/country',
			'checkout/checkout/customfield',
			'extension/payment/cod/confirm',
			'extension/payment/cardinity/confirm',
			'extension/payment/cardinity/threeDSecureForm/extra',
		) as $route) {
			self::assertFalse(CheckoutFragmentNonce::isNoStoreHtmlResponse($route, 'GET'), $route);
			self::assertFalse(CheckoutFragmentNonce::isNoStoreHtmlResponse($route, 'POST'), $route);
		}
	}

	public function testParentNonceRequiresExactAjaxRequestAndCanonicalSixteenByteBase64(): void {
		$nonce = base64_encode(str_repeat("\0", 16));
		$request = array(
			'get' => array('route' => 'checkout/guest'),
			'server' => array(
				'REQUEST_METHOD' => 'GET',
				'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
				'HTTP_X_CHECKOUT_CSP_NONCE' => $nonce,
			),
		);

		self::assertSame($nonce, CheckoutFragmentNonce::getParentNonce($request['get'], $request['server']));

		foreach (array('', 'short', $nonce . '=', $nonce . '==', "\n" . $nonce) as $invalidNonce) {
			$server = $request['server'];
			$server['HTTP_X_CHECKOUT_CSP_NONCE'] = $invalidNonce;
			self::assertNull(CheckoutFragmentNonce::getParentNonce($request['get'], $server), 'Rejected malformed nonce');
		}

		$server = $request['server'];
		unset($server['HTTP_X_REQUESTED_WITH']);
		self::assertNull(CheckoutFragmentNonce::getParentNonce($request['get'], $server), 'Rejected non-XHR request');

		$server = $request['server'];
		$server['HTTP_X_REQUESTED_WITH'] = 'fetch';
		self::assertNull(CheckoutFragmentNonce::getParentNonce($request['get'], $server), 'Rejected non-jQuery XHR marker');

		$server = $request['server'];
		$server['REQUEST_METHOD'] = 'POST';
		self::assertNull(CheckoutFragmentNonce::getParentNonce($request['get'], $server), 'Rejected wrong method');

		$get = array('route' => 'checkout/guest/save');
		self::assertNull(CheckoutFragmentNonce::getParentNonce($get, $request['server']), 'Rejected non-fragment route');

		$get = array('route' => 'extension/payment/cardinity/threeDSecureForm');
		$server = array(
			'REQUEST_METHOD' => 'POST',
			'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
			'HTTP_X_CHECKOUT_CSP_NONCE' => $nonce,
		);
		self::assertSame($nonce, CheckoutFragmentNonce::getParentNonce($get, $server));

		$get = array('route' => 'extension/payment/opayo/getForm');
		$server['REQUEST_METHOD'] = 'GET';
		self::assertNull(CheckoutFragmentNonce::getParentNonce($get, $server), 'Opayo selector-load removes its scripts; parent nonce is not forwarded');
	}

	public function testOnlyAllowlistedFragmentResponsesAreMarkedPrivateNoStore(): void {
		self::assertTrue(CheckoutFragmentNonce::isNoStoreHtmlResponse('checkout/guest', 'GET'));
		self::assertTrue(CheckoutFragmentNonce::isNoStoreHtmlResponse('extension/payment/cardinity/threeDSecureForm', 'POST'));
		self::assertTrue(CheckoutFragmentNonce::isNoStoreHtmlResponse('extension/payment/opayo/getForm', 'GET'));
		self::assertFalse(CheckoutFragmentNonce::isNoStoreHtmlResponse('checkout/checkout/country', 'GET'));
		self::assertFalse(CheckoutFragmentNonce::isNoStoreHtmlResponse('checkout/guest/save', 'POST'));
	}

	public function testBothCheckoutThemesLoadTheScopedSameOriginNonceHandler(): void {
		foreach (array('default', 'charm_by_sylora') as $theme) {
			$template = $this->read('upload/catalog/view/theme/' . $theme . '/template/checkout/checkout.twig');

			self::assertMatchesRegularExpression('/<script[^>]*nonce="\{\{ csp_nonce \}\}"[^>]*src="catalog\/view\/javascript\/checkout-csp\.js\?v=[^"]+"/', $template, $theme);
		}

		$script = $this->read('upload/catalog/view/javascript/checkout-csp.js');
		self::assertStringContainsString('url.origin !== window.location.origin', $script);
		self::assertStringContainsString("new URL('index.php', document.baseURI).pathname", $script);
		self::assertStringContainsString('url.pathname !== storefrontPath', $script);
		self::assertStringContainsString("xhr.setRequestHeader('X-Checkout-CSP-Nonce'", $script);
		self::assertStringContainsString('extension/payment/cardinity/threeDSecureForm', $script);
		self::assertStringContainsString('extension/payment/cardinity/threeDSecureFormV2', $script);
		self::assertStringNotContainsString('extension/payment/opayo/getForm', $script);
	}

	public function testStartupKeepsFragmentNonceOutOfResponsePolicyAndLoaderUsesItOnlyForViews(): void {
		$startup = $this->read('upload/catalog/controller/startup/startup.php');
		$loader = $this->read('upload/system/engine/loader.php');

		self::assertStringContainsString("getContentSecurityPolicy(\$csp_nonce)", $startup);
		self::assertStringContainsString("addHeader('Cache-Control: private, no-store')", $startup);
		self::assertStringContainsString("registry->set('csp_fragment_nonce', \$fragment_nonce)", $startup);
		self::assertStringNotContainsString("config->set('csp_fragment_nonce'", $startup);
		self::assertStringContainsString("registry->get('csp_fragment_nonce')", $loader);
		self::assertStringNotContainsString("config')->get('csp_fragment_nonce')", $loader);
		self::assertStringContainsString("get('csp_nonce')", $loader);
		self::assertStringContainsString("\$data['csp_nonce'] = \$csp_nonce", $loader);
	}

	public function testLoaderUsesParentNonceForNestedFragmentViewsWithoutChangingResponseNonce(): void {
		$templateRoot = defined('DIR_TEMPLATE') ? rtrim(DIR_TEMPLATE, '/\\') . '/' : sys_get_temp_dir() . '/sylora-checkout-fragment-templates/';
		$fixtureRoot = $templateRoot . 'security/csp/checkoutfragment/';
		$fixtureRoute = 'security/csp/checkoutfragment/main';

		if (!defined('DIR_TEMPLATE')) {
			define('DIR_TEMPLATE', $templateRoot);
		}

		if (!defined('DIR_CACHE')) {
			define('DIR_CACHE', sys_get_temp_dir() . '/sylora-checkout-fragment-cache/');
		}

		if (!is_dir($fixtureRoot)) {
			mkdir($fixtureRoot, 0777, true);
		}

		if (!is_dir(DIR_CACHE . 'template')) {
			mkdir(DIR_CACHE . 'template', 0777, true);
		}

		file_put_contents($fixtureRoot . 'main.twig', '{% include "security/csp/checkoutfragment/nested.twig" %}<script>console.log("main");</script>');
		file_put_contents($fixtureRoot . 'nested.twig', '<script>console.log("nested");</script>');

		require_once $this->root . '/upload/system/library/template.php';
		require_once $this->root . '/upload/system/library/template/twig.php';
		require_once $this->root . '/upload/system/engine/loader.php';

		$config = new class {
			private array $values = array(
				'template_engine' => 'Twig',
				'template_directory' => '',
				'csp_nonce' => 'response-policy-nonce',
				'csp_fragment_nonce' => 'database-setting-must-be-ignored',
			);

			public function get(string $key) {
				return $this->values[$key] ?? null;
			}

			public function set(string $key, $value): void {
				$this->values[$key] = $value;
			}
		};

		$event = new class {
			public function trigger(string $name, array $arguments = array()) {
				return null;
			}
		};

		$registry = new class($config, $event) {
			private array $services;

			public function __construct($config, $event) {
				$this->services = array('config' => $config, 'event' => $event);
			}

			public function get(string $key) {
				return $this->services[$key] ?? null;
			}

			public function set(string $key, $value): void {
				$this->services[$key] = $value;
			}
		};

		$loader = new Loader($registry);
		$output = $loader->view($fixtureRoute);

		self::assertStringContainsString('<script nonce="response-policy-nonce">console.log("nested");</script>', $output);
		self::assertStringNotContainsString('database-setting-must-be-ignored', $output);
		self::assertSame('response-policy-nonce', $config->get('csp_nonce'));

		$registry->set('csp_fragment_nonce', 'parent-document-nonce');
		$output = $loader->view($fixtureRoute);
		self::assertStringContainsString('<script nonce="parent-document-nonce">console.log("nested");</script>', $output);
		self::assertStringContainsString('<script nonce="parent-document-nonce">console.log("main");</script>', $output);
		self::assertStringNotContainsString('nonce="response-policy-nonce"', $output);
		self::assertSame('response-policy-nonce', $config->get('csp_nonce'));
		self::assertSame('database-setting-must-be-ignored', $config->get('csp_fragment_nonce'));
	}

	private function read(string $relativePath): string {
		$content = file_get_contents($this->root . '/' . $relativePath);

		self::assertIsString($content, $relativePath);

		return $content;
	}
}
