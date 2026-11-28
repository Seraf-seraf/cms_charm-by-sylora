import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [, , targetUrl, scenarioArg, chromeBinary] = process.argv;
const [scenario, scenarioArgument] = String(scenarioArg || '').split(':', 2);

if (!targetUrl || !scenario || !chromeBinary) {
	throw new Error('Usage: checkout_consent_browser.mjs <url> <scenario[:arg]> <chrome-binary>');
}

const profile = await mkdtemp(join(tmpdir(), 'sylora-checkout-consent-'));
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
		const description = response.exceptionDetails.exception?.description || response.exceptionDetails.text || 'browser runtime error';
		throw new Error(description);
	}

	return response.result?.value;
}

let client;

try {
	const port = await getDebuggingPort();
	client = await connect(port);
	await client.command('Page.navigate', { url: targetUrl });
	await delay(1400);

	let result;

	switch (scenario) {
		case 'account_page': {
			await client.command('Page.navigate', { url: new URL('index.php?route=account/register', targetUrl).href });
			await delay(600);
			result = await evaluate(client, `(() => {
				const link = document.querySelector('form input[name="agree"]') ? document.querySelector('a.agree') : null;
				const input = document.querySelector('form input[name="agree"]');
				return {
					agreeInputExists: Boolean(input),
					agreementText: (link && link.textContent ? link.textContent.trim() : ''),
					agreementHref: link ? link.getAttribute('href') : '',
					agreementInformationId: link && link.getAttribute('href') ? new URL(link.getAttribute('href'), location.href).searchParams.get('information_id') || '' : ''
				};
			})()`);
			break;
		}

		case 'checkout_register_fragment':
		case 'checkout_payment_method_fragment': {
			const route = scenario === 'checkout_register_fragment' ? 'checkout/register' : 'checkout/payment_method';
			result = await evaluate(client, `(() => {
				const parse = (content) => {
					const documentNode = new DOMParser().parseFromString(content, 'text/html');
					const link = documentNode.querySelector('a.agree');
					const input = documentNode.querySelector('input[name="agree"]');
					return {
						agreeInputExists: Boolean(input),
						agreementText: (link && link.textContent ? link.textContent.trim() : ''),
						agreementHref: link ? link.getAttribute('href') : '',
						agreementInformationId: link && link.getAttribute('href') ? new URL(link.getAttribute('href'), location.href).searchParams.get('information_id') || '' : ''
					};
				};
				return (async () => {
					const response = await fetch(new URL('index.php?route=${route}', location.href).href, { credentials: 'same-origin' });
					const text = await response.text();
					return parse(text);
				})()
			})()`);
			break;
		}

		case 'register_without_agreement': {
			result = await evaluate(client, `(() => {
				const payload = new URLSearchParams({
					customer_group_id: '1',
					firstname: 'Test',
					lastname: 'Customer',
					email: 'e2e-e05-' + Date.now() + '-' + Math.floor(Math.random() * 9999) + '@example.com',
					telephone: '1234567890',
					address_1: 'Test Street',
					address_2: 'Suite 1',
					city: 'Moscow',
					postcode: '123456',
					country_id: '222',
					zone_id: '3608',
					password: 'Password123!',
					confirm: 'Password123!'
				});
				return (async () => {
					const response = await fetch(new URL('index.php?route=account/register', location.href).href, {
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
						},
						body: payload.toString()
					});

					const responseBody = await response.text();
					let responseJson = {};
					let parsingError = null;
					try {
						responseJson = JSON.parse(responseBody);
					} catch (error) {
						parsingError = error.message;
					}
					const parsed = new DOMParser().parseFromString(responseBody, 'text/html');
					const alert = parsed.querySelector('.alert-danger');
					return {
						status: response.status,
						responseBodyLength: responseBody.length,
						parsingError,
						responseJson,
						alertDangerText: alert ? alert.textContent.trim() : '',
						responseBody
					};
				})();
			})()`);
			break;
		}

		case 'information_agree_success': {
			if (!scenarioArgument) throw new Error('information_agree_success requires information id argument.');
			result = await evaluate(client, `(() => {
				return (async () => {
					const response = await fetch(new URL('index.php?route=information/information/agree&information_id=${scenarioArgument}', location.href).href, { credentials: 'same-origin' });
					return {
						status: response.status,
						text: await response.text()
					};
				})();
			})()`);
			break;
		}

		case 'information_agree_missing': {
			result = await evaluate(client, `(() => (async () => {
				const response = await fetch(new URL('index.php?route=information/information/agree', location.href).href, { credentials: 'same-origin' });
				return {
					status: response.status,
					text: await response.text()
				};
			})())()`);
			break;
		}

		case 'information_agree_wrong': {
			result = await evaluate(client, `(() => (async () => {
				const response = await fetch(new URL('index.php?route=information/information/agree&information_id=9999999', location.href).href, { credentials: 'same-origin' });
				return {
					status: response.status,
					text: await response.text()
				};
			})())()`);
			break;
		}

		default:
			throw new Error(`Unknown scenario: ${scenario}`);
	}

	process.stdout.write(`${JSON.stringify(result)}\n`);
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
