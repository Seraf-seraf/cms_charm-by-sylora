import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [, , targetUrl, scenario, chromeBinary] = process.argv;

if (!targetUrl || !['filter', 'visual', 'cart', 'category'].includes(scenario) || !chromeBinary) throw new Error('Usage: catalog_regression_browser.mjs <url> <filter|visual|cart|category> <chrome-binary>');

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

async function runCategoryControls(client) {
	const widths = [1440, 390, 320];
	// Product rows in Figma nodes 133:1076 and 133:1650.
	const mobileLayouts = new Map([
		[390, { inset: 18, gridWidth: 354, cardWidth: 171, rowGap: '24px' }],
		[320, { inset: 16, gridWidth: 284, cardWidth: 284, rowGap: '24px' }],
	]);
	const viewportHeight = 1000;
	const desktopWidth = widths[0];
	const mobileWidth = widths[1];
	const summarySelector = '.catalog-filters__summary';
	const closeSelector = '.catalog-filters__close';

	const page = (fn, arg = null) => evaluate(client, `(${fn.toString()})(${JSON.stringify(arg)})`);
	const resize = width => client.command('Emulation.setDeviceMetricsOverride', {
		width,
		height: viewportHeight,
		deviceScaleFactor: 1,
		mobile: false,
	});
	const pressEnter = async selector => {
		await page(value => document.querySelector(value).focus(), selector);
		const key = { key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 };
		await client.command('Input.dispatchKeyEvent', { type: 'keyDown', ...key, text: '\r' });
		await client.command('Input.dispatchKeyEvent', { type: 'keyUp', ...key });
	};
	const afterNavigation = async (action, predicate) => {
		await action();
		for (let attempt = 0; attempt < 50; attempt++) {
			if (await page(predicate)) return;
			await delay(100);
		}
		throw new Error('Category navigation did not complete: ' + predicate.toString());
	};

	const root = await page(() => [...document.querySelectorAll('#site-nav a')]
		.find(link => link.textContent.trim() === 'Каталог').href);
	await client.command('Emulation.setScrollbarsHidden', { hidden: true });
	await navigate(client, root);
	await page(() => document.querySelector('[data-cookie-consent="essential"]')?.click());
	const rootPath = await page(() => document.querySelector('input[name="path"]:checked').value);
	const columns = [];

	for (const width of widths) {
		await resize(width);
		await delay(100);
		const layout = await page(() => {
			const grid = document.querySelector('#product-category .catalog-grid');
			const panel = document.querySelector('.catalog-filters');
			const chips = document.querySelector('.catalog-category-chips');
			const sort = document.querySelector('#input-sort');
			const limit = document.querySelector('#input-limit');
			const filtersButton = panel.querySelector('summary');
			const sortRect = sort.getBoundingClientRect();
			const limitRect = limit.getBoundingClientRect();
			const filterRect = filtersButton.getBoundingClientRect();
			const chipsRect = chips.getBoundingClientRect();
			const gridRect = grid.getBoundingClientRect();
			const gridStyle = getComputedStyle(grid);
			return {
				columns: gridStyle.gridTemplateColumns.split(' ').length,
				inset: gridRect.left,
				gridWidth: gridRect.width,
				cardWidth: grid.firstElementChild.getBoundingClientRect().width,
				rowGap: gridStyle.rowGap,
				columnGap: gridStyle.columnGap,
				fits: document.documentElement.scrollWidth === document.documentElement.clientWidth,
				panelOpen: panel.open,
				sidebar: panel.getBoundingClientRect().right <= grid.getBoundingClientRect().left,
				sortHeight: sortRect.height,
				limitHeight: limitRect.height,
				filterHeight: filterRect.height,
				chipsAboveControls: chipsRect.bottom <= filterRect.top,
				filtersAndSortAligned: Math.abs(filterRect.bottom - sortRect.bottom) <= 1,
				sortTextVisible: getComputedStyle(sort).webkitTextFillColor !== 'transparent',
			};
		});
		columns.push(layout.columns);
		assert.equal(layout.fits, true, `The category page overflows at ${width}px`);
		assert.ok(layout.sortHeight >= 44 && layout.limitHeight >= 44 && layout.sortTextVisible);
		assert.equal(layout.panelOpen, width === desktopWidth);
		if (width === desktopWidth) {
			assert.equal(layout.sidebar, true);
		} else {
			assert.ok(layout.chipsAboveControls && layout.filtersAndSortAligned && layout.filterHeight >= 44);
			const { inset, gridWidth, cardWidth, rowGap } = layout;
			assert.deepEqual({ inset, gridWidth, cardWidth, rowGap }, mobileLayouts.get(width), `Product-grid geometry at ${width}px`);
			if (width === mobileWidth) {
				assert.equal(layout.columnGap, '12px');
			}
		}
	}

	await resize(mobileWidth);
	await pressEnter(summarySelector);
	for (let attempt = 0; attempt < 20; attempt++) {
		if (await page(selector => document.activeElement.matches(selector), closeSelector)) break;
		await delay(50);
	}
	const openPanel = await page(() => {
		const panel = document.querySelector('.catalog-filters');
		const fields = [...panel.querySelectorAll('.catalog-filters__price input')];
		const [first, last] = fields.map(field => field.getBoundingClientRect());
		const totals = [...panel.querySelectorAll('.catalog-filters__categories small')];
		const close = panel.querySelector('.catalog-filters__close');
		document.querySelector('#input-price-min').value = '100.50';
		return {
			open: panel.open,
			insidePage: getComputedStyle(panel).position === 'static',
			focusMoved: document.activeElement === close,
			inputsFit: first.right <= last.left && last.right <= innerWidth,
			priceControlsHeight: Math.min(first.height, last.height),
			zeroInPanel: totals.some(total => total.textContent.trim() === '0'),
			chipCount: document.querySelectorAll('.catalog-category-chips a').length,
			categoryCount: totals.length,
		};
	});
	assert.equal(openPanel.open, true, 'Enter must open the native filter summary');
	assert.equal(openPanel.insidePage, true, 'Filters must open in the page flow');
	assert.equal(openPanel.focusMoved, true, 'Opening filters must focus the close control');
	assert.equal(openPanel.inputsFit, true, 'Both price controls must fit on the same line');
	assert.ok(openPanel.priceControlsHeight >= 44);
	assert.ok(openPanel.zeroInPanel && openPanel.chipCount < openPanel.categoryCount);

	const longLegend = await page(() => {
		const legend = document.querySelector('input[name="filter[]"]').closest('fieldset').querySelector('legend');
		const text = legend.textContent;
		legend.textContent = 'Дополнительные характеристики материалов и декоративных элементов ручной работы';
		const fits = document.documentElement.scrollWidth === document.documentElement.clientWidth
			&& legend.scrollWidth <= legend.clientWidth
			&& legend.getBoundingClientRect().height > parseFloat(getComputedStyle(legend).lineHeight);
		return { text, fits };
	});
	assert.equal(longLegend.fits, true);
	await page(text => {
		document.querySelector('input[name="filter[]"]').closest('fieldset').querySelector('legend').textContent = text;
	}, longLegend.text);

	await pressEnter(closeSelector);
	assert.equal(await page(selector => !document.querySelector('.catalog-filters').open
		&& document.activeElement.matches(selector), summarySelector), true);
	await pressEnter(summarySelector);
	assert.equal(await page(() => Number(document.querySelector('#input-price-min').value)), 100.50);

	const expectedFilters = await page(async () => {
		const panel = document.querySelector('.catalog-filters');
		const allCategories = [...panel.querySelectorAll('input[name=path]')];
		const next = allCategories.find(input => !input.checked && Number(input.closest('label').querySelector('small').textContent) > 0);
		const categoryName = next.closest('label').querySelector('span').textContent.trim();
		const categoryLink = [...document.querySelectorAll('.catalog-category-chips a')]
			.find(link => link.textContent.trim() === categoryName);
		const categoryHtml = new DOMParser().parseFromString(await (await fetch(categoryLink.href)).text(), 'text/html');
		const categoryAttributes = new Set([...categoryHtml.querySelectorAll('input[name="filter[]"]')].map(input => input.value));
		const attribute = [...panel.querySelectorAll('input[name="filter[]"]')]
			.find(input => !categoryAttributes.has(input.value));
		const group = attribute.closest('fieldset').querySelector('legend').textContent.trim();
		const name = attribute.closest('label').querySelector('span').textContent.trim();
		next.checked = true;
		panel.querySelector('#input-price-min').value = '0';
		panel.querySelector('#input-price-max').value = '1';
		panel.querySelector('input[value=out_of_stock]').checked = true;
		panel.querySelector('input[name=is_new]').checked = true;
		panel.querySelector('input[name=is_sale]').checked = true;
		attribute.checked = true;
		return { category: next.value, attribute: attribute.value, group, name };
	});
	await afterNavigation(() => page(() => document.querySelector('.catalog-filters__form').requestSubmit()),
		() => location.search.includes('price_max=1') && document.readyState === 'complete');

	const filtered = await page(() => {
		const query = new URL(location.href).searchParams;
		const panel = document.querySelector('.catalog-filters');
		return {
			query: Object.fromEntries(query),
			summary: [...document.querySelectorAll('#catalog-applied-filters li')].map(item => item.textContent.trim()),
			count: document.querySelector('#catalog-result-count').textContent.trim(),
			empty: document.querySelector('#catalog-empty-title').textContent.trim(),
			buttonText: panel.querySelector('summary').textContent.trim(),
			panelOpen: panel.open,
			selectedPath: panel.querySelector('input[name=path]:checked').value,
			selectedAttribute: panel.querySelector('input[name="filter[]"]:checked')?.value,
		};
	});
	assert.equal(filtered.query.path, expectedFilters.category);
	assert.equal(filtered.query.price_min, '0');
	assert.equal(filtered.query.price_max, '1');
	assert.equal(filtered.query.availability, 'out_of_stock');
	assert.equal(filtered.query.is_new, '1');
	assert.equal(filtered.query.is_sale, '1');
	assert.equal(filtered.query['filter[]'], expectedFilters.attribute);
	assert.equal(filtered.query.page, undefined);
	assert.equal(filtered.selectedPath, expectedFilters.category);
	assert.equal(filtered.selectedAttribute, expectedFilters.attribute);
	assert.ok(filtered.summary.includes('Цена от 0 до 1 ₽'));
	assert.ok(filtered.summary.includes('Под заказ или нет в наличии'));
	assert.ok(filtered.summary.includes('Новинки') && filtered.summary.includes('Скидки'));
	assert.ok(filtered.summary.includes(expectedFilters.group + ': ' + expectedFilters.name));
	assert.equal(filtered.summary.length, 5);
	assert.match(filtered.buttonText, /\(5\)$/);
	assert.equal(filtered.count, '0 товаров');
	assert.equal(filtered.empty, 'Ничего не найдено');
	assert.equal(filtered.panelOpen, false);
	const filteredUrl = await page(() => location.href);
	await navigate(client, filteredUrl);
	assert.deepEqual(await page(() => [...document.querySelectorAll('#catalog-applied-filters li')]
		.map(item => item.textContent.trim())), filtered.summary);

	const resetUrl = await page(() => document.querySelector('.catalog-empty > a').href);
	assert.equal(resetUrl, await page(() => document.querySelector('#catalog-applied-filters a').href));
	await navigate(client, resetUrl);
	assert.equal(await page(() => document.querySelector('.catalog-filters input[name=path]:checked').value), expectedFilters.category);
	assert.ok(await page(() => document.querySelectorAll('#product-category .catalog-card').length > 0));

	const pagedRoot = new URL(root);
	pagedRoot.searchParams.set('availability', 'in_stock');
	pagedRoot.searchParams.set('limit', '1');
	await navigate(client, pagedRoot.href);
	const pagination = await page(() => {
		const link = document.querySelector('.catalog-pagination a');
		const rect = link.getBoundingClientRect();
		return { href: link.href, width: rect.width, height: rect.height };
	});
	assert.ok(pagination.width >= 44 && pagination.height >= 44);
	await navigate(client, pagination.href);
	assert.equal(await page(() => new URL(location.href).searchParams.get('page')), '2');
	assert.equal(await page(() => new URL(location.href).searchParams.get('availability')), 'in_stock');
	assert.equal(await page(() => document.querySelector('input[name=path]:checked').value), rootPath);
	const sortQuery = await page(() => {
		const input = document.querySelector('#input-sort');
		const option = [...input.options].find(item => new URL(item.value).searchParams.get('sort') === 'p.price');
		return Object.fromEntries(new URL(option.value).searchParams);
	});
	await afterNavigation(() => page(() => {
		const input = document.querySelector('#input-sort');
		input.value = [...input.options].find(item => new URL(item.value).searchParams.get('sort') === 'p.price').value;
		input.dispatchEvent(new Event('change', { bubbles: true }));
	}), () => new URL(location.href).searchParams.get('sort') === 'p.price' && document.readyState === 'complete');
	assert.equal(sortQuery.page, undefined);
	assert.equal(sortQuery.availability, 'in_stock');
	assert.equal(sortQuery.limit, '1');
	await afterNavigation(() => page(() => {
		const input = document.querySelector('#input-limit');
		input.value = [...input.options].find(item => new URL(item.value).searchParams.get('limit') === '25').value;
		input.dispatchEvent(new Event('change', { bubbles: true }));
	}), () => new URL(location.href).searchParams.get('limit') === '25' && document.readyState === 'complete');
	const finalQuery = await page(() => Object.fromEntries(new URL(location.href).searchParams));
	assert.equal(finalQuery.sort, 'p.price');
	assert.equal(finalQuery.availability, 'in_stock');
	assert.equal(finalQuery.page, undefined);
	return { columns, empty: filtered.empty, resetPath: expectedFilters.category, longLegendFits: longLegend.fits };
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
	} else if (scenario === 'category') {
		process.stdout.write(JSON.stringify(await runCategoryControls(client)));
	} else if (scenario === 'cart') {
		await client.command('Emulation.setDeviceMetricsOverride', { width: 320, height: 900, deviceScaleFactor: 1, mobile: false });
		await navigate(client, new URL('?route=product/search&limit=100', targetUrl).href);
		const result = await evaluate(client, `(async () => {
			const waitFor = async (predicate, timeoutDetails) => {
				for (let attempt = 0; attempt < 100; attempt++) {
					if (predicate()) return;
					await new Promise(resolve => setTimeout(resolve, 100));
				}
				throw new Error('Timed out waiting for cart state' + (typeof timeoutDetails === 'function' ? ': ' + JSON.stringify(timeoutDetails()) : ''));
			};
			const totalGroups = total => (String(total).match(/[0-9]+/g) || []).map(Number);
			const sameTotalGroups = (left, right) => left.length >= 2 && left.length === right.length && left.every((value, index) => value === right[index]);
			const zeroTotalGroups = values => values.length >= 2 && values.every(value => value === 0);
			const ids = [...new Set(Array.from(document.querySelectorAll('[data-cart-add]'), item => item.getAttribute('data-cart-add')))];
			let selected = null;
			for (const id of ids) {
				const response = await fetch('index.php?route=checkout/cart/add', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams({product_id:id,quantity:'1'})});
				const data = await response.json();
				if (data.success) { selected = id; break; }
			}
			if (!selected) throw new Error('No available product without required options in the test catalog');
			const button = document.querySelector('#cart > button');
			const initialTotal = button.querySelector('#cart-total').textContent;
			const initialTotalGroups = totalGroups(initialTotal);
			if (!zeroTotalGroups(initialTotalGroups)) throw new Error('Cart test expected an empty starting total: ' + JSON.stringify({initialTotal, initialTotalGroups}));
			// A fresh product with the same options merges into the session cart.
			cart.add(selected, 1);
			await waitFor(() => document.querySelector('#site-notifications .cart-notice') && document.querySelector('#cart [data-cart-remove]') && button.querySelector('#cart-total')?.textContent !== initialTotal);
			const icon = button.querySelector(':scope > .site-action-icon');
			const iconVisible = !!icon && icon.getBoundingClientRect().width > 0 && getComputedStyle(icon).visibility === 'visible';
			const noticeVisible = document.querySelector('#site-notifications .cart-notice').getBoundingClientRect().height > 0;
			button.click();
			const remove = document.querySelector('#cart [data-cart-remove]');
			const rect = remove.getBoundingClientRect();
			const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
			const removeReachable = hit === remove || remove.contains(hit);
			if (!removeReachable) return {iconVisible, noticeVisible, removeReachable, removed: false, iconAfterRemove: false};
			let removeResponse = null;
			$(document).on('ajaxComplete.cartLifecycleTest', (event, xhr, settings) => {
				const route = new URL(settings.url, location.href).searchParams.get('route') || '';
				if (route !== 'checkout/cart/remove') return;
				let json = {};
				try { json = JSON.parse(xhr.responseText || '{}'); } catch {}
				removeResponse = {status: xhr.status, total: typeof json.total === 'string' ? json.total : ''};
			});
			remove.click();
			await waitFor(() => {
				const currentTotal = button.querySelector('#cart-total')?.textContent || '';
				const currentTotalGroups = totalGroups(currentTotal);
				const responseTotalGroups = totalGroups(removeResponse?.total || '');
				return removeResponse?.status === 200
					&& removeResponse.total !== ''
					&& currentTotal === removeResponse.total
					&& sameTotalGroups(initialTotalGroups, currentTotalGroups)
					&& zeroTotalGroups(responseTotalGroups)
					&& document.querySelectorAll('#cart [data-cart-remove]').length === 0
					&& !!document.querySelector('#cart .mini-cart__empty');
			}, () => ({
				initialTotal,
				currentTotal: button.querySelector('#cart-total')?.textContent || '',
				removeCount: document.querySelectorAll('#cart [data-cart-remove]').length,
				miniCartEmpty: !!document.querySelector('#cart .mini-cart__empty'),
				removeResponse,
			}));
			const after = button.querySelector(':scope > .site-action-icon');
			return {iconVisible, noticeVisible, removeReachable, removed: true, iconAfterRemove: !!after && after.getBoundingClientRect().width > 0};
		})()`);
		process.stdout.write(JSON.stringify(result));
	} else {
		const hero = await evaluate(client, `(() => {
			const media = document.querySelector('#common-home .sylora-hero__media');
			if (!media) throw new Error('Hero media container was not found.');

			const image = media.querySelector('.sylora-hero__fallback');
			const product = media.querySelector('.sylora-hero__product');
			const slider = media.querySelector('.sylora-hero__banner, .slideshow, .swiper-container, [data-slideshow]');
			const rect = image?.getBoundingClientRect();

			return {
				fallbackBranch: !!image && !product,
				imageLoaded: !!image && image.complete && image.naturalWidth > 0 && rect.width > 0 && rect.height > 0,
				objectFit: image ? getComputedStyle(image).objectFit : null,
				noSlider: !slider,
			};
		})()`);
		const productUrl = await evaluate(client, `(async () => { const searchUrl = new URL(location.href); searchUrl.search = '?route=product/search&limit=100'; const catalog = new DOMParser().parseFromString(await (await fetch(searchUrl)).text(), 'text/html'); const productId = catalog.querySelector('[data-wishlist-add]')?.getAttribute('data-wishlist-add'); if (!productId) throw new Error('A real catalog product was not found.'); const url = new URL(location.href); url.search = '?route=product/product&product_id=' + productId; return url.href; })()`);
		await navigate(client, productUrl);
		const product = await evaluate(client, `(() => { const media = document.querySelector('.product-page__media'); const image = document.querySelector('.product-gallery__main img'); const metaLabel = document.querySelector('.product-meta__label'); if (!media || !image || !metaLabel) throw new Error('Product visual nodes were not found.'); const mediaRect = media.getBoundingClientRect(); const imageRect = image.getBoundingClientRect(); const labelRect = metaLabel.getBoundingClientRect(); return { widthOccupancy: imageRect.width / mediaRect.width, objectFit: getComputedStyle(image).objectFit, metaLabelSingleLine: labelRect.height <= parseFloat(getComputedStyle(metaLabel).lineHeight) + 1 }; })()`);
		const categoryUrl = await evaluate(client, `(async () => { const searchUrl = new URL(location.href); searchUrl.search = '?route=product/search'; const searchDocument = new DOMParser().parseFromString(await (await fetch(searchUrl)).text(), 'text/html'); for (const option of searchDocument.querySelectorAll('select[name="category_id"] option:not([value="0"])')) { const url = new URL(searchUrl); url.searchParams.set('category_id', option.value); url.searchParams.set('limit', '100'); const categoryDocument = new DOMParser().parseFromString(await (await fetch(url)).text(), 'text/html'); if (categoryDocument.querySelectorAll('.catalog-card').length >= 3) return url.href; } throw new Error('A real category with at least three products was not found.'); })()`);
		await navigate(client, categoryUrl);
		const catalog = await evaluate(client, `(() => { const cards = Array.from(document.querySelectorAll('.catalog-card')).slice(0, 3); const media = cards.map(card => card.querySelector('.catalog-card__media')?.getBoundingClientRect()).filter(Boolean); const images = cards.map(card => card.querySelector('.catalog-card__image-primary')).filter(Boolean); const bottoms = cards.map(card => Math.round(card.querySelector('.catalog-card__actions')?.getBoundingClientRect().bottom || 0)); if (cards.length !== 3 || media.length !== 3 || images.length !== 3) throw new Error('Catalog visual nodes were not found.'); return { equalMedia: media.every(rect => Math.round(rect.width) === Math.round(media[0].width) && Math.round(rect.height) === Math.round(media[0].height)), maxMediaHeight: Math.max(...media.map(rect => rect.height)), objectFits: [...new Set(images.map(image => getComputedStyle(image).objectFit))], actionsAligned: bottoms.every(bottom => Math.abs(bottom - bottoms[0]) <= 1) }; })()`);
		await navigate(client, new URL('?route=product/search', targetUrl).href);
		const pagination = await evaluate(client, `(() => { const root = document.querySelector('.catalog-pagination .pagination'); const control = root?.querySelector('li:not(.active):not(.disabled) > a'); const active = root?.querySelector('.active > a, .active > span'); if (!root || !control || !active) return { exists: false }; const controlStyle = getComputedStyle(control); const activeStyle = getComputedStyle(active); const rect = control.getBoundingClientRect(); const probe = document.createElement('span'); probe.style.color = 'var(--color-accent)'; document.body.appendChild(probe); const accent = getComputedStyle(probe).color; probe.remove(); return { exists: true, minimumTouchTarget: rect.width >= 44 && rect.height >= 44, atelierCorners: parseFloat(controlStyle.borderTopLeftRadius) <= 3, activeUsesThemeAccent: activeStyle.backgroundColor === accent, notBootstrapBlue: activeStyle.backgroundColor !== 'rgb(51, 122, 183)' }; })()`);
		process.stdout.write(JSON.stringify({ hero, product, catalog, pagination }));
	}
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
