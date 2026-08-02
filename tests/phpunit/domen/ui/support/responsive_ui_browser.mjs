import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [, , targetUrl, viewportArgument, chromeBinary] = process.argv;
const [viewportValue, pageScaleValue = '1'] = (viewportArgument || '').split('@');
const viewportWidth = Number(viewportValue);
const pageScale = Number(pageScaleValue);

if (!targetUrl || !Number.isInteger(viewportWidth) || viewportWidth < 320 || ![1, 2].includes(pageScale) || !chromeBinary) {
	throw new Error('Usage: responsive_ui_browser.mjs <url> <viewport-width[@page-scale]> <chrome-binary>');
}

const profile = await mkdtemp(join(tmpdir(), 'sylora-responsive-ui-'));
const chrome = spawn(chromeBinary, [
	'--headless=new',
	'--no-sandbox',
	'--disable-gpu',
	'--disable-background-networking',
	'--remote-debugging-port=0',
	`--user-data-dir=${profile}`,
	'about:blank',
], { stdio: 'ignore' });
const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

async function getDebuggingPort() {
	for (let attempt = 0; attempt < 100; attempt++) {
		try {
			const content = await readFile(join(profile, 'DevToolsActivePort'), 'utf8');
			const port = Number(content.split(/\r?\n/, 1)[0]);

			if (Number.isInteger(port) && port > 0) return port;
		} catch {
			// Chrome has not created DevToolsActivePort yet.
		}

		await delay(100);
	}

	throw new Error('Chrome DevTools did not start.');
}

async function connect(port) {
	const pages = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
	const page = pages.find(item => item.type === 'page');

	if (!page) throw new Error('Chrome page target was not found.');

	const socket = new WebSocket(page.webSocketDebuggerUrl);
	const pending = new Map();
	let commandId = 0;

	socket.addEventListener('message', event => {
		const message = JSON.parse(event.data);

		if (message.id && pending.has(message.id)) {
			pending.get(message.id)(message);
			pending.delete(message.id);
		}
	});

	await new Promise((resolve, reject) => {
		socket.addEventListener('open', resolve, { once: true });
		socket.addEventListener('error', reject, { once: true });
	});

	return {
		close: () => socket.close(),
		command(method, params = {}) {
			return new Promise((resolve, reject) => {
				const id = ++commandId;
				pending.set(id, message => {
					if (message.error) reject(new Error(message.error.message));
					else resolve(message.result || {});
				});
				socket.send(JSON.stringify({ id, method, params }));
			});
		},
	};
}

async function evaluate(client, expression) {
	const response = await client.command('Runtime.evaluate', {
		expression,
		returnByValue: true,
		awaitPromise: true,
	});

	if (response.exceptionDetails) {
		const description = response.exceptionDetails.exception?.description || response.exceptionDetails.text || 'unknown browser error';
		throw new Error(`Browser expression failed: ${description}`);
	}
	return response.result?.value;
}

let client;

try {
	const port = await getDebuggingPort();
	client = await connect(port);
	await client.command('Emulation.setDeviceMetricsOverride', {
		width: viewportWidth,
		height: 900,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await client.command('Page.navigate', { url: targetUrl });
	await delay(1800);
	await client.command('Emulation.setPageScaleFactor', { pageScaleFactor: pageScale });
	await delay(100);
	await evaluate(client, `(async () => {
		const fixture = document.createElement('div');
		fixture.id = 'responsive-ui-fixture';
		fixture.style.cssText = 'display:flex;flex-wrap:wrap;align-items:flex-start;gap:8px;width:100%;padding:8px;position:relative;z-index:1';
		const image = 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22320%22%20height%3D%22320%22%3E%3Crect%20width%3D%22320%22%20height%3D%22320%22%20fill%3D%22%23d192b2%22%2F%3E%3C%2Fsvg%3E';
		fixture.innerHTML = [
			'<button id="dynamic-icon" type="button" aria-label="Закрыть"><i class="fa fa-times" aria-hidden="true"></i></button>',
			'<button id="dynamic-text" type="button"><i class="fa fa-check" aria-hidden="true"></i><span>Готово</span></button>',
			'<button id="fixture-catalog-icon" class="catalog-card__icon" type="button" aria-label="Избранное"><i class="fa fa-heart" aria-hidden="true"></i></button>',
			'<span style="position:relative;display:block;width:68px;height:68px"><span class="quick-view__close"><button id="fixture-quick-view" type="button" aria-label="Закрыть"><i class="fa fa-times" aria-hidden="true"></i></button></span></span>',
			'<button id="fixture-cart-remove" class="cart-item__remove" type="button" aria-label="Удалить"><i class="fa fa-times" aria-hidden="true"></i></button>',
			'<button id="fixture-mini-cart-remove" class="mini-cart__remove" type="button" aria-label="Удалить"><i class="fa fa-times" aria-hidden="true"></i></button>',
			'<span id="fixture-wishlist-wrap" class="product-page__summary" style="display:block;width:240px;height:100px"><span class="product-summary__tools"><button id="fixture-product-tools" class="btn" type="button" aria-label="Добавить в закладки"><i class="fa fa-heart" aria-hidden="true"></i></button></span></span>',
			'<span class="site-search" style="display:block;width:40px"><span id="search" class="input-group"><span class="input-group-btn"><button id="fixture-search" class="btn btn-default btn-lg" type="button" aria-label="Поиск"><i class="fa fa-search" aria-hidden="true"></i></button></span></span></span>',
			'<span style="display:block;width:100%;max-width:420px"><article id="fixture-mini-cart" class="mini-cart__item"><a id="fixture-mini-image" class="mini-cart__image" href="#"><picture><img id="fixture-mini-image-img" src="' + image + '" alt="Товар" width="320" height="320"></picture></a><div class="mini-cart__content">Товар</div><button id="fixture-mini-remove" class="mini-cart__remove" type="button" aria-label="Удалить"><i class="fa fa-times" aria-hidden="true"></i></button></article></span>',
			'<article id="fixture-cart-item" class="cart-item" style="width:100%"><a id="fixture-cart-image" class="cart-item__image" href="#"><picture><img id="fixture-cart-image-img" src="' + image + '" alt="Товар" width="320" height="320"></picture></a><div class="cart-item__body"><div class="cart-item__top"><h2>Товар</h2><button class="cart-item__remove" type="button" aria-label="Удалить"><i class="fa fa-times" aria-hidden="true"></i></button></div><div class="cart-item__bottom"><div>Количество</div><div>Цена</div><div>Итого</div></div></div></article>',
			'<aside class="contact-card" style="width:220px"><picture><img id="fixture-contact-image" class="contact-card__image" src="' + image + '" alt="Charm by Sylora" width="320" height="320"></picture></aside>',
			'<div id="fixture-captcha" class="captcha-section" aria-label="Проверка от спама"><div class="form-group required smartcaptcha-field"><label class="control-label" for="fixture-captcha-control">Подтвердите, что вы не робот</label><div id="fixture-captcha-control" class="smart-captcha"></div><div class="text-danger">Ошибка проверки</div></div></div>'
		].join('');
		document.body.appendChild(fixture);
		await Promise.all(Array.from(fixture.querySelectorAll('img')).map(node => node.decode ? node.decode() : Promise.resolve()));
	})()`);
	await delay(250);

	const state = await evaluate(client, `(() => {
		const round = value => Math.round(value * 100) / 100;
		const number = (element, property) => Math.round(parseFloat(getComputedStyle(element)[property]));
		const brand = document.querySelector('.site-brand__mark');
		const brandRect = brand.getBoundingClientRect();
		const hero = document.querySelector('.sylora-hero h1');
		const section = document.querySelector('.sylora-section__head h2');
		const controls = Array.from(document.querySelectorAll('.is-icon-only, .catalog-card__icon, .quick-view__close button, .cart-item__remove, .mini-cart__remove, .product-summary__tools .btn'))
			.filter(control => {
				const rect = control.getBoundingClientRect();
				return rect.width > 0 && rect.height > 0;
			});
		const measure = (control, selector) => {
			if (!control) return null;

			const glyph = control.querySelector('.fa, .glyphicon, svg, [class*="icon-"]');
			const controlRect = control.getBoundingClientRect();

			if (!glyph) {
				return {
					selector,
					hasGlyph: false,
					width: Math.round(controlRect.width),
					height: Math.round(controlRect.height),
					deltaX: null,
					deltaY: null,
					glyphSize: null
				};
			}

			const glyphRect = glyph.getBoundingClientRect();
			return {
				selector,
				hasGlyph: true,
				width: Math.round(controlRect.width),
				height: Math.round(controlRect.height),
				deltaX: round(Math.abs((controlRect.left + controlRect.width / 2) - (glyphRect.left + glyphRect.width / 2))),
				deltaY: round(Math.abs((controlRect.top + controlRect.height / 2) - (glyphRect.top + glyphRect.height / 2))),
				glyphSize: number(glyph, 'fontSize')
			};
		};
		const icons = controls.map((control, index) => measure(
			control,
			control.id ? '#' + control.id : (control.className || control.tagName) + ':' + index
		));
		const fixtures = {
			dynamic: measure(document.getElementById('dynamic-icon'), '#dynamic-icon'),
			catalog: measure(document.getElementById('fixture-catalog-icon'), '#fixture-catalog-icon'),
			quickView: measure(document.getElementById('fixture-quick-view'), '#fixture-quick-view'),
			cartRemove: measure(document.getElementById('fixture-cart-remove'), '#fixture-cart-remove'),
			miniCartRemove: measure(document.getElementById('fixture-mini-cart-remove'), '#fixture-mini-cart-remove'),
			productTools: measure(document.getElementById('fixture-product-tools'), '#fixture-product-tools'),
			search: measure(document.getElementById('fixture-search'), '#fixture-search'),
			miniItemRemove: measure(document.getElementById('fixture-mini-remove'), '#fixture-mini-remove'),
			cartItemRemove: measure(document.querySelector('#fixture-cart-item .cart-item__remove'), '#fixture-cart-item .cart-item__remove')
		};
		const measureImage = (containerId, imageId) => {
			const container = document.getElementById(containerId);
			const imageNode = document.getElementById(imageId);
			const containerRect = container.getBoundingClientRect();
			const imageRect = imageNode.getBoundingClientRect();

			return {
				containerWidth: Math.round(containerRect.width),
				containerHeight: Math.round(containerRect.height),
				imageWidth: Math.round(imageRect.width),
				imageHeight: Math.round(imageRect.height),
				naturalWidth: imageNode.naturalWidth,
				naturalHeight: imageNode.naturalHeight,
				objectFit: getComputedStyle(imageNode).objectFit
			};
		};
		const wishlistWrap = document.getElementById('fixture-wishlist-wrap').getBoundingClientRect();
		const wishlistNode = document.querySelector('#fixture-wishlist-wrap .product-summary__tools');
		const wishlist = wishlistNode.getBoundingClientRect();
		const wishlistStyle = getComputedStyle(wishlistNode);
		const captcha = document.getElementById('fixture-captcha');
		const captchaLabel = captcha.querySelector('label');
		const visualContracts = {
			miniImage: measureImage('fixture-mini-image', 'fixture-mini-image' + '-img'),
			cartImage: measureImage('fixture-cart-image', 'fixture-cart-image' + '-img'),
			contactImage: measureImage('fixture-contact-image', 'fixture-contact-image'),
			wishlist: {
				right: Math.round(parseFloat(wishlistStyle.right)),
				top: Math.round(parseFloat(wishlistStyle.top)),
				contained: wishlist.left >= wishlistWrap.left && wishlist.right <= wishlistWrap.right && wishlist.top >= wishlistWrap.top
			},
			captcha: {
				hasVisibleTitle: captcha.querySelector('.captcha-section__title') !== null,
				label: captchaLabel.textContent.trim(),
				labelTarget: captchaLabel.htmlFor,
				hasError: captcha.querySelector('.text-danger') !== null
			}
		};

		return JSON.stringify({
			viewportWidth: window.innerWidth,
			pageScale: window.visualViewport ? window.visualViewport.scale : 1,
			documentOverflow: Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
			brand: {
				text: brand.textContent.trim(),
				overflow: Math.max(0, brand.scrollWidth - brand.clientWidth),
				fullyVisible: brandRect.left >= -1 && brandRect.right <= window.innerWidth + 1
			},
			fonts: {
				body: getComputedStyle(document.body).fontFamily,
				brand: getComputedStyle(brand).fontFamily,
				display: hero ? getComputedStyle(hero).fontFamily : ''
			},
			sizes: {
				body: number(document.body, 'fontSize'),
				hero: hero ? number(hero, 'fontSize') : null,
				section: section ? number(section, 'fontSize') : null
			},
			dynamic: {
				iconOnly: document.getElementById('dynamic-icon').classList.contains('is-icon-only'),
				iconWithText: document.getElementById('dynamic-text').classList.contains('is-icon-only')
			},
			icons,
			fixtures,
			visualContracts
		});
	})()`);

	process.stdout.write(`${state}\n`);
} finally {
	if (client) client.close();
	chrome.kill('SIGTERM');
	await Promise.race([
		new Promise(resolve => chrome.once('exit', resolve)),
		delay(2000),
	]);
	await rm(profile, { recursive: true, force: true });
}
