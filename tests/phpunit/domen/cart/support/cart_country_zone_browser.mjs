import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [, , targetUrl, scenario, chromeBinary] = process.argv;

if (!targetUrl || scenario !== 'country-zone' || !chromeBinary) {
	throw new Error('Usage: cart_country_zone_browser.mjs <url> country-zone <chrome-binary>');
}

const profile = await mkdtemp(join(tmpdir(), 'sylora-cart-country-zone-'));
const chrome = spawn(chromeBinary, [
	'--headless=new',
	'--no-sandbox',
	'--disable-gpu',
	'--disable-background-networking',
	'--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1',
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
		} catch (error) {
			if (error?.code !== 'ENOENT') throw error;
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
	const events = [];
	let id = 0;

	socket.addEventListener('message', event => {
		const message = JSON.parse(event.data);

		if (message.id && pending.has(message.id)) {
			pending.get(message.id)(message);
			pending.delete(message.id);
		} else if (message.method) {
			events.push(message);
		}
	});

	await new Promise((resolve, reject) => {
		socket.addEventListener('open', resolve, { once: true });
		socket.addEventListener('error', reject, { once: true });
	});

	return {
		events,
		close: () => socket.close(),
		command(method, params = {}) {
			return new Promise((resolve, reject) => {
				const commandId = ++id;
				pending.set(commandId, message => message.error ? reject(new Error(message.error.message)) : resolve(message.result || {}));
				socket.send(JSON.stringify({ id: commandId, method, params }));
			});
		},
	};
}

async function evaluate(client, expression) {
	const response = await client.command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });

	if (response.exceptionDetails) throw new Error(response.exceptionDetails.exception?.description || response.exceptionDetails.text);

	return response.result?.value;
}

async function navigate(client, url) {
	await client.command('Page.navigate', { url });
	await delay(1500);
}

let client;

try {
	client = await connect(await getDebuggingPort());
	await client.command('Runtime.enable');
	await client.command('Page.enable');
	await client.command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: false });
	await navigate(client, targetUrl);

	const raw = await evaluate(client, `(async () => {
		const wait = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
		const waitFor = async (predicate, description) => {
			for (let attempt = 0; attempt < 100; attempt++) {
				if (predicate()) return;
				await wait(20);
			}
			throw new Error('Timed out waiting for ' + description);
		};
		const fixture = document.createElement('div');
		fixture.id = 'cart-country-zone-fixture';
		const config = document.createElement('script');
		config.id = 'cart-shipping-config';
		config.type = 'application/json';
		config.textContent = JSON.stringify({
			textShippingMethod: 'Shipping method',
			shippingMethod: '',
			buttonCancel: 'Cancel',
			buttonShipping: 'Continue',
			textLoading: 'Loading',
			textSelect: 'Select a region',
			zoneId: '',
			textNone: 'No regions available',
		});
		fixture.append(config);

		const country = document.createElement('select');
		country.id = 'input-country';
		country.name = 'country_id';
		country.innerHTML = '<option value="">Select a country</option><option value="9901">Synthetic Audit Country</option>';
		fixture.append(country);

		const zone = document.createElement('select');
		zone.id = 'input-zone';
		zone.name = 'zone_id';
		fixture.append(zone);

		const postcodeGroup = document.createElement('div');
		postcodeGroup.className = 'form-group';
		const postcodeColumn = document.createElement('div');
		const postcode = document.createElement('input');
		postcode.name = 'postcode';
		postcodeColumn.append(postcode);
		postcodeGroup.append(postcodeColumn);
		fixture.append(postcodeGroup);
		document.body.append(fixture);

		window.__cartCountryZone = {requests: [], errors: []};
		window.addEventListener('error', event => {
			if (event.filename.includes('/cart.js')) {
				window.__cartCountryZone.errors.push({message: event.message, line: event.lineno});
			}
		});

		$.ajaxTransport('+*', options => {
			const url = new URL(options.url, document.baseURI);
			if (url.searchParams.get('route') !== 'extension/total/shipping/country') return undefined;

			return {
				send(headers, complete) {
					const countryId = url.searchParams.get('country_id') || '';
					const zones = countryId === '9901' ? [{zone_id: '9901', name: 'Synthetic Audit Region'}] : [];
					window.__cartCountryZone.requests.push(countryId);
					setTimeout(() => complete(200, 'OK', {
						text: JSON.stringify({postcode_required: countryId === '9901' ? '1' : '0', zone: zones}),
					}, 'Content-Type: application/json'), 10);
				},
				abort() {},
			};
		});

		const script = document.createElement('script');
		script.src = new URL('catalog/view/javascript/cart.js?v=20261114-cart-country-zone-v1', document.baseURI).href;
		await new Promise((resolve, reject) => {
			script.addEventListener('load', resolve, {once: true});
			script.addEventListener('error', () => reject(new Error('cart.js failed to load')), {once: true});
			document.head.append(script);
		});

		await waitFor(() => !country.disabled && Array.from(zone.options).some(option => option.value === '0'), 'initial empty-country response');
		const emptyCountryZoneText = zone.options[1]?.textContent.trim() || '';
		country.value = '9901';
		country.dispatchEvent(new Event('change', {bubbles: true}));
		await waitFor(() => !country.disabled && Array.from(zone.options).some(option => option.value === '9901'), 'synthetic country zones');
		zone.value = '9901';
		zone.dispatchEvent(new Event('change', {bubbles: true}));

		return JSON.stringify({
			countryRequests: window.__cartCountryZone.requests,
			selectedCountry: country.value,
			selectedZone: zone.value,
			selectedZoneName: zone.selectedOptions[0]?.textContent.trim() || '',
			emptyCountryZoneText,
			countryEnabled: !country.disabled,
			postcodeRequired: postcodeGroup.classList.contains('required'),
			cartScriptErrors: window.__cartCountryZone.errors,
		});
	})()`);

	const result = JSON.parse(raw);
	const browserErrors = client.events
		.filter(event => event.method === 'Runtime.exceptionThrown' && event.params.exceptionDetails.url?.includes('/cart.js'))
		.map(event => event.params.exceptionDetails.exception?.description || event.params.exceptionDetails.text);
	result.cartScriptErrors.push(...browserErrors);
	process.stdout.write(JSON.stringify(result));
} finally {
	if (client) client.close();
	const chromeExited = new Promise(resolve => chrome.once('exit', resolve));
	chrome.kill('SIGTERM');
	await Promise.race([chromeExited, delay(2000)]);
	if (chrome.exitCode === null) {
		chrome.kill('SIGKILL');
		await Promise.race([chromeExited, delay(2000)]);
	}
	await rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
}
