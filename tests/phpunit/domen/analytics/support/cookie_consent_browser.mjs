import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [, , targetUrl, scenario, chromeBinary] = process.argv;

if (!targetUrl || !scenario || !chromeBinary) {
	throw new Error('Usage: cookie_consent_browser.mjs <url> <scenario> <chrome-binary>');
}

const settingsMatch = /^settings-(analytics|essential)-(320|390|1440)$/.exec(scenario);
const viewportWidth = settingsMatch ? Number(settingsMatch[2]) : 1440;
const viewportHeight = viewportWidth === 1440 ? 640 : 720;
const shortViewportHeight = 240;
const waitTimeoutMilliseconds = 5000;
const pollIntervalMilliseconds = 50;
const selectors = {
	banner: '#analytics-cookie-banner',
	settings: '[data-cookie-settings]',
	close: '[data-cookie-close]',
	accept: '[data-cookie-consent=analytics]',
	reject: '[data-cookie-consent=essential]',
	privacy: '#analytics-cookie-banner .ym-consent__privacy a',
};

const profile = await mkdtemp(join(tmpdir(), 'sylora-cookie-consent-'));
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
	const listeners = new Map();
	let commandId = 0;

	socket.addEventListener('message', event => {
		const message = JSON.parse(event.data);

		if (message.id && pending.has(message.id)) {
			pending.get(message.id)(message);
			pending.delete(message.id);
		}

		if (message.method && listeners.has(message.method)) {
			for (const listener of listeners.get(message.method)) listener(message.params || {});
		}
	});

	socket.addEventListener('close', () => {
		for (const finish of pending.values()) {
			finish({ error: { message: 'Chrome DevTools disconnected.' } });
		}
		pending.clear();
	});

	await new Promise((resolve, reject) => {
		socket.addEventListener('open', resolve, { once: true });
		socket.addEventListener('error', reject, { once: true });
	});

	return {
		close: () => socket.close(),
		on(method, listener) {
			if (!listeners.has(method)) listeners.set(method, []);
			listeners.get(method).push(listener);
		},
		command(method, params = {}) {
			return new Promise((resolve, reject) => {
				if (socket.readyState !== WebSocket.OPEN) {
					reject(new Error('Chrome DevTools is disconnected.'));
					return;
				}
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
		throw new Error(response.exceptionDetails.exception?.description ?? 'Browser expression failed.');
	}
	return response.result?.value;
}


async function waitFor(client, expression) {
	const deadline = Date.now() + waitTimeoutMilliseconds;
	while (Date.now() < deadline) {
		try {
			if (await evaluate(client, expression)) return;
		} catch (error) {
			if (!/Execution context was destroyed|Cannot find context with specified id/.test(error.message)) {
				throw error;
			}
		}
		await delay(pollIntervalMilliseconds);
	}
	throw new Error(`Timed out waiting for ${expression}`);
}

async function setViewport(client, height) {
	await client.command('Emulation.setDeviceMetricsOverride', {
		width: viewportWidth, height, deviceScaleFactor: 1, mobile: false,
	});
}

function click(client, selector) {
	return evaluate(client, `document.querySelector(${JSON.stringify(selector)}).click()`);
}

async function clickVisible(client, selector) {
	const { x, y } = await evaluate(client, `(() => {
		const control = document.querySelector(${JSON.stringify(selector)});
		control.scrollIntoView({ block: 'nearest', behavior: 'instant' });
		const rect = control.getBoundingClientRect();
		return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
	})()`);
	await client.command('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
	await client.command('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
}

async function pressKey(client, key, modifiers = 0) {
	const keyCode = { Tab: 9, Escape: 27 }[key];
	const params = { key, code: key, modifiers, windowsVirtualKeyCode: keyCode };
	await client.command('Input.dispatchKeyEvent', { type: 'rawKeyDown', ...params });
	await client.command('Input.dispatchKeyEvent', { type: 'keyUp', ...params });
}

function isFocused(client, selector) {
	return evaluate(client, `document.activeElement.matches(${JSON.stringify(selector)})`);
}

async function consentState(client, localOrigin) {
	const { cookies } = await client.command('Network.getCookies', { urls: [localOrigin] });
	const browserState = await evaluate(client, `({
		choiceEvents: JSON.parse(sessionStorage.getItem('test-cookie-choices') || '[]'),
		metricaTagActive: Boolean(document.querySelector('script[src*="mc.yandex.ru/metrika/tag.js"]')),
		metricaTagMockLoaded: window.__syloraMetricaTagMockLoaded === true,
		dataLayerExists: Array.isArray(window.dataLayer),
		counterDisabled: window['disableYaCounter' + JSON.parse(document.querySelector('#yandex-metrica-consent-config').dataset.config).counter],
	})`);
	return { ...browserState, consentCookie: cookies.find(cookie => cookie.name === 'sylora_cookie_consent') };
}

async function checkSettings(client, localOrigin, requests) {
	const before = await consentState(client, localOrigin);
	const requestCount = requests.length;
	const analyticsEnabled = settingsMatch[1] === 'analytics';
	assert.equal(before.metricaTagActive, analyticsEnabled);
	assert.equal(before.counterDisabled, !analyticsEnabled);
	assert.deepEqual(before.choiceEvents, [analyticsEnabled ? 'v1:analytics' : 'v1:essential']);
	await clickVisible(client, selectors.settings);
	const dimensions = await evaluate(client, `(() => {
		const dialog = document.querySelector('#analytics-cookie-banner');
		const rect = dialog.getBoundingClientRect();
		return {
			width: rect.width, height: rect.height, left: rect.left, top: rect.top,
			viewportWidth: innerWidth, viewportHeight: innerHeight,
			backdrop: getComputedStyle(dialog, '::backdrop').backgroundColor,
			modal: dialog.matches(':modal'),
		};
	})()`);
	assert.equal(dimensions.viewportWidth, viewportWidth);
	assert.equal(dimensions.viewportHeight, viewportHeight);
	assert.equal(dimensions.width, { 320: 288, 390: 354, 1440: 560 }[viewportWidth], 'Panel width follows the Atelier dialog composition');
	assert.equal(dimensions.left, (dimensions.viewportWidth - dimensions.width) / 2);
	assert.ok(Math.abs(dimensions.top - (dimensions.viewportHeight - dimensions.height) / 2) <= 1);
	assert.equal(dimensions.modal, true);
	assert.equal(dimensions.backdrop, 'rgba(48, 37, 30, 0.33)');
	assert.equal(await isFocused(client, selectors.close), true, 'Focus starts on the close button');
	const savedChoiceLabel = await evaluate(client, 'document.querySelector("#analytics-cookie-choice").textContent');
	const openCopy = await evaluate(client, `({
		accept: document.querySelector('[data-cookie-consent=analytics]').textContent,
		reject: document.querySelector('[data-cookie-consent=essential]').textContent,
	})`);
	assert.deepEqual(openCopy, { accept: 'Разрешить аналитику', reject: 'Продолжить без аналитики' });

	const shiftModifier = 8;
	await pressKey(client, 'Tab', shiftModifier);
	assert.equal(await isFocused(client, selectors.reject), true, 'Shift+Tab wraps within the dialog');
	await pressKey(client, 'Tab');
	assert.equal(await isFocused(client, selectors.close), true, 'Tab wraps within the dialog');
	await pressKey(client, 'Tab');
	assert.equal(await isFocused(client, selectors.privacy), true, 'The configured policy is reachable');
	await pressKey(client, 'Tab');
	assert.equal(await isFocused(client, selectors.accept), true);
	await pressKey(client, 'Escape');
	assert.equal(await evaluate(client, 'document.querySelector("#analytics-cookie-banner").hidden'), true);
	assert.equal(await isFocused(client, selectors.settings), true, 'Escape restores focus to the footer');
	assert.deepEqual(await consentState(client, localOrigin), before);
	assert.equal(requests.length, requestCount);

	await clickVisible(client, selectors.settings);
	if (viewportWidth === 320) {
		await setViewport(client, shortViewportHeight);
		const viewport = await evaluate(client, `(() => {
			const dialog = document.querySelector('#analytics-cookie-banner');
			return { height: dialog.clientHeight, contentHeight: dialog.scrollHeight, pageY: scrollY };
		})()`);
		assert.ok(viewport.height < viewport.contentHeight, 'Content overflows the short dialog');
		assert.ok(viewport.height < shortViewportHeight, 'Panel fits in the short viewport');
		await pressKey(client, 'Tab', shiftModifier);
		assert.equal(await isFocused(client, selectors.reject), true);
		assert.ok(await evaluate(client, 'document.querySelector("#analytics-cookie-banner").scrollTop > 0'));
		assert.equal(await evaluate(client, 'scrollY'), viewport.pageY);
		await setViewport(client, viewportHeight);
	}
	await clickVisible(client, selectors.close);
	assert.equal(await evaluate(client, 'document.querySelector("#analytics-cookie-banner").hidden'), true);
	assert.equal(await isFocused(client, selectors.settings), true, 'Close button restores focus to the footer');
	assert.deepEqual(await consentState(client, localOrigin), before);
	assert.equal(requests.length, requestCount);

	await clickVisible(client, selectors.settings);
	return { savedChoiceLabel, settingsWidth: dimensions.width };
}

let client;

try {
	const port = await getDebuggingPort();
	client = await connect(port);
	const requests = [];
	const metricaMockRequests = [];
	const blockedExternalRequests = [];
	const interceptionErrors = [];
	const interceptionTasks = [];
	const localOrigin = new URL(targetUrl).origin;
	client.on('Network.requestWillBeSent', params => requests.push(params.request.url));
	await client.command('Network.enable');
	client.on('Fetch.requestPaused', params => {
		const task = (async () => {
			const requestUrl = new URL(params.request.url);
			const isMetricaTag = /^https:\/\/mc\.yandex\.ru\/metrika\/tag\.js(?:\?|$)/.test(params.request.url);

			if (isMetricaTag) {
				metricaMockRequests.push(params.request.url);
				await client.command('Fetch.fulfillRequest', {
					requestId: params.requestId,
					responseCode: 200,
					responseHeaders: [{ name: 'Content-Type', value: 'application/javascript; charset=utf-8' }],
					body: Buffer.from('window.__syloraMetricaTagMockLoaded = true;').toString('base64'),
				});
				return;
			}

			if (requestUrl.origin === localOrigin) {
				await client.command('Fetch.continueRequest', { requestId: params.requestId });
				return;
			}

			blockedExternalRequests.push(params.request.url);
			await client.command('Fetch.failRequest', { requestId: params.requestId, errorReason: 'BlockedByClient' });
		})();
		interceptionTasks.push(task.catch(async error => {
			interceptionErrors.push(error);
			try {
				await client.command('Fetch.failRequest', { requestId: params.requestId, errorReason: 'BlockedByClient' });
			} catch (failureError) {
				interceptionErrors.push(failureError);
			}
		}));
	});
	await client.command('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }] });
	await client.command('Page.enable');
	await client.command('Page.addScriptToEvaluateOnNewDocument', {
		source: `document.addEventListener('sylora:cookie-consent-changed', event => {
			const choices = JSON.parse(sessionStorage.getItem('test-cookie-choices') || '[]');
			choices.push(event.detail.choice);
			sessionStorage.setItem('test-cookie-choices', JSON.stringify(choices));
		});`,
	});
	await setViewport(client, viewportHeight);
	await client.command('Page.navigate', { url: targetUrl });
	await waitFor(client, 'document.readyState === "complete" && document.querySelector("#analytics-cookie-banner")');
	let refusalRequestOffset = metricaMockRequests.length;
	let settingsEvidence = {};

	if (scenario === 'initial') {
		await click(client, selectors.settings);
		await pressKey(client, 'Escape');
		assert.equal(await evaluate(client, 'document.querySelector("#analytics-cookie-banner").dataset.cookieMode'), undefined);
		assert.deepEqual(await evaluate(client, 'JSON.parse(sessionStorage.getItem("test-cookie-choices") || "[]")'), []);
	}

	if (scenario === 'accept' || scenario === 'refuse' || settingsMatch?.[1] === 'analytics') {
		await click(client, selectors.accept);
		await waitFor(client, 'window.__syloraMetricaTagMockLoaded === true');
	}

	if (scenario === 'refuse') {
		await evaluate(client, 'document.cookie = "_ym_consent_test=active; Path=/; SameSite=Lax"');
		assert.equal(await evaluate(client, 'document.cookie.includes("_ym_consent_test=active")'), true);
		await clickVisible(client, selectors.settings);
		const originalPageTime = await evaluate(client, 'performance.timeOrigin');
		refusalRequestOffset = metricaMockRequests.length;
		await clickVisible(client, selectors.reject);
		await waitFor(client, `performance.timeOrigin !== ${originalPageTime} && document.readyState === "complete" && document.querySelector("#analytics-cookie-banner")`);
		assert.deepEqual(await evaluate(client, 'JSON.parse(sessionStorage.getItem("test-cookie-choices"))'), ['v1:analytics', 'v1:essential']);
		assert.equal(await evaluate(client, 'window.__syloraMetricaTagMockLoaded'), undefined);
		await clickVisible(client, selectors.settings);
		assert.equal(await evaluate(client, 'document.querySelector("#analytics-cookie-choice").textContent'), 'Текущий выбор: без аналитики');
		await pressKey(client, 'Escape');
	}

	if (settingsMatch?.[1] === 'essential') {
		await click(client, selectors.reject);
	}

	if (settingsMatch) {
		settingsEvidence = await checkSettings(client, localOrigin, metricaMockRequests);
	}
	await Promise.all(interceptionTasks);
	if (interceptionErrors.length > 0) {
		throw new Error(`CDP request interception failed: ${interceptionErrors[0].message}`);
	}

	const state = JSON.parse(await evaluate(client, `JSON.stringify({
		bannerHidden: document.getElementById('analytics-cookie-banner')?.hidden ?? true,
		bannerTitle: document.getElementById('analytics-cookie-title')?.textContent ?? '',
		bannerDescription: document.querySelector('#analytics-cookie-banner .ym-consent__description')?.textContent ?? '',
		privacyText: document.querySelector('#analytics-cookie-banner .ym-consent__privacy a')?.textContent ?? '',
		privacyInOwnParagraph: Boolean(document.querySelector('#analytics-cookie-banner .ym-consent__privacy a')?.parentElement?.classList.contains('ym-consent__privacy')),
		acceptText: document.querySelector('[data-cookie-consent=analytics]')?.textContent ?? '',
		rejectText: document.querySelector('[data-cookie-consent=essential]')?.textContent ?? '',
		settingsText: document.querySelector('[data-cookie-settings]')?.textContent ?? '',
		settingsInSiteFooter: Boolean(document.querySelector('.site-footer__bottom > .ym-consent-settings')),
		settingsInCatalogCard: Boolean(document.querySelector('.catalog-card__actions .ym-consent-settings')),
		extensionScriptCount: Array.from(document.scripts).filter(script => script.src.includes('yandex_metrica_consent.js')).length,
		dataLayerExists: Array.isArray(window.dataLayer),
		metricaTagMockLoaded: window.__syloraMetricaTagMockLoaded === true,
		consent: (document.cookie.match(/(?:^|; )sylora_cookie_consent=([^;]*)/) || [,''])[1] ? decodeURIComponent((document.cookie.match(/(?:^|; )sylora_cookie_consent=([^;]*)/) || [,''])[1]) : '',
		ymCookies: document.cookie.split(';').map(value => value.trim()).filter(value => value.startsWith('_ym_')),
		metricaScripts: Array.from(document.scripts).map(script => script.src).filter(source => source.includes('mc.yandex.ru'))
	})`));
	const isMetricaRequest = url => /^https:\/\/mc\.yandex\.ru\//.test(url);
	Object.assign(state, settingsEvidence);
	state.metricaRequests = metricaMockRequests;
	state.metricaRequestsAfterRefusal = metricaMockRequests.slice(refusalRequestOffset);
	state.blockedExternalRequests = blockedExternalRequests;
	state.observedExternalNetworkRequests = requests.filter(url => new URL(url).origin !== localOrigin && !isMetricaRequest(url));
	process.stdout.write(`${JSON.stringify(state)}\n`);
} finally {
	if (client) {
		try {
			await client.command('Fetch.disable');
		} catch {
			// Chrome may have exited before interception could be disabled.
		}
		client.close();
	}
	const chromeExited = new Promise(resolve => chrome.once('exit', resolve));
	chrome.kill('SIGTERM');
	await Promise.race([chromeExited, delay(2000)]);
	if (chrome.exitCode === null) {
		chrome.kill('SIGKILL');
		await Promise.race([chromeExited, delay(2000)]);
	}
	await rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
}
