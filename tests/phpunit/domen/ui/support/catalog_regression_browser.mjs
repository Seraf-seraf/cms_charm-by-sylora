import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [, , targetUrl, scenario, chromeBinary] = process.argv;

if (!targetUrl || !['filter', 'visual'].includes(scenario) || !chromeBinary) throw new Error('Usage: catalog_regression_browser.mjs <url> <filter|visual> <chrome-binary>');

const profile = await mkdtemp(join(tmpdir(), 'sylora-catalog-regression-'));
const chrome = spawn(chromeBinary, ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-background-networking', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore' });
const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

async function debuggingPort() {
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
	let id = 0;
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
	client = await connect(await debuggingPort());
	await client.command('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
	await navigate(client, targetUrl);

	if (scenario === 'filter') {
		const candidate = await evaluate(client, `(async () => {
			const links = root => Array.from(root.querySelectorAll('.catalog-card__title a'), item => item.href);
			const searchUrl = new URL(location.href); searchUrl.search = '?route=product/search';
			const allUrl = new URL(searchUrl); allUrl.searchParams.set('limit', '100');
			const documentAll = new DOMParser().parseFromString(await (await fetch(searchUrl)).text(), 'text/html');
			const key = href => { const url = new URL(href); return url.searchParams.get('product_id') ? 'product:' + url.searchParams.get('product_id') : url.pathname; };
			const allDocument = new DOMParser().parseFromString(await (await fetch(allUrl)).text(), 'text/html'); const all = links(allDocument).map(key);
			for (const option of documentAll.querySelectorAll('select[name="category_id"] option:not([value="0"])')) {
				const filteredUrl = new URL(searchUrl); filteredUrl.searchParams.set('category_id', option.value);
				const filteredDocument = new DOMParser().parseFromString(await (await fetch(filteredUrl)).text(), 'text/html'); const products = links(filteredDocument);
				if (products.length && products.length < all.length && products.every(link => all.includes(key(link)))) return { category: option.value, products };
			}
			throw new Error('No category with a strict product subset was found.');
		})()`);
		await navigate(client, new URL('?route=product/search', targetUrl).href);
		const submitted = await evaluate(client, `(() => { const select = document.querySelector('select[name="category_id"]'); const form = document.querySelector('.catalog-search-panel'); if (!select || !form) return false; select.value = ${JSON.stringify(candidate.category)}; form.requestSubmit(); return true; })()`);
		await delay(1500);
		const actual = await evaluate(client, `(() => ({ selected: document.querySelector('select[name="category_id"]')?.value || '', category: new URL(location.href).searchParams.get('category_id') || '', products: Array.from(document.querySelectorAll('.catalog-card__title a'), item => item.href) }))()`);
		const productKey = href => { const url = new URL(href); return url.searchParams.get('product_id') ? 'product:' + url.searchParams.get('product_id') : url.pathname; };
		process.stdout.write(JSON.stringify({ submitted, expectedCategory: candidate.category, selectedCategory: actual.selected, urlCategory: actual.category, expectedProducts: candidate.products.map(productKey), actualProducts: actual.products.map(productKey) }));
	} else {
		const hero = await evaluate(client, `(() => { const slider = document.querySelector('.sylora-product-slider'); const images = Array.from(document.querySelectorAll('.sylora-product-slide:not([aria-hidden="true"]) img')).slice(0, 3); const frames = images.map(image => ({ width: Math.round(image.getBoundingClientRect().width), height: Math.round(image.getBoundingClientRect().height) })); return { equalFrames: frames.length > 1 && frames.every(frame => frame.width === frames[0].width && frame.height === frames[0].height), objectFits: [...new Set(images.map(image => getComputedStyle(image).objectFit))], beforeContent: getComputedStyle(slider, '::before').content, afterContent: getComputedStyle(slider, '::after').content }; })()`);
		const productUrl = await evaluate(client, `(async () => { const searchUrl = new URL(location.href); searchUrl.search = '?route=product/search&limit=100'; const catalog = new DOMParser().parseFromString(await (await fetch(searchUrl)).text(), 'text/html'); const button = catalog.querySelector('.catalog-card__wishlist'); const productId = button?.getAttribute('onclick')?.match(/wishlist\\.add\\('([0-9]+)'/)?.[1]; if (!productId) throw new Error('A real catalog product was not found.'); const url = new URL(location.href); url.search = '?route=product/product&product_id=' + productId; return url.href; })()`);
		await navigate(client, productUrl);
		const product = await evaluate(client, `(() => { const media = document.querySelector('.product-page__media'); const image = document.querySelector('.product-gallery__main img'); const metaLabel = document.querySelector('.product-meta__label'); if (!media || !image || !metaLabel) throw new Error('Product visual nodes were not found.'); const mediaRect = media.getBoundingClientRect(); const imageRect = image.getBoundingClientRect(); const labelRect = metaLabel.getBoundingClientRect(); return { widthOccupancy: imageRect.width / mediaRect.width, objectFit: getComputedStyle(image).objectFit, metaLabelSingleLine: labelRect.height <= parseFloat(getComputedStyle(metaLabel).lineHeight) + 1 }; })()`);
		await navigate(client, new URL('?route=product/search', targetUrl).href);
		const catalog = await evaluate(client, `(() => { const cards = Array.from(document.querySelectorAll('.catalog-card')).slice(0, 3); const media = cards.map(card => card.querySelector('.catalog-card__media')?.getBoundingClientRect()).filter(Boolean); const bottoms = cards.map(card => Math.round(card.querySelector('.catalog-card__actions')?.getBoundingClientRect().bottom || 0)); return { equalMedia: media.length > 1 && media.every(rect => Math.round(rect.width) === Math.round(media[0].width) && Math.round(rect.height) === Math.round(media[0].height)), objectFits: [...new Set(cards.map(card => getComputedStyle(card.querySelector('.catalog-card__image-primary')).objectFit))], actionsAligned: bottoms.length > 1 && bottoms.every(bottom => Math.abs(bottom - bottoms[0]) <= 1) }; })()`);
		process.stdout.write(JSON.stringify({ hero, product, catalog }));
	}
} finally {
	if (client) client.close();
	const chromeExited = new Promise(resolve => chrome.once('exit', resolve));
	chrome.kill('SIGTERM');
	await Promise.race([chromeExited, delay(2000)]);
	await rm(profile, { recursive: true, force: true });
}
