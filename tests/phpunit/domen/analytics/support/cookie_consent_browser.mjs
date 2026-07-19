import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [, , targetUrl, scenario, chromeBinary] = process.argv;

if (!targetUrl || !scenario || !chromeBinary) {
	throw new Error('Usage: cookie_consent_browser.mjs <url> <scenario> <chrome-binary>');
}

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

	if (response.exceptionDetails) throw new Error('Browser expression failed.');
	return response.result?.value;
}

let client;

try {
	const port = await getDebuggingPort();
	client = await connect(port);
	const requests = [];
	client.on('Network.requestWillBeSent', params => requests.push(params.request.url));
	await client.command('Network.enable');
	await client.command('Page.navigate', { url: targetUrl });
	await delay(1800);
	let refusalRequestOffset = requests.length;

	if (scenario === 'accept' || scenario === 'refuse') {
		await evaluate(client, 'document.querySelector("[data-cookie-consent=analytics]").click()');
		await delay(4000);
	}

	if (scenario === 'refuse') {
		await evaluate(client, 'document.querySelector("[data-cookie-settings]").click()');
		refusalRequestOffset = requests.length;
		await evaluate(client, 'document.querySelector("[data-cookie-consent=essential]").click()');
		await delay(2500);
	}

	const state = JSON.parse(await evaluate(client, `JSON.stringify({
		bannerHidden: document.getElementById('analytics-cookie-banner')?.hidden ?? true,
		bannerTitle: document.getElementById('analytics-cookie-title')?.textContent ?? '',
		acceptText: document.querySelector('[data-cookie-consent=analytics]')?.textContent ?? '',
		rejectText: document.querySelector('[data-cookie-consent=essential]')?.textContent ?? '',
		settingsText: document.querySelector('[data-cookie-settings]')?.textContent ?? '',
		extensionScriptCount: Array.from(document.scripts).filter(script => script.src.includes('yandex_metrica_consent.js')).length,
		dataLayerExists: Array.isArray(window.dataLayer),
		consent: (document.cookie.match(/(?:^|; )sylora_cookie_consent=([^;]*)/) || [,''])[1] ? decodeURIComponent((document.cookie.match(/(?:^|; )sylora_cookie_consent=([^;]*)/) || [,''])[1]) : '',
		ymCookies: document.cookie.split(';').map(value => value.trim()).filter(value => value.startsWith('_ym_')),
		metricaScripts: Array.from(document.scripts).map(script => script.src).filter(source => source.includes('mc.yandex.ru'))
	})`));
	const isMetricaRequest = url => /^https:\/\/mc\.yandex\.ru\//.test(url);
	state.metricaRequests = requests.filter(isMetricaRequest);
	state.metricaRequestsAfterRefusal = requests.slice(refusalRequestOffset).filter(isMetricaRequest);
	process.stdout.write(`${JSON.stringify(state)}\n`);
} finally {
	if (client) client.close();
	chrome.kill('SIGTERM');
	await Promise.race([
		new Promise(resolve => chrome.once('exit', resolve)),
		delay(2000),
	]);
	await rm(profile, { recursive: true, force: true });
}
