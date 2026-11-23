import { spawn } from 'node:child_process';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [, , targetUrl, scenarioArg, chromeBinary] = process.argv;
const [scenario, encodedConfig] = String(scenarioArg || '').split(':', 2);

if (!targetUrl || !scenario || !encodedConfig || !chromeBinary) {
	throw new Error('Usage: return_consent_browser.mjs <url> <audit|checked>:<config> <chrome-binary>');
}

const config = JSON.parse(Buffer.from(encodedConfig, 'base64url').toString('utf8'));
const profile = await mkdtemp(join(tmpdir(), 'sylora-return-consent-browser-'));
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
		const description = response.exceptionDetails.exception?.description || response.exceptionDetails.text || 'browser runtime error';
		throw new Error(description);
	}

	return response.result?.value;
}

async function navigate(client, url) {
	await client.command('Page.navigate', { url });
	await delay(1200);
}

async function waitForSelector(client, selector, timeoutMilliseconds = 10000) {
	const deadline = Date.now() + timeoutMilliseconds;

	while (Date.now() < deadline) {
		const exists = await evaluate(client, `Boolean(document.querySelector(${JSON.stringify(selector)}))`);
		if (exists) return;
		await delay(200);
	}

	const pageState = await evaluate(client, `JSON.stringify({
		url: location.href,
		title: document.title,
		headings: Array.from(document.querySelectorAll('h1, h2')).map(node => node.textContent.trim()),
		alerts: Array.from(document.querySelectorAll('.alert, .text-danger')).map(node => node.textContent.trim()).filter(Boolean),
		bodyText: document.body?.innerText.slice(0, 500) || ''
	})`);
	throw new Error(`Timed out waiting for selector ${selector}. Page state: ${pageState}`);
}

function routeUrl(route) {
	const url = new URL(targetUrl);
	url.search = '';
	url.searchParams.set('route', route);
	return url.href;
}

async function loginCustomer(client) {
	await navigate(client, routeUrl('account/return'));
	await waitForSelector(client, '#account-login form');
	await evaluate(client, `(() => {
		const form = document.querySelector('#account-login form');
		const email = form?.querySelector('[name="email"]');
		const password = form?.querySelector('[name="password"]');
		if (!form || !email || !password) throw new Error('OpenCart customer login form fields were not found.');
		email.value = ${JSON.stringify(config.customerEmail)};
		password.value = ${JSON.stringify(config.customerPassword)};
		form.requestSubmit();
		return true;
	})()`);
	await delay(1200);
	await navigate(client, routeUrl('account/return'));
	await waitForSelector(client, '#account-return');
	await navigate(client, routeUrl('account/return/add'));
	await waitForSelector(client, '#account-return form.return-form', 12000);
	const acceptedEssentialCookies = await evaluate(client, `(() => {
		const button = document.querySelector('[data-cookie-consent="essential"]');
		if (button) button.click();
		return !document.querySelector('#analytics-cookie-banner:not([hidden])');
	})()`);
	if (!acceptedEssentialCookies) throw new Error('The isolated browser could not dismiss the cookie banner using essential-only consent.');
	await delay(350);
}

async function configureExternalRequestBlocking(client) {
	const localOrigin = new URL(targetUrl).origin;
	const blockedExternalRequests = [];
	const interceptionErrors = [];
	const interceptionTasks = [];

	client.on('Fetch.requestPaused', params => {
		const task = (async () => {
			if (new URL(params.request.url).origin === localOrigin) {
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

	return {
		blockedExternalRequests,
		async finish() {
			await Promise.all(interceptionTasks);
			if (interceptionErrors.length > 0) throw new Error(`CDP request interception failed: ${interceptionErrors[0].message}`);
		},
	};
}

async function saveResponsiveScreenshots(client) {
	await mkdir(config.screenshotsDirectory, { recursive: true, mode: 0o700 });
	await client.command('Emulation.setScrollbarsHidden', { hidden: true });
	const screenshots = {};

	for (const width of [320, 390, 1440]) {
		await client.command('Emulation.setDeviceMetricsOverride', {
			width,
			height: 1000,
			deviceScaleFactor: 1,
			mobile: false,
		});
		await evaluate(client, 'window.scrollTo(0, 0); document.fonts?.ready');
		await delay(350);

		const capture = await client.command('Page.captureScreenshot', {
			format: 'png',
			fromSurface: true,
			captureBeyondViewport: true,
		});
		const path = join(config.screenshotsDirectory, `return-consent-${width}.png`);
		await writeFile(path, Buffer.from(capture.data, 'base64'), { mode: 0o600 });
		screenshots[String(width)] = path;
	}

	return screenshots;
}

async function readAgreement(client) {
	return await evaluate(client, `(() => {
		const input = document.querySelector('#account-return input[name="agree"]');
		const label = input?.closest('label');
		const link = label?.querySelector('a.agree');
		const normalize = value => String(value || '').replace(/\\s+/g, ' ').trim();
		return {
			checkboxExists: Boolean(input),
			checkboxChecked: input?.checked ?? true,
			checkboxValue: input?.value ?? '',
			checkboxRequired: input?.required ?? false,
			agreementLabelText: normalize(label?.textContent),
			agreementLinkText: normalize(link?.textContent),
			agreementHref: link?.href ?? '',
			agreementInformationId: link ? new URL(link.href).searchParams.get('information_id') || '' : '',
			linkInsideCheckboxLabel: Boolean(input && label && link && label.contains(link)),
			formAction: document.querySelector('#account-return form.return-form')?.action ?? '',
			pageRoute: new URL(location.href).searchParams.get('route') || '',
		};
	})()`);
}

async function readAgreementPage(client, href) {
	return await evaluate(client, `(() => (async () => {
		const response = await fetch(${JSON.stringify(href)}, { credentials: 'same-origin' });
		return { status: response.status, body: await response.text() };
	})())()`);
}

async function submitReturn(client, checked) {
	return await evaluate(client, `(() => (async () => {
		const form = document.querySelector('#account-return form.return-form');
		if (!form) throw new Error('OpenCart return form was not found before POST.');
		const payload = new FormData(form);
		payload.set('firstname', ${JSON.stringify(config.customerFirstname)});
		payload.set('lastname', ${JSON.stringify(config.customerLastname)});
		payload.set('email', ${JSON.stringify(config.customerEmail)});
		payload.set('telephone', ${JSON.stringify(config.customerTelephone)});
		payload.set('order_id', ${JSON.stringify(config.orderId)});
		payload.set('date_ordered', '2026-06-15');
		payload.set('product', 'Synthetic return item');
		payload.set('product_id', '0');
		payload.set('model', 'SYNTHETIC-RETURN-001');
		payload.set('quantity', '1');
		payload.set('return_reason_id', ${JSON.stringify(String(config.returnReasonId))});
		payload.set('opened', '0');
		payload.set('comment', 'Synthetic isolated return consent test.');
		if (${checked}) payload.set('agree', '1');
		else payload.delete('agree');

		let response;
		try {
			response = await fetch(form.action, {
				method: 'POST',
				credentials: 'same-origin',
				redirect: ${checked} ? 'manual' : 'follow',
				body: payload,
			});
		} catch (error) {
			return {
				status: 0,
				finalUrl: '',
				failure: String(error?.name || 'Error') + ': ' + String(error?.message || error),
				formAction: form.action,
			};
		}
		const responseBody = await response.text();
		const parsed = new DOMParser().parseFromString(responseBody, 'text/html');
		return {
			status: response.status,
			type: response.type,
			finalUrl: response.url,
			formAction: form.action,
			responseBody,
			warning: parsed.querySelector('#account-return .alert-danger')?.textContent.replace(/\\s+/g, ' ').trim() || '',
			successHeading: parsed.querySelector('#common-success h1')?.textContent.replace(/\\s+/g, ' ').trim() || '',
		};
	})())()`);
}

let client;
let interception;
const networkRequests = new Map();
const networkResponses = [];
const networkRedirects = [];
const networkFailures = [];

try {
	const port = await getDebuggingPort();
	client = await connect(port);
	await client.command('Page.enable');
	await client.command('Runtime.enable');
	client.on('Network.requestWillBeSent', params => {
		if (params.redirectResponse) {
			const headers = params.redirectResponse.headers || {};
			const location = Object.entries(headers).find(([name]) => name.toLowerCase() === 'location')?.[1] || '';
			networkRedirects.push({
				request: networkRequests.get(params.requestId) || null,
				status: params.redirectResponse.status,
				location: String(location),
				nextRequest: { url: params.request.url, method: params.request.method },
			});
		}
		networkRequests.set(params.requestId, { url: params.request.url, method: params.request.method });
	});
	client.on('Network.loadingFailed', params => {
		networkFailures.push({
			request: networkRequests.get(params.requestId) || null,
			errorText: params.errorText,
			blockedReason: params.blockedReason || '',
			corsErrorStatus: params.corsErrorStatus || null,
		});
	});
	client.on('Network.responseReceived', params => {
		const headers = params.response.headers || {};
		const location = Object.entries(headers).find(([name]) => name.toLowerCase() === 'location')?.[1] || '';
		networkResponses.push({
			request: networkRequests.get(params.requestId) || null,
			status: params.response.status,
			location: String(location),
		});
	});
	await client.command('Network.enable');
	interception = await configureExternalRequestBlocking(client);
	await loginCustomer(client);
	const agreement = await readAgreement(client);

	let result;
	if (scenario === 'audit') {
		const information = await readAgreementPage(client, agreement.agreementHref);
		const screenshots = await saveResponsiveScreenshots(client);
		const unchecked = await submitReturn(client, false);
		result = {
			...agreement,
			informationResponseStatus: information.status,
			informationResponseBody: information.body,
			uncheckedResponseStatus: unchecked.status,
			uncheckedResponseUrl: unchecked.finalUrl,
			uncheckedWarning: unchecked.warning,
			uncheckedRejected: new URL(unchecked.finalUrl).searchParams.get('route') === 'account/return/add' && unchecked.warning.includes(config.informationTitle),
			screenshots,
		};
	} else if (scenario === 'checked') {
		const checked = await submitReturn(client, true);
		const checkedRequestRoute = new URL(checked.formAction || agreement.formAction).searchParams.get('route');
		const checkedRedirect = networkRedirects.find(response => response.request?.method === 'POST' && new URL(response.request.url).searchParams.get('route') === checkedRequestRoute)
			|| networkResponses.find(response => response.request?.method === 'POST' && new URL(response.request.url).searchParams.get('route') === checkedRequestRoute)
			|| null;
		result = {
			...agreement,
			checkedResponseStatus: checked.status,
			checkedResponseType: checked.type || '',
			checkedFinalUrl: checked.finalUrl,
			checkedFormAction: checked.formAction || agreement.formAction,
			checkedRedirectStatus: checkedRedirect?.status ?? null,
			checkedRedirectLocation: checkedRedirect?.location ?? '',
			checkedResponseBody: checked.responseBody,
			checkedSuccessHeading: checked.successHeading,
		};
	} else {
		throw new Error(`Unknown scenario ${scenario}.`);
	}

	await delay(300);
	await interception.finish();
	result.blockedExternalRequestCount = interception.blockedExternalRequests.length;
	result.networkFailures = networkFailures;
	process.stdout.write(`${JSON.stringify(result)}\n`);
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
