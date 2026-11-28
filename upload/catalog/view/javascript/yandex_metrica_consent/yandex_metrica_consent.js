(function(window, document) {
	'use strict';

	function isObject(value) {
		return value !== null && typeof value === 'object' && !Array.isArray(value);
	}

	function normalizeConfig(raw) {
		if (!isObject(raw) || !/^\d{5,12}$/.test(String(raw.counter || '')) || !isObject(raw.banner)) {
			return null;
		}

		var banner = {};

		for (var index = 0; index < ['title', 'description', 'accept', 'reject', 'settings', 'privacy'].length; index++) {
			var field = ['title', 'description', 'accept', 'reject', 'settings', 'privacy'][index];
			var value = typeof raw.banner[field] === 'string' ? raw.banner[field].trim() : '';

			if (!value) {
				return null;
			}

			banner[field] = value;
		}

		var cookieDays = Number(raw.cookieDays);

		if (!Number.isInteger(cookieDays) || cookieDays < 1 || cookieDays > 730) {
			cookieDays = 365;
		}

		var privacyUrl = typeof raw.privacyUrl === 'string' ? raw.privacyUrl.trim() : '';

		if (privacyUrl && !/^(?:https?:\/\/|\/|index\.php)/i.test(privacyUrl)) {
			privacyUrl = '';
		}

		var goals = isObject(raw.goals) ? raw.goals : {};

		return {
			counter: Number(raw.counter),
			webvisor: Boolean(raw.webvisor),
			ecommerce: Boolean(raw.ecommerce),
			currency: typeof raw.currency === 'string' && /^[A-Z]{3}$/.test(raw.currency) ? raw.currency : 'RUB',
			ecommerceEvent: isObject(raw.ecommerceEvent) ? raw.ecommerceEvent : {},
			goals: {
				orderSuccess: Boolean(goals.orderSuccess),
				checkoutStart: Boolean(goals.checkoutStart),
				contactSubmit: Boolean(goals.contactSubmit)
			},
			cookieDays: cookieDays,
			privacyUrl: privacyUrl,
			banner: banner
		};
	}

	function readConfig(element) {
		if (!element) {
			return null;
		}

		try {
			return normalizeConfig(JSON.parse(element.getAttribute('data-config') || '{}'));
		} catch (error) {
			return null;
		}
	}

	var configElement = document.getElementById('yandex-metrica-consent-config');
	var config = readConfig(configElement);

	if (!config) {
		return;
	}

	var cookieName = 'sylora_cookie_consent';
	var analyticsChoice = 'v1:analytics';
	var essentialChoice = 'v1:essential';
	var settingsTitle = 'Настройки аналитики';
	var settingsDescription = 'Вы можете изменить выбор в любой момент. Аналитические cookie используются только с вашего согласия.';
	var initialized = false;
	var listenersBound = false;
	var settingsTrigger = null;

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

	function hideBanner(banner) {
		banner.close();
		banner.hidden = true;
		banner.setAttribute('aria-modal', 'false');

		if (settingsTrigger && settingsTrigger.isConnected) {
			settingsTrigger.focus();
		}

		settingsTrigger = null;
	}

	function openSettings(trigger) {
		var banner = document.getElementById('analytics-cookie-banner');

		if (!banner || (!banner.hidden && banner.getAttribute('data-cookie-mode') === 'settings')) {
			return;
		}

		var accept = banner.querySelector('[data-cookie-consent="analytics"]');
		var reject = banner.querySelector('[data-cookie-consent="essential"]');
		var close = banner.querySelector('[data-cookie-close]');
		var title = document.getElementById('analytics-cookie-title');
		var description = document.getElementById('analytics-cookie-description');
		var currentChoice = document.getElementById('analytics-cookie-choice');
		var choice = getChoice();

		if (!choice) {
			banner.removeAttribute('data-cookie-mode');
			title.textContent = config.banner.title;
			description.textContent = config.banner.description;
			currentChoice.hidden = true;
			accept.textContent = config.banner.accept;
			reject.textContent = config.banner.reject;
			close.hidden = true;
			banner.setAttribute('open', '');
			banner.hidden = false;
			accept.focus();
			return;
		}

		settingsTrigger = trigger || document.activeElement;
		banner.close();
		banner.setAttribute('data-cookie-mode', 'settings');
		banner.setAttribute('aria-modal', 'true');
		title.textContent = settingsTitle;
		description.textContent = settingsDescription;
		currentChoice.textContent = choice === analyticsChoice ? 'Текущий выбор: аналитика разрешена' : 'Текущий выбор: без аналитики';
		currentChoice.hidden = false;
		accept.textContent = 'Разрешить аналитику';
		reject.textContent = 'Продолжить без аналитики';
		close.hidden = false;
		banner.hidden = false;
		banner.showModal();
		close.focus();
	}

	function setChoice(choice) {
		var previousChoice = getChoice();
		writeChoice(choice);
		hideBanner(document.getElementById('analytics-cookie-banner'));

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
		var banner = document.createElement('dialog');
		banner.className = 'ym-consent';
		banner.id = 'analytics-cookie-banner';
		banner.setAttribute('aria-modal', 'false');
		banner.setAttribute('aria-labelledby', 'analytics-cookie-title');
		banner.setAttribute('aria-describedby', 'analytics-cookie-description');
		banner.hidden = true;

		var inner = document.createElement('div');
		inner.className = 'ym-consent__inner';
		var copy = document.createElement('div');
		copy.className = 'ym-consent__copy';
		var heading = document.createElement('div');
		heading.className = 'ym-consent__heading';
		var title = document.createElement('h2');
		title.id = 'analytics-cookie-title';
		title.textContent = config.banner.title;
		heading.appendChild(title);

		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'ym-consent__close';
		close.setAttribute('data-cookie-close', '');
		close.setAttribute('aria-label', 'Закрыть настройки аналитики');
		close.setAttribute('autofocus', '');
		close.hidden = true;
		var closeIcon = document.createElement('img');
		closeIcon.alt = '';
		closeIcon.setAttribute('aria-hidden', 'true');
		closeIcon.addEventListener('load', function() {
			close.textContent = '';
			close.appendChild(closeIcon);
		});
		closeIcon.src = 'catalog/view/theme/charm_by_sylora/image/icons/atelier/close.svg';
		close.textContent = '×';
		heading.appendChild(close);
		copy.appendChild(heading);
		var body = document.createElement('div');
		body.className = 'ym-consent__body';
		var description = document.createElement('p');
		description.className = 'ym-consent__description';
		description.id = 'analytics-cookie-description';
		description.textContent = config.banner.description;
		body.appendChild(description);

		var currentChoice = document.createElement('p');
		currentChoice.className = 'ym-consent__choice';
		currentChoice.id = 'analytics-cookie-choice';
		currentChoice.hidden = true;
		body.appendChild(currentChoice);

		if (config.privacyUrl) {
			var privacyParagraph = document.createElement('p');
			privacyParagraph.className = 'ym-consent__privacy';

			var privacyLink = document.createElement('a');
			privacyLink.href = config.privacyUrl;
			privacyLink.setAttribute('data-cookie-privacy', '');
			privacyLink.textContent = config.banner.privacy;
			privacyParagraph.appendChild(privacyLink);
			body.appendChild(privacyParagraph);
		}
		copy.appendChild(body);

		var actions = document.createElement('div');
		actions.className = 'ym-consent__actions';
		actions.appendChild(createButton(config.banner.accept, 'ym-consent__button ym-consent__button--primary', 'analytics'));
		actions.appendChild(createButton(config.banner.reject, 'ym-consent__button', 'essential'));
		inner.appendChild(copy);
		inner.appendChild(actions);
		banner.appendChild(inner);
		document.body.appendChild(banner);

		var footer = document.querySelector('.site-footer__bottom');

		if (footer) {
			var settings = document.createElement('button');
			settings.type = 'button';
			settings.className = 'ym-consent-settings';
			settings.textContent = config.banner.settings;
			settings.setAttribute('data-cookie-settings', '');
			settings.setAttribute('aria-haspopup', 'dialog');
			settings.setAttribute('aria-controls', banner.id);
			footer.appendChild(settings);
		}

		banner.addEventListener('click', function(event) {
			var trigger = event.target.closest ? event.target.closest('[data-cookie-consent]') : null;

			if (trigger) {
				setChoice(trigger.getAttribute('data-cookie-consent') === 'analytics' ? analyticsChoice : essentialChoice);
			}
		});

		function dismissSettings() {
			if (!banner.hidden && banner.getAttribute('data-cookie-mode') === 'settings') {
				hideBanner(banner);
			}
		}

		close.addEventListener('click', dismissSettings);
		banner.addEventListener('cancel', function(event) {
			event.preventDefault();
			dismissSettings();
		});
		banner.addEventListener('keydown', function(event) {
			if (event.key !== 'Tab' || banner.getAttribute('data-cookie-mode') !== 'settings') {
				return;
			}

			var last = banner.querySelector('[data-cookie-consent="essential"]');

			if (event.shiftKey && document.activeElement === close) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				close.focus();
			}
		});

		document.addEventListener('click', function(event) {
			var trigger = event.target.closest ? event.target.closest('[data-cookie-settings]') : null;

			if (trigger) {
				event.preventDefault();
				openSettings(trigger);
			}
		});
	}

	function start() {
		buildBanner();

		if (getChoice() === analyticsChoice) initializeMetrica();
		else window['disableYaCounter' + config.counter] = true;

		if (!getChoice()) {
			var banner = document.getElementById('analytics-cookie-banner');
			banner.setAttribute('open', '');
			banner.hidden = false;
		}
	}

	window.SyloraCookieConsent = {
		getChoice: getChoice,
		isAnalyticsAllowed: function() { return getChoice() === analyticsChoice; },
		open: openSettings
	};

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
	else start();
})(window, document);
