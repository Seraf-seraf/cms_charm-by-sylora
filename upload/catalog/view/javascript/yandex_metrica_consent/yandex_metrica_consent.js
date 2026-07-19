(function(window, document) {
	'use strict';

	var config = window.YandexMetricaConsentConfig || {};
	var cookieName = 'sylora_cookie_consent';
	var analyticsChoice = 'v1:analytics';
	var essentialChoice = 'v1:essential';
	var initialized = false;
	var listenersBound = false;

	function getChoice() {
		var cookies = document.cookie ? document.cookie.split(';') : [];

		for (var index = 0; index < cookies.length; index++) {
			var parts = cookies[index].trim().split('=');

			if (parts.shift() === cookieName) {
				var value = decodeURIComponent(parts.join('='));
				return value === analyticsChoice || value === essentialChoice ? value : '';
			}
		}

		return '';
	}

	function writeChoice(choice) {
		var secure = window.location.protocol === 'https:' ? '; Secure' : '';
		var days = Number(config.cookieDays) || 365;
		document.cookie = cookieName + '=' + encodeURIComponent(choice) + '; Max-Age=' + (days * 86400) + '; Path=/; SameSite=Lax' + secure;
	}

	function removeMetricaCookies() {
		var hostname = window.location.hostname;
		var cookies = document.cookie ? document.cookie.split(';') : [];

		for (var index = 0; index < cookies.length; index++) {
			var name = cookies[index].split('=')[0].trim();

			if (name.indexOf('_ym_') !== 0) {
				continue;
			}

			document.cookie = name + '=; Max-Age=0; Path=/; SameSite=Lax';
			document.cookie = name + '=; Max-Age=0; Path=/; Domain=' + hostname + '; SameSite=Lax';
			document.cookie = name + '=; Max-Age=0; Path=/; Domain=.' + hostname + '; SameSite=Lax';
		}
	}

	function goal(name) {
		if (typeof window.ym === 'function') {
			window.ym(config.counter, 'reachGoal', name);
		}
	}

	function bindAnalyticsListeners() {
		if (listenersBound) {
			return;
		}

		listenersBound = true;
		document.addEventListener('click', function(event) {
			var link = event.target.closest && event.target.closest('a[href]');

			if (!link) {
				return;
			}

			var href = link.getAttribute('href') || '';

			if (href.indexOf('tel:') === 0) goal('phone_click');
			else if (href.indexOf('mailto:') === 0) goal('email_click');
			else if (/t\.me|wa\.me|whatsapp|vk\.com/i.test(href)) goal('messenger_click');
			if (/checkout\/checkout|route=checkout\/checkout/.test(href)) goal('checkout_start');
		});
		document.addEventListener('sylora:cart-add', function(event) {
			goal('add_to_cart');

			if (config.ecommerce) {
				window.dataLayer.push({ecommerce: {currencyCode: config.currency, add: {products: [{id: String(event.detail.product_id), quantity: Number(event.detail.quantity || 1)}]}}});
			}
		});
		document.addEventListener('sylora:cart-remove', function(event) {
			if (config.ecommerce) {
				window.dataLayer.push({ecommerce: {currencyCode: config.currency, remove: {products: [{id: String(event.detail.product_id || event.detail.key), quantity: Number(event.detail.quantity || 1)}]}}});
			}
		});
	}

	function initializeMetrica() {
		if (initialized) {
			return;
		}

		initialized = true;
		window['disableYaCounter' + config.counter] = false;
		window.dataLayer = window.dataLayer || [];

		if (config.ecommerceEvent && Object.keys(config.ecommerceEvent).length) {
			window.dataLayer.push(config.ecommerceEvent);
		}

		(function(m, e, t, r, i, k, a) {
			m[i] = m[i] || function() {(m[i].a = m[i].a || []).push(arguments);};
			m[i].l = 1 * new Date();

			for (var scriptIndex = 0; scriptIndex < document.scripts.length; scriptIndex++) {
				if (document.scripts[scriptIndex].src === r) return;
			}

			k = e.createElement(t);
			a = e.getElementsByTagName(t)[0];
			k.async = 1;
			k.src = r;
			a.parentNode.insertBefore(k, a);
		})(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym');

		window.ym(config.counter, 'init', {clickmap: true, trackLinks: true, accurateTrackBounce: true, webvisor: Boolean(config.webvisor), ecommerce: Boolean(config.ecommerce)});
		bindAnalyticsListeners();

		if (config.goals && config.goals.orderSuccess) goal('order_success');
		if (config.goals && config.goals.checkoutStart) goal('checkout_start');
		if (config.goals && config.goals.contactSubmit) goal('contact_submit');
	}

	function setBannerVisible(visible, focusPrimary) {
		var banner = document.getElementById('analytics-cookie-banner');

		if (!banner) {
			return;
		}

		banner.hidden = !visible;

		if (visible && focusPrimary) {
			var primary = banner.querySelector('[data-cookie-consent="analytics"]');
			if (primary) primary.focus();
		}
	}

	function setChoice(choice) {
		var previousChoice = getChoice();
		writeChoice(choice);
		setBannerVisible(false, false);

		if (choice === analyticsChoice) {
			initializeMetrica();
		} else {
			window['disableYaCounter' + config.counter] = true;
			removeMetricaCookies();
		}

		document.dispatchEvent(new CustomEvent('sylora:cookie-consent-changed', {detail: {choice: choice}}));

		if (previousChoice === analyticsChoice && choice === essentialChoice) {
			window.location.reload();
		}
	}

	function createButton(text, className, choice) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = className;
		button.textContent = text;
		button.setAttribute('data-cookie-consent', choice);
		return button;
	}

	function buildBanner() {
		var banner = document.createElement('section');
		banner.className = 'ym-consent';
		banner.id = 'analytics-cookie-banner';
		banner.setAttribute('role', 'dialog');
		banner.setAttribute('aria-modal', 'false');
		banner.setAttribute('aria-labelledby', 'analytics-cookie-title');
		banner.hidden = true;

		var inner = document.createElement('div');
		inner.className = 'ym-consent__inner';
		var copy = document.createElement('div');
		copy.className = 'ym-consent__copy';
		var title = document.createElement('h2');
		title.id = 'analytics-cookie-title';
		title.textContent = config.banner.title;
		var description = document.createElement('p');
		description.appendChild(document.createTextNode(config.banner.description + ' '));

		if (config.privacyUrl) {
			var privacy = document.createElement('a');
			privacy.href = config.privacyUrl;
			privacy.textContent = config.banner.privacy;
			description.appendChild(privacy);
		}

		var actions = document.createElement('div');
		actions.className = 'ym-consent__actions';
		actions.appendChild(createButton(config.banner.accept, 'ym-consent__button ym-consent__button--primary', 'analytics'));
		actions.appendChild(createButton(config.banner.reject, 'ym-consent__button', 'essential'));
		copy.appendChild(title);
		copy.appendChild(description);
		inner.appendChild(copy);
		inner.appendChild(actions);
		banner.appendChild(inner);
		document.body.appendChild(banner);

		var footer = document.querySelector('.site-footer__bottom, footer');

		if (footer) {
			var settings = document.createElement('button');
			settings.type = 'button';
			settings.className = 'ym-consent-settings';
			settings.textContent = config.banner.settings;
			settings.setAttribute('data-cookie-settings', '');
			footer.appendChild(settings);
		}

		banner.addEventListener('click', function(event) {
			var trigger = event.target.closest ? event.target.closest('[data-cookie-consent]') : null;

			if (trigger) {
				setChoice(trigger.getAttribute('data-cookie-consent') === 'analytics' ? analyticsChoice : essentialChoice);
			}
		});

		document.addEventListener('click', function(event) {
			var trigger = event.target.closest ? event.target.closest('[data-cookie-settings]') : null;

			if (trigger) {
				event.preventDefault();
				setBannerVisible(true, true);
			}
		});
	}

	function start() {
		buildBanner();

		if (getChoice() === analyticsChoice) initializeMetrica();
		else window['disableYaCounter' + config.counter] = true;

		setBannerVisible(getChoice() === '', false);
	}

	window.SyloraCookieConsent = {
		getChoice: getChoice,
		isAnalyticsAllowed: function() { return getChoice() === analyticsChoice; },
		open: function() { setBannerVisible(true, true); }
	};

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
	else start();
})(window, document);
