(function ($, window, document) {
	'use strict';

	if (!$ || typeof $.ajax !== 'function') {
		return;
	}

	var currentScript = document.currentScript;
	var nonce = currentScript ? currentScript.nonce : '';
	var storefrontPath;

	if (!nonce) {
		return;
	}

	try {
		storefrontPath = new URL('index.php', document.baseURI).pathname;
	} catch (error) {
		return;
	}

	var fragmentRoutes = {
		'checkout/login': ['GET'],
		'checkout/guest': ['GET'],
		'checkout/register': ['GET'],
		'checkout/guest_shipping': ['GET'],
		'checkout/payment_address': ['GET'],
		'checkout/shipping_address': ['GET'],
		'checkout/shipping_method': ['GET'],
		'checkout/payment_method': ['GET'],
		'checkout/confirm': ['GET'],
		'extension/payment/cardinity/threeDSecureForm': ['POST'],
		'extension/payment/cardinity/threeDSecureFormV2': ['POST']
	};

	$(document).on('ajaxSend.checkoutCspNonce', function (event, xhr, settings) {
		if (!settings || typeof settings.url !== 'string') {
			return;
		}

		var url;

		try {
			url = new URL(settings.url, document.baseURI);
		} catch (error) {
			return;
		}

		if (url.origin !== window.location.origin || url.pathname !== storefrontPath) {
			return;
		}

		var routes = url.searchParams.getAll('route');

		if (routes.length !== 1 || !Object.prototype.hasOwnProperty.call(fragmentRoutes, routes[0])) {
			return;
		}

		var method = String(settings.type || settings.method || 'GET').toUpperCase();

		if (fragmentRoutes[routes[0]].indexOf(method) === -1) {
			return;
		}

		xhr.setRequestHeader('X-Checkout-CSP-Nonce', nonce);
	});
})(window.jQuery, window, document);
