<?php

final class CheckoutFragmentNonce {
	private const HTML_FRAGMENT_METHODS = array(
		'checkout/login' => array('GET'),
		'checkout/guest' => array('GET'),
		'checkout/register' => array('GET'),
		'checkout/guest_shipping' => array('GET'),
		'checkout/payment_address' => array('GET'),
		'checkout/shipping_address' => array('GET'),
		'checkout/shipping_method' => array('GET'),
		'checkout/payment_method' => array('GET'),
		'checkout/confirm' => array('GET'),
		'extension/payment/opayo/getForm' => array('GET'),
		'extension/payment/cardinity/threeDSecureForm' => array('POST'),
		'extension/payment/cardinity/threeDSecureFormV2' => array('POST'),
	);
	private const NONCE_FRAGMENT_METHODS = array(
		'checkout/login' => array('GET'),
		'checkout/guest' => array('GET'),
		'checkout/register' => array('GET'),
		'checkout/guest_shipping' => array('GET'),
		'checkout/payment_address' => array('GET'),
		'checkout/shipping_address' => array('GET'),
		'checkout/shipping_method' => array('GET'),
		'checkout/payment_method' => array('GET'),
		'checkout/confirm' => array('GET'),
		'extension/payment/cardinity/threeDSecureForm' => array('POST'),
		'extension/payment/cardinity/threeDSecureFormV2' => array('POST'),
	);

	public static function isNoStoreHtmlResponse(string $route, string $method): bool {
		$allowed_methods = self::HTML_FRAGMENT_METHODS[$route] ?? array();

		return in_array(strtoupper($method), $allowed_methods, true);
	}

	public static function getParentNonce(array $get, array $server): ?string {
		$route = $get['route'] ?? null;
		$method = $server['REQUEST_METHOD'] ?? null;

		if (!is_string($route) || !is_string($method)) {
			return null;
		}

		$allowed_methods = self::NONCE_FRAGMENT_METHODS[$route] ?? array();

		if (!in_array(strtoupper($method), $allowed_methods, true)) {
			return null;
		}

		if (($server['HTTP_X_REQUESTED_WITH'] ?? null) !== 'XMLHttpRequest') {
			return null;
		}

		$nonce = $server['HTTP_X_CHECKOUT_CSP_NONCE'] ?? null;

		if (!is_string($nonce) || strlen($nonce) !== 24) {
			return null;
		}

		$decoded_nonce = base64_decode($nonce, true);

		if (!is_string($decoded_nonce) || strlen($decoded_nonce) !== 16 || base64_encode($decoded_nonce) !== $nonce) {
			return null;
		}

		return $nonce;
	}
}
