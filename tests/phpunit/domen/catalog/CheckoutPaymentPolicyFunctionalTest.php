<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/TestBootstrap.php';

use PHPUnit\Framework\TestCase;

final class CheckoutPaymentPolicyFunctionalTest extends TestCase {
	private const CHECKOUT_ALIAS = 'registration';
	private const HTTP_TIMEOUT_SECONDS = 10;

	public function testCheckoutAllowsRobokassaAfterSeoRouting(): void {
		$store = TestBootstrap::shared();
		$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
		$db->execute_query(
			"INSERT INTO seo_url (store_id, language_id, query, keyword) VALUES (0, 1, 'checkout/checkout', ?)",
			array(self::CHECKOUT_ALIAS)
		);
		$aliasId = $db->insert_id;
		$http = curl_init();

		try {
			curl_setopt_array($http, array(
				CURLOPT_COOKIEFILE => '',
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => true,
				CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
				CURLOPT_URL => $store->getRouteUrl('checkout/cart/add'),
				CURLOPT_POSTFIELDS => 'product_id=1&quantity=1',
			));
			$response = curl_exec($http);
			self::assertIsString($response);
			$cart = json_decode(substr($response, curl_getinfo($http, CURLINFO_HEADER_SIZE)), true);
			self::assertIsArray($cart);
			self::assertArrayHasKey('success', $cart);

			curl_setopt($http, CURLOPT_HTTPGET, true);
			$directUrl = $store->getRouteUrl('checkout/checkout');
			$seoUrl = HTTP_SERVER . self::CHECKOUT_ALIAS . '?_route_=' . self::CHECKOUT_ALIAS;
			foreach (array($directUrl, $seoUrl) as $checkoutUrl) {
				self::assertSame("form-action 'self' https://auth.robokassa.ru", $this->formAction($http, $checkoutUrl), $checkoutUrl);
			}

			self::assertSame("form-action 'self'", $this->formAction($http, $store->getRouteUrl('common/home')));
			self::assertSame("form-action 'self'", $this->formAction($http, $store->getRouteUrl('checkout/payment_address')));
		} finally {
			$db->execute_query('DELETE FROM seo_url WHERE seo_url_id = ?', array($aliasId));
			$db->close();
		}
	}

	private function formAction(CurlHandle $http, string $url): string {
		curl_setopt($http, CURLOPT_URL, $url);
		$response = curl_exec($http);
		self::assertIsString($response);
		self::assertSame(200, curl_getinfo($http, CURLINFO_RESPONSE_CODE), $url);
		$headers = substr($response, 0, curl_getinfo($http, CURLINFO_HEADER_SIZE));
		self::assertSame(1, preg_match_all('/^Content-Security-Policy: [^\r\n]+/mi', $headers, $policies));
		self::assertSame(1, preg_match('/\bform-action [^;]+/', $policies[0][0], $match));

		return $match[0];
	}
}
