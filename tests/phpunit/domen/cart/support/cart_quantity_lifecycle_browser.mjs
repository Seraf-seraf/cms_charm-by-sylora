import { spawn } from 'node:child_process';
import { access, mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';

const [, , targetUrl, scenario, chromeBinary] = process.argv;
const productName = 'Серьги Розовый рассвет';
const productSearchTerm = 'Розовый рассвет';
const captureDirectoryValue = process.env.SYLORA_CART_LIFECYCLE_CAPTURE_DIR || '';

if (scenario !== 'cart-quantity-lifecycle' || !targetUrl || !chromeBinary) {
	throw new Error('Usage: cart_quantity_lifecycle_browser.mjs <loopback-url> cart-quantity-lifecycle <chrome-binary>');
}

const localOrigin = new URL(targetUrl).origin;
const localHostname = new URL(targetUrl).hostname;

if (!['127.0.0.1', 'localhost', '[::1]'].includes(localHostname)) {
	throw new Error('Cart lifecycle browser tests may only use the isolated loopback storefront.');
}

const captureDirectory = captureDirectoryValue ? resolve(captureDirectoryValue) : '';

if (captureDirectory) {
	if (
		dirname(captureDirectory) !== resolve(tmpdir())
		|| !captureDirectory.split('/').at(-1)?.startsWith('sylora-cart-quantity-lifecycle-')
	) {
		throw new Error('Capture artifacts must use a dedicated directory directly under /tmp.');
	}

	await mkdir(captureDirectory, { recursive: false });
}

const BROWSER_EXIT_TIMEOUT_MS = 3000;
const profile = await mkdtemp(join(tmpdir(), 'sylora-cart-lifecycle-profile-'));
const chrome = spawn(chromeBinary, [
	'--headless=new',
	'--no-sandbox',
	'--disable-gpu',
	'--disable-background-networking',
	'--remote-debugging-port=0',
	'--user-data-dir=' + profile,
	'about:blank',
], { stdio: 'ignore' });
let browserExitObserved = false;
const browserExit = new Promise(resolveExit => {
	chrome.once('exit', () => {
		browserExitObserved = true;
		resolveExit();
	});
});

const delay = milliseconds => new Promise(resolveDelay => setTimeout(resolveDelay, milliseconds));
const blockedExternalRequests = [];
const interceptionErrors = [];
const interceptionTasks = [];
const requestedRoutes = [];
const cartMutationRequests = [];
const requestPostDataTasks = [];
let client;
let result;
let failure;
let browserProcessExited = false;
let profileRemoved = false;

function hasBrowserExited() {
	return browserExitObserved || chrome.exitCode !== null || chrome.signalCode !== null;
}

async function waitForBrowserExit() {
	if (hasBrowserExited()) {
		return true;
	}

	let timeout;
	const exitObserved = await Promise.race([
		browserExit.then(() => true),
		new Promise(resolveTimeout => {
			timeout = setTimeout(() => resolveTimeout(false), BROWSER_EXIT_TIMEOUT_MS);
		}),
	]);
	clearTimeout(timeout);

	return exitObserved || hasBrowserExited();
}

async function pathExists(path) {
	try {
		await access(path);
		return true;
	} catch {
		return false;
	}
}

async function debuggingPort() {
	for (let attempt = 0; attempt < 100; attempt++) {
		try {
			const content = await readFile(join(profile, 'DevToolsActivePort'), 'utf8');
			const port = Number(content.split(/\r?\n/, 1)[0]);

			if (Number.isInteger(port) && port > 0) {
				return port;
			}
		} catch (error) {
			if (error?.code !== 'ENOENT') {
				throw error;
			}
		}

		await delay(100);
	}

	throw new Error('Chrome DevTools did not start.');
}

async function connect(port) {
	const pages = await (await fetch('http://127.0.0.1:' + port + '/json/list')).json();
	const page = pages.find(item => item.type === 'page');

	if (!page) {
		throw new Error('Chrome page target was not found.');
	}

	const socket = new WebSocket(page.webSocketDebuggerUrl);
	const pending = new Map();
	const listeners = new Map();
	let id = 0;

	socket.addEventListener('message', event => {
		const message = JSON.parse(event.data);

		if (message.id && pending.has(message.id)) {
			pending.get(message.id)(message);
			pending.delete(message.id);
		}

		if (message.method && listeners.has(message.method)) {
			for (const listener of listeners.get(message.method)) {
				listener(message.params || {});
			}
		}
	});

	await new Promise((resolveOpen, rejectOpen) => {
		socket.addEventListener('open', resolveOpen, { once: true });
		socket.addEventListener('error', rejectOpen, { once: true });
	});

	return {
		close: () => socket.close(),
		on(method, listener) {
			if (!listeners.has(method)) {
				listeners.set(method, []);
			}

			listeners.get(method).push(listener);
		},
		command(method, params = {}) {
			return new Promise((resolveCommand, rejectCommand) => {
				const commandId = ++id;

				pending.set(commandId, message => {
					if (message.error) {
						rejectCommand(new Error(message.error.message));
						return;
					}

					resolveCommand(message.result || {});
				});

				socket.send(JSON.stringify({ id: commandId, method, params }));
			});
		},
	};
}

async function evaluate(expression) {
	const response = await client.command('Runtime.evaluate', {
		expression,
		returnByValue: true,
		awaitPromise: true,
	});

	if (response.exceptionDetails) {
		throw new Error(response.exceptionDetails.exception?.description || response.exceptionDetails.text);
	}

	return response.result?.value;
}

async function waitFor(predicate, message, timeoutMilliseconds = 15000) {
	const deadline = Date.now() + timeoutMilliseconds;

	while (Date.now() < deadline) {
		try {
			if (await predicate()) {
				return;
			}
		} catch {
			// Navigation can discard the current execution context between polls.
		}

		await delay(100);
	}

	throw new Error(message);
}

async function settleInterceptionTasks() {
	let settledCount = 0;

	while (settledCount < interceptionTasks.length) {
		const currentCount = interceptionTasks.length;
		await Promise.all(interceptionTasks.slice(settledCount, currentCount));
		settledCount = currentCount;
	}
}

function installExternalRequestBlocker() {
	client.on('Fetch.requestPaused', params => {
		const task = (async () => {
			const requestOrigin = new URL(params.request.url).origin;

			if (requestOrigin === localOrigin) {
				await client.command('Fetch.continueRequest', { requestId: params.requestId });
				return;
			}

			blockedExternalRequests.push(params.request.url);
			await client.command('Fetch.failRequest', {
				requestId: params.requestId,
				errorReason: 'BlockedByClient',
			});
		})();

		interceptionTasks.push(task.catch(error => interceptionErrors.push(error)));
	});
}

function installRequestRecorder() {
	client.on('Network.requestWillBeSent', params => {
		const url = new URL(params.request.url);

		if (url.origin !== localOrigin) {
			return;
		}

		const route = url.searchParams.get('route') || '';
		requestedRoutes.push(route);

		if (!['checkout/cart/add', 'checkout/cart/edit', 'checkout/cart/remove'].includes(route)) {
			return;
		}

		const contentType = Object.entries(params.request.headers || {})
			.find(([name]) => name.toLowerCase() === 'content-type')?.[1] || '';
		const request = {
			route,
			requestId: params.requestId,
			method: params.request.method,
			url: params.request.url,
			contentType,
			rawQuery: url.search.slice(1),
			query: Array.from(url.searchParams.entries()),
			hasPostDataAtRequestEvent: typeof params.request.postData === 'string',
			rawPostData: typeof params.request.postData === 'string' ? params.request.postData : '',
			postData: typeof params.request.postData === 'string' ? params.request.postData : '',
			postDataError: '',
		};
		cartMutationRequests.push(request);

		if (request.method === 'POST') {
			const task = client.command('Network.getRequestPostData', { requestId: request.requestId })
				.then(response => {
					if (typeof response.postData === 'string') {
						request.postData = response.postData;
					}
				})
				.catch(error => {
					request.postDataError = error.message;
				});
			requestPostDataTasks.push(task);
		}
	});
}

function getRequestParameters(request) {
	let parameters;

	if (/^multipart\/form-data\b/i.test(request.contentType)) {
		const boundaryMatch = request.contentType.match(/\bboundary=(?:"([^"]+)"|([^;\s]+))/i);

		if (!boundaryMatch) {
			throw new Error('Multipart cart request did not provide a boundary in Content-Type.');
		}

		const boundary = boundaryMatch[1] || boundaryMatch[2];
		const delimiter = '--' + boundary;
		parameters = new URLSearchParams();

		for (const part of request.postData.split(delimiter)) {
			const separator = part.indexOf('\r\n\r\n');

			if (separator < 0) {
				continue;
			}

			const headers = part.slice(0, separator);
			const nameMatch = headers.match(/content-disposition:\s*form-data;\s*name="([^"]+)"/i);

			if (!nameMatch) {
				continue;
			}

			const value = part.slice(separator + 4).replace(/\r\n$/, '');
			parameters.append(nameMatch[1], value);
		}
	} else {
		parameters = new URLSearchParams(request.postData);
	}

	for (const [key, value] of new URL(request.url).searchParams.entries()) {
		if (!parameters.has(key)) {
			parameters.append(key, value);
		}
	}

	return parameters;
}

async function getLoaderId() {
	const tree = await client.command('Page.getFrameTree');
	return tree.frameTree?.frame?.loaderId || '';
}

async function navigate(url) {
	if (new URL(url).origin !== localOrigin) {
		throw new Error('The browser may only navigate to the isolated local storefront.');
	}

	await client.command('Page.navigate', { url });
	await waitFor(async () => (await evaluate('document.readyState')) === 'complete', 'Local storefront did not finish navigation.');
	await prepareImages();
}

async function prepareImages() {
	await evaluate('(async () => { await document.fonts.ready; for (const image of document.images) image.loading = "eager"; await Promise.all(Array.from(document.images, image => image.decode().catch(() => undefined))); return true; })()');
	await delay(100);
}

async function clickSelector(selector) {
	const rect = await evaluate(`(() => {
		const element = document.querySelector(${JSON.stringify(selector)});
		if (!element) return null;
		element.scrollIntoView({ block: 'center', inline: 'center' });
		const rect = element.getBoundingClientRect();
		return { x: rect.x, y: rect.y, width: rect.width, height: rect.height, disabled: Boolean(element.disabled) };
	})()`);

	if (!rect || rect.disabled || rect.width < 1 || rect.height < 1) {
		throw new Error('Visible enabled control was not found: ' + selector);
	}

	const x = Math.round(rect.x + rect.width / 2);
	const y = Math.round(rect.y + rect.height / 2);

	await client.command('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y });
	await client.command('Input.dispatchMouseEvent', { type: 'mousePressed', button: 'left', clickCount: 1, x, y });
	await client.command('Input.dispatchMouseEvent', { type: 'mouseReleased', button: 'left', clickCount: 1, x, y });
}

async function setQuantityWithKeyboard(selector, quantity) {
	await clickSelector(selector);
	await client.command('Input.dispatchKeyEvent', {
		type: 'keyDown',
		key: 'Control',
		code: 'ControlLeft',
		windowsVirtualKeyCode: 17,
		nativeVirtualKeyCode: 17,
	});
	await client.command('Input.dispatchKeyEvent', {
		type: 'keyDown',
		key: 'a',
		code: 'KeyA',
		windowsVirtualKeyCode: 65,
		nativeVirtualKeyCode: 65,
		modifiers: 2,
	});
	await client.command('Input.dispatchKeyEvent', {
		type: 'keyUp',
		key: 'a',
		code: 'KeyA',
		windowsVirtualKeyCode: 65,
		nativeVirtualKeyCode: 65,
		modifiers: 2,
	});
	await client.command('Input.dispatchKeyEvent', {
		type: 'keyUp',
		key: 'Control',
		code: 'ControlLeft',
		windowsVirtualKeyCode: 17,
		nativeVirtualKeyCode: 17,
	});
	await client.command('Input.insertText', { text: quantity });
}

async function readCartState() {
	return evaluate(`(() => {
		const cart = document.querySelector('#checkout-cart');
		const items = Array.from(document.querySelectorAll('#checkout-cart .cart-item:not(.cart-item--voucher)'));
		const vouchers = Array.from(document.querySelectorAll('#checkout-cart .cart-item--voucher'));
		const first = items[0];
		const amountDigits = value => (value || '').replace(/[^0-9]/g, '');
		const totalRows = Array.from(document.querySelectorAll('#checkout-cart .cart-summary table tr'));
		const finalTotalRow = totalRows.find(row => /^(Итого|Total)/i.test(row.querySelector('td strong')?.textContent.trim() || ''));
		const quantity = first?.querySelector('input[name^="quantity["]');
		const unitPrice = first?.querySelector('.cart-item__price strong')?.textContent || '';
		const lineTotal = first?.querySelector('.cart-item__total strong')?.textContent || '';
		const heading = cart?.querySelector('.cart-head h1')?.textContent.trim() || '';
		const emptyState = cart?.querySelector('.cart-empty');
		return {
			route: new URL(location.href).searchParams.get('route') || '',
			heading,
			productCount: items.length,
			voucherCount: vouchers.length,
			productId: first?.querySelector('[data-cart-remove][data-product-id]')?.getAttribute('data-product-id') || '',
			cartId: first?.querySelector('[data-cart-remove]')?.getAttribute('data-cart-remove') || '',
			name: first?.querySelector('.cart-item__top h2 a')?.textContent.trim() || '',
			model: first?.querySelector('.cart-item__model')?.textContent.replace(/^.*?:\\s*/, '').trim() || '',
			productHref: first?.querySelector('.cart-item__top h2 a')?.href || '',
			quantity: quantity?.value || '',
			unitPriceMinor: amountDigits(unitPrice),
			lineTotalMinor: amountDigits(lineTotal),
			lineTotalMatchesQuantity: !!quantity && amountDigits(lineTotal) === String(Number(amountDigits(unitPrice)) * Number(quantity.value)),
			summaryTotalMinor: finalTotalRow ? amountDigits(finalTotalRow.querySelector('td:last-child')?.textContent || '') : '',
			cartSummaryRows: totalRows.map(row => row.textContent.trim()),
			emptyStateVisible: !!emptyState && emptyState.getClientRects().length > 0,
			emptyStateText: emptyState?.textContent.trim() || '',
			productVisible: !!first && first.getClientRects().length > 0,
			pageHasPhpError: /Warning:|Deprecated:|Fatal error|Array to string conversion/i.test(document.documentElement.outerHTML),
			viewportWidth: window.innerWidth,
			documentWidth: document.documentElement.scrollWidth,
			documentHeight: document.documentElement.scrollHeight,
			horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
			productImageLoaded: !!first?.querySelector('.cart-item__image img')?.complete && (first?.querySelector('.cart-item__image img')?.naturalWidth || 0) > 0,
		};
	})()`);
}

async function captureStateAtWidths(stateName) {
	const captures = [];

	for (const width of [320, 390, 1440]) {
		await client.command('Emulation.setDeviceMetricsOverride', {
			width,
			height: 1000,
			deviceScaleFactor: 1,
			mobile: false,
		});
		await delay(100);
		await prepareImages();

		const state = await readCartState();

		if (state.viewportWidth !== width || state.horizontalOverflow) {
			throw new Error('Cart state has the wrong viewport width or horizontal overflow at ' + width + 'px.');
		}

		let screenshotPath = '';
		let screenshotWidth = 0;

		if (captureDirectory) {
			const metrics = await client.command('Page.getLayoutMetrics');
			const content = metrics.cssContentSize || metrics.contentSize;
			const screenshot = await client.command('Page.captureScreenshot', {
				format: 'png',
				fromSurface: true,
				captureBeyondViewport: true,
				clip: { x: 0, y: 0, width, height: content.height, scale: 1 },
			});
			const screenshotData = Buffer.from(screenshot.data, 'base64');
			screenshotWidth = screenshotData.readUInt32BE(16);
			screenshotPath = join(captureDirectory, 'cart-' + stateName + '-' + width + '.png');
			await writeFile(screenshotPath, screenshotData);

			if (screenshotWidth !== width) {
				throw new Error('Screenshot width did not match viewport ' + width + 'px.');
			}
		}

		captures.push({ ...state, screenshotPath, screenshotWidth });
	}

	return captures;
}

try {
	client = await connect(await debuggingPort());
	installExternalRequestBlocker();
	installRequestRecorder();
	await client.command('Network.enable');
	await client.command('Network.setBypassServiceWorker', { bypass: true });
	await client.command('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }] });
	await client.command('Page.enable');
	await client.command('Emulation.setDeviceMetricsOverride', {
		width: 1440,
		height: 1000,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await client.command('Network.setCookie', {
		name: 'sylora_cookie_consent',
		value: 'v1%3Aessential',
		url: targetUrl,
		path: '/',
		sameSite: 'Lax',
	});
	await navigate(targetUrl);
	const consentChoice = await evaluate(`(() => {
		const cookie = document.cookie.split(';').map(value => value.trim()).find(value => value.startsWith('sylora_cookie_consent=')) || '';
		return cookie ? decodeURIComponent(cookie.slice('sylora_cookie_consent='.length)) : '';
	})()`);

	if (consentChoice !== 'v1:essential') {
		throw new Error('The isolated storefront did not retain the synthetic essential-only consent cookie.');
	}

	const searchUrl = new URL('?route=product/search', targetUrl);
	searchUrl.searchParams.set('search', productSearchTerm);
	await navigate(searchUrl.href);

	const product = await evaluate(`(() => {
		const card = Array.from(document.querySelectorAll('.catalog-card'))
			.find(item => item.querySelector('.catalog-card__title a')?.textContent.trim() === ${JSON.stringify(productName)});
		const button = card?.querySelector('[data-cart-add]');
		const link = card?.querySelector('.catalog-card__title a');
		return {
			id: button?.getAttribute('data-cart-add') || '',
			name: link?.textContent.trim() || '',
			canAdd: !!button && !button.disabled,
			quantity: button?.getAttribute('data-quantity') || '',
			productHref: link?.href || '',
		};
	})()`);

	if (!product.id || product.name !== productName || !product.canAdd || product.quantity !== '1' || !product.productHref || new URL(product.productHref).origin !== localOrigin) {
		throw new Error('The synthetic product card is not ready for an initial quantity-one cart add.');
	}

	await clickSelector('.catalog-card [data-cart-add="' + product.id + '"]');

	const notice = await evaluate(`(async () => {
		for (let attempt = 0; attempt < 150; attempt++) {
			const element = document.querySelector('#site-notifications .cart-notice[role="status"]');
			const link = element?.querySelector('.cart-notice__actions a.btn-primary');
			if (element && link) {
				return { visible: element.getBoundingClientRect().height > 0, name: element.textContent.trim(), href: link.href };
			}
			await new Promise(resolveNotice => setTimeout(resolveNotice, 100));
		}
		return null;
	})()`);

	if (!notice?.visible || !notice.href || new URL(notice.href).origin !== localOrigin) {
		throw new Error('The product card did not expose a local cart action after the add.');
	}

	const afterAddLoaderId = await getLoaderId();
	await clickSelector('#site-notifications .cart-notice__actions a.btn-primary');
	await waitFor(async () => (await getLoaderId()) !== afterAddLoaderId, 'The add notice did not open the cart page.');
	await waitFor(async () => (await evaluate('!!document.querySelector("#checkout-cart .cart-item:not(.cart-item--voucher)")')), 'The added fixture product was not rendered in the cart.');

	const initialCart = await readCartState();

	if (initialCart.productCount !== 1 || initialCart.quantity !== '1' || initialCart.productId !== product.id) {
		throw new Error('The cart does not contain the single synthetic product at quantity one.');
	}

	if (initialCart.pageHasPhpError) {
		throw new Error('The cart page rendered a PHP diagnostic before the update.');
	}

	const quantitySelector = '#checkout-cart input[name="quantity[' + initialCart.cartId + ']"]';
	await setQuantityWithKeyboard(quantitySelector, '2');

	const typedQuantity = await evaluate(`document.querySelector(${JSON.stringify(quantitySelector)})?.value || ''`);

	if (typedQuantity !== '2') {
		throw new Error('Typing quantity two into the cart form did not update its native input.');
	}

	const beforeEditLoaderId = await getLoaderId();
	await clickSelector('#checkout-cart .cart-quantity button[type="submit"]');
	await waitFor(async () => (await getLoaderId()) !== beforeEditLoaderId, 'Submitting the cart quantity form did not navigate back to the cart.');
	await waitFor(async () => (await evaluate(`document.readyState === 'complete' && document.querySelector('#checkout-cart input[name="quantity[' + ${JSON.stringify(initialCart.cartId)} + ']"]')?.value === '2'`)), 'The cart page did not render the submitted quantity.');

	const updatedCart = await readCartState();

	if (updatedCart.productCount !== 1 || updatedCart.quantity !== '2' || updatedCart.productId !== product.id) {
		throw new Error('The cart quantity form did not persist the requested quantity two.');
	}

	if (updatedCart.pageHasPhpError) {
		throw new Error('The cart page rendered a PHP diagnostic after the quantity update.');
	}

	const cartCaptures = await captureStateAtWidths('quantity');
	const beforeRemoveLoaderId = await getLoaderId();
	await clickSelector('#checkout-cart [data-cart-remove][data-product-id]');
	await waitFor(async () => (await getLoaderId()) !== beforeRemoveLoaderId, 'The cart remove control did not return to the cart page.');
	await waitFor(async () => (await evaluate('!!document.querySelector("#checkout-cart .cart-empty")')), 'Removing the only cart item did not show the empty state.');

	const removedCart = await readCartState();

	if (removedCart.productCount !== 0 || removedCart.voucherCount !== 0 || !removedCart.emptyStateVisible) {
		throw new Error('The cart did not reach its real empty state after removal.');
	}

	if (removedCart.pageHasPhpError || removedCart.route !== 'checkout/cart') {
		throw new Error('The emptied cart route or document state is invalid.');
	}

	const emptyCaptures = await captureStateAtWidths('empty');
	const expectedMutationRoutes = ['checkout/cart/add', 'checkout/cart/edit', 'checkout/cart/remove'];
	const mutations = cartMutationRequests.filter(request => expectedMutationRoutes.includes(request.route));
	await Promise.all(requestPostDataTasks);

	if (mutations.length !== 3 || mutations.map(request => request.route).join(',') !== expectedMutationRoutes.join(',')) {
		throw new Error('The cart did not issue the expected add, edit, and remove requests in order.');
	}

	const addBody = getRequestParameters(mutations[0]);
	const editBody = getRequestParameters(mutations[1]);
	const removeBody = getRequestParameters(mutations[2]);
	const cartQuantityEntry = Array.from(editBody.entries()).find(([key]) => /^quantity\[[^\]]+\]$/.test(key));
	const editCartId = cartQuantityEntry ? cartQuantityEntry[0].slice('quantity['.length, -1) : '';
	const removeCartId = removeBody.get('key') || '';

	if (
		mutations.some(request => request.method !== 'POST')
		|| addBody.get('product_id') !== product.id
		|| addBody.get('quantity') !== '1'
		|| cartQuantityEntry?.[1] !== '2'
		|| editCartId !== initialCart.cartId
		|| removeCartId !== initialCart.cartId
	) {
		const requestEvidence = mutations.map(request => ({
			route: request.route,
			requestId: request.requestId,
			method: request.method,
			url: request.url,
			contentType: request.contentType,
			hasPostDataAtRequestEvent: request.hasPostDataAtRequestEvent,
			rawQuery: request.rawQuery,
			query: request.query,
			rawPostData: request.rawPostData,
			resolvedPostData: request.postData,
			postDataError: request.postDataError,
		}));
		throw new Error('Observed cart mutation requests did not contain the fixture product, quantity, and cart key: ' + JSON.stringify({
			requestEvidence,
			parsed: {
				addProductId: addBody.get('product_id'),
				addQuantity: addBody.get('quantity'),
				editQuantityEntry: cartQuantityEntry,
				editCartId,
				removeCartId,
				expectedProductId: product.id,
				expectedCartId: initialCart.cartId,
			},
		}));
	}

	const forbiddenCheckoutRouteVisited = requestedRoutes.some(route => (
		route === 'checkout/checkout'
		|| route === 'checkout/confirm'
		|| route.startsWith('extension/payment/')
	));

	if (forbiddenCheckoutRouteVisited) {
		throw new Error('The cart lifecycle scenario reached a checkout confirmation or payment route.');
	}

	result = {
		localOrigin,
		productName: product.name,
		productId: product.id,
		addNoticeVisible: notice.visible,
		addNoticeText: notice.name,
		initialCart,
		updatedCart,
		removedCart,
		cartMutationRequests: mutations.map(request => {
			const body = getRequestParameters(request);
			const quantityEntry = Array.from(body.entries()).find(([key]) => /^quantity\[[^\]]+\]$/.test(key));
			const cartId = quantityEntry ? quantityEntry[0].slice('quantity['.length, -1) : body.get('key') || '';
			return {
				route: request.route,
				method: request.method,
				productId: body.get('product_id') || '',
				quantity: body.get('quantity') || quantityEntry?.[1] || '',
				cartId,
			};
		}),
		cartMutationRequestEvidence: mutations.map(request => ({
			route: request.route,
			requestId: request.requestId,
			method: request.method,
			url: request.url,
			contentType: request.contentType,
			hasPostDataAtRequestEvent: request.hasPostDataAtRequestEvent,
			rawQuery: request.rawQuery,
			query: request.query,
			rawPostData: request.rawPostData,
			resolvedPostData: request.postData,
			postDataError: request.postDataError,
		})),
		requestedRoutes,
		forbiddenCheckoutRouteVisited,
		cartCaptures,
		emptyCaptures,
		externalRequests: blockedExternalRequests,
		artifactDirectory: captureDirectory,
		reportPath: captureDirectory ? join(captureDirectory, 'cart-quantity-lifecycle-report.json') : '',
	};
} catch (error) {
	failure = error;
} finally {
	if (client) {
		try {
			await client.command('Page.stopLoading');
			await settleInterceptionTasks();
		} catch (error) {
			interceptionErrors.push(error);
		}

		client.close();
	}

	if (!hasBrowserExited()) {
		chrome.kill('SIGTERM');
		await waitForBrowserExit();
	}

	if (!hasBrowserExited()) {
		chrome.kill('SIGKILL');
		await waitForBrowserExit();
	}

	browserProcessExited = hasBrowserExited();

	try {
		await rm(profile, { recursive: true, force: true, maxRetries: 12, retryDelay: 250 });
		profileRemoved = !(await pathExists(profile));
	} catch (error) {
		failure ??= new Error('Chrome profile cleanup failed: ' + error.message);
	}
}

if (interceptionErrors.length > 0) {
	failure ??= new Error('External request blocking failed: ' + interceptionErrors[0].message);
}

if (blockedExternalRequests.length > 0) {
	failure ??= new Error('External browser requests were blocked: ' + Array.from(new Set(blockedExternalRequests)).join(', '));
}

if (!browserProcessExited || !profileRemoved) {
	failure ??= new Error('Browser process or profile cleanup did not complete.');
}

if (failure) {
	throw failure;
}

result.cleanup = { browserProcessExited, profileRemoved };

if (captureDirectory) {
	await writeFile(result.reportPath, JSON.stringify(result, null, 2) + '\n');
}

process.stdout.write(JSON.stringify(result));
