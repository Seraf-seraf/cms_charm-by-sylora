import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [, , targetUrl, scenarioValue, chromeBinary] = process.argv;
const scenario = scenarioValue || '';
const scenarios = ['search', 'add-to-cart', 'quantity-three', 'wishlist', 'card-actions', 'breadcrumbs', 'compare'];

if (
  !targetUrl
  || !scenarios.includes(scenario)
  || !chromeBinary
) {
  throw new Error(`Usage: product_actions_browser.mjs <url> <${scenarios.join('|')}> <chrome-binary>`);
}

const searchTerm = 'Розовый рассвет';
const productName = 'Серьги Розовый рассвет';
const profile = await mkdtemp(join(tmpdir(), 'sylora-product-actions-'));
const chrome = spawn(chromeBinary, ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-background-networking', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore' });
const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
const localOrigin = new URL(targetUrl).origin;
const blockedExternalRequests = [];
const interceptionErrors = [];
const interceptionTasks = [];
let rejectNextCartRequest = false;

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
  const listeners = new Map();
  let id = 0;
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
        const commandId = ++id;
        pending.set(commandId, message => message.error ? reject(new Error(message.error.message)) : resolve(message.result || {}));
        socket.send(JSON.stringify({ id: commandId, method, params }));
      });
    },
  };
}

async function settleInterceptionTasks() {
  let settledCount = 0;

  while (settledCount < interceptionTasks.length) {
    const currentCount = interceptionTasks.length;
    await Promise.all(interceptionTasks.slice(settledCount, currentCount));
    settledCount = currentCount;
  }
}

async function writeResult(result) {
  await client.command('Page.stopLoading');
  await settleInterceptionTasks();

  if (interceptionErrors.length > 0) {
    throw new Error(`External request blocking failed: ${interceptionErrors[0].message}`);
  }

  if (blockedExternalRequests.length > 0) {
    throw new Error('External browser requests were blocked: ' + Array.from(new Set(blockedExternalRequests)).join(', '));
  }

  process.stdout.write(JSON.stringify(result));
}

function installExternalRequestBlocker() {
  client.on('Fetch.requestPaused', params => {
    const task = (async () => {
      const requestOrigin = new URL(params.request.url).origin;

      if (requestOrigin === localOrigin) {
        if (rejectNextCartRequest && new URL(params.request.url).searchParams.get('route') === 'checkout/cart/add') {
          rejectNextCartRequest = false;
          await client.command('Fetch.failRequest', { requestId: params.requestId, errorReason: 'Failed' });
          return;
        }
        await client.command('Fetch.continueRequest', { requestId: params.requestId });
        return;
      }

      blockedExternalRequests.push(params.request.url);
      await client.command('Fetch.failRequest', { requestId: params.requestId, errorReason: 'BlockedByClient' });
    })();

    interceptionTasks.push(task.catch(error => {
      interceptionErrors.push(error);
    }));
  });
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

async function openSyntheticProduct(client) {
  const url = new URL('?route=product/search', targetUrl);
  url.searchParams.set('search', searchTerm);
  await navigate(client, url.href);

  const href = await evaluate(client, `(() => {
    const link = Array.from(document.querySelectorAll('.catalog-card__title a'))
      .find(item => item.textContent.trim() === ${JSON.stringify(productName)});
    return link?.href || '';
  })()`);

  if (!href) throw new Error(`Synthetic product "${productName}" was not found in search results.`);
  await navigate(client, href);

  const product = await evaluate(client, `(() => {
    const root = document.querySelector('#product-product[data-product-id]');
    const button = document.querySelector('#button-cart');
    const quantity = document.querySelector('#input-quantity');
    if (!root || !button || !quantity) throw new Error('Synthetic product page controls were not found.');
    return {
      id: root.getAttribute('data-product-id'),
      name: document.querySelector('#content h1')?.textContent.trim() || '',
      titleLink: document.querySelector('.product-breadcrumbs [aria-current="page"]')?.textContent.trim() || '',
    };
  })()`);

  if (!product.id || product.name !== productName) throw new Error('The opened page does not match the synthetic product.');
  return product;
}

async function addProductAndReadCart(client, quantityValue) {
  const product = await openSyntheticProduct(client);
  if (!await waitForBrowserState(`document.readyState === 'complete'`, 10000)) {
    throw new Error('The product page did not finish loading before the cart action.');
  }
  await evaluate(client, `(() => {
    const quantity = document.querySelector('#input-quantity');
    const button = document.querySelector('#button-cart');
    if (!quantity || !button) throw new Error('Product page add-to-cart control is unavailable.');
    quantity.value = ${JSON.stringify(String(quantityValue))};
    quantity.dispatchEvent(new Event('input', { bubbles: true }));
    quantity.dispatchEvent(new Event('change', { bubbles: true }));
  })()`);

  // The quantity request clears alerts when its response arrives.
  if (!await waitForBrowserState('window.jQuery.active === 0', 10000)) {
    throw new Error('The quantity update did not finish before the cart action.');
  }
  await evaluate(client, `document.querySelector('#button-cart').click()`);
  const notice = await evaluate(client, `(async () => {
    for (let attempt = 0; attempt < 100; attempt++) {
      const current = document.querySelector('#content .cart-notice[role="status"]');
      if (current) {
        const link = current.querySelector('a[href*="route=checkout/cart"]');
        return { visible: current.getBoundingClientRect().height > 0, text: current.textContent.trim(), href: link?.href || '' };
      }
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error('Product page did not show an add-to-cart notification.');
  })()`);

  if (!notice.visible || !notice.href) throw new Error('Add-to-cart notification does not expose the cart action.');
  await navigate(client, notice.href);

  return { ...await readCart(client), expectedProductId: product.id, expectedProductName: product.name, noticeVisible: notice.visible, noticeText: notice.text };
}

async function readCart(client) {
  return evaluate(client, `(() => {
    const item = document.querySelector('#checkout-cart .cart-item:not(.cart-item--voucher)');
    if (!item) throw new Error('The added product was not rendered in the cart.');
    const quantity = item.querySelector('input[name^="quantity["]');
    const productId = item.querySelector('[data-cart-remove][data-product-id]')?.getAttribute('data-product-id') || '';
    const name = item.querySelector('.cart-item__top h2 a')?.textContent.trim() || '';
    const unitPrice = item.querySelector('.cart-item__price strong')?.textContent || '';
    const lineTotal = item.querySelector('.cart-item__total strong')?.textContent || '';
    const digits = value => value.replace(/[^0-9]/g, '');
    return {
      productId,
      name,
      quantity: quantity?.value || '',
      unitPriceDigits: digits(unitPrice),
      lineTotalDigits: digits(lineTotal),
    };
  })()`);
}

async function pressKey(key, code, windowsVirtualKeyCode) {
  const keyEvent = { key, code, windowsVirtualKeyCode };
  const downEvent = key === 'Enter' ? { type: 'keyDown', text: '\r' } : { type: 'rawKeyDown' };
  await client.command('Input.dispatchKeyEvent', { ...keyEvent, ...downEvent });
  await client.command('Input.dispatchKeyEvent', { ...keyEvent, type: 'keyUp' });
}

async function clickCardControl(selector, click = true) {
  const point = await evaluate(client, `(() => {
    const button = document.querySelector(${JSON.stringify(selector)});
    button.scrollIntoView({ block: 'center' });
    const { x, y, width, height } = button.getBoundingClientRect();
    return { x: x + width / 2, y: y + height / 2 };
  })()`);
  await client.command('Input.dispatchMouseEvent', { ...point, type: 'mouseMoved' });
  if (click) {
    await client.command('Input.dispatchMouseEvent', { ...point, type: 'mousePressed', button: 'left', clickCount: 1 });
    await client.command('Input.dispatchMouseEvent', { ...point, type: 'mouseReleased', button: 'left', clickCount: 1 });
  }
}

async function waitForBrowserState(condition, timeoutMilliseconds = 1000) {
  const deadline = performance.now() + timeoutMilliseconds;
  while (performance.now() < deadline) {
    if (await evaluate(client, condition)) return true;
    await delay(50);
  }
  return false;
}

async function exerciseCardActions() {
  await client.command('Emulation.setDeviceMetricsOverride', { width: 320, height: 600, deviceScaleFactor: 1, mobile: false });
  const catalogLink = `[...document.querySelectorAll('#site-nav a')].find(link => link.textContent.trim() === 'Каталог')?.href`;
  if (!await waitForBrowserState(catalogLink, 10000)) throw new Error('The storefront catalog link was not found.');
  const catalogHref = await evaluate(client, catalogLink);
  const catalogUrl = new URL(catalogHref);
  catalogUrl.searchParams.set('limit', '100');
  await navigate(client, catalogUrl.href);
  if (!await waitForBrowserState(`document.readyState === 'complete' && document.querySelector('#product-category .catalog-card') !== null`, 10000)) {
    throw new Error('The catalog did not render synthetic product cards.');
  }
  const consentChoice = '#analytics-cookie-banner:not([hidden]) [data-cookie-consent="essential"]';
  if (await evaluate(client, `document.querySelector(${JSON.stringify(consentChoice)}) !== null`)) {
    await clickCardControl(consentChoice);
    if (!await waitForBrowserState(`document.querySelector(${JSON.stringify(consentChoice)}) === null`)) {
      throw new Error('Cookie banner still covers the catalog controls.');
    }
  }
  const product = await evaluate(client, `(() => {
    const card = [...document.querySelectorAll('.catalog-card')].find(card => card.querySelector('.catalog-card__title').textContent.trim() === ${JSON.stringify(productName)});
    const cart = card?.querySelector('.catalog-card__actions [data-cart-add]');
    if (!cart) throw new Error('Synthetic category card with cart button was not found.');
    card.dataset.testCard = '';
    return { id: cart.dataset.cartAdd, minimum: Number(cart.dataset.quantity), name: card.querySelector('.catalog-card__title').textContent.trim() };
  })()`);
  await evaluate(client, `document.querySelector('[data-test-card] .catalog-card__image').focus()`);
  await pressKey('Tab', 'Tab', 9);
  const wishlistTooltip = await evaluate(client, `(() => {
    const button = document.querySelector('[data-test-card] .catalog-card__wishlist');
    const tip = button.querySelector('.catalog-card__tooltip');
    return Boolean(document.activeElement === button && tip && getComputedStyle(tip).visibility === 'visible'
      && tip.getBoundingClientRect().top < button.getBoundingClientRect().top
      && tip.textContent.trim() === button.getAttribute('aria-label') && !button.hasAttribute('data-toggle'));
  })()`);

  const quick = '[data-test-card] .js-quick-view-open';
  await clickCardControl(quick, false);
  const quickViewTooltip = await evaluate(client, `(() => {
    const button = document.querySelector('${quick}');
    const tip = button.querySelector('.catalog-card__tooltip');
    return Boolean(tip && getComputedStyle(tip).visibility === 'visible'
      && tip.getBoundingClientRect().left >= 0 && tip.getBoundingClientRect().right <= innerWidth
      && tip.textContent.trim() === button.getAttribute('aria-label') && !button.hasAttribute('data-toggle'));
  })()`);

  await evaluate(client, `document.querySelector('${quick}').focus()`);
  await pressKey('Enter', 'Enter', 13);
  const keyboardOpened = await evaluate(client, `(() => {
    const button = document.querySelector('${quick}');
    const dialog = document.querySelector('[data-test-card] .quick-view');
    return dialog.matches(':modal') && button.getAttribute('aria-expanded') === 'true'
      && dialog.querySelector('.quick-view__close button') === document.activeElement;
  })()`);
  await pressKey('Escape', 'Escape', 27);
  const dialogClosed = `!document.querySelector('[data-test-card] .quick-view').open
    && document.activeElement === document.querySelector('${quick}')
    && document.activeElement.getAttribute('aria-expanded') === 'false'`;
  const keyboardDialog = keyboardOpened && await waitForBrowserState(dialogClosed);

  await clickCardControl(quick);
  const scrollableDialog = await evaluate(client, `(() => {
    const dialog = document.querySelector('[data-test-card] .quick-view');
    const actions = dialog.querySelector('.quick-view__actions');
    const dialogRect = dialog.getBoundingClientRect();
    dialog.scrollTop = dialog.scrollHeight;
    return dialog.open && dialog.scrollTop > 0
      && actions.getBoundingClientRect().bottom <= dialogRect.bottom
      && dialogRect.width <= innerWidth && dialogRect.height <= innerHeight;
  })()`);
  await evaluate(client, `document.querySelector('[data-test-card] .quick-view').scrollTop = 0`);
  await clickCardControl('[data-test-card] .quick-view__close button');
  const pointerDialog = await waitForBrowserState(dialogClosed);

  const wishlist = await evaluate(client, `(async () => {
    const button = document.querySelector('[data-test-card] .catalog-card__wishlist');
    const previousLabel = button.getAttribute('aria-label');
    button.click();
    for (let attempt = 0; attempt < 100; attempt++) {
      const notice = document.querySelector('#site-notifications [role="status"]');
      if (button.getAttribute('aria-pressed') === 'true' && !button.disabled && notice) {
        const label = button.getAttribute('aria-label');
        return {
          wishlistSelected: button.classList.contains('is-selected') && notice.getBoundingClientRect().height > 0,
          wishlistLabelUpdated: label !== previousLabel && label === button.querySelector('.catalog-card__tooltip')?.textContent.trim()
            && !button.hasAttribute('title') && !button.hasAttribute('data-original-title'),
        };
      }
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error('Wishlist result did not reach the card.');
  })()`);

  rejectNextCartRequest = true;
  const loading = await evaluate(client, `(() => {
    const button = document.querySelector('[data-test-card] .catalog-card__actions [data-cart-add]');
    window.alert = () => {};
    button.click();
    const label = button.querySelector('.catalog-card__cart-loading');
    return button.disabled && button.getAttribute('aria-busy') === 'true'
      && button.classList.contains('is-loading') && Boolean(label?.getBoundingClientRect().height);
  })()`);
  const restoredAfterError = await evaluate(client, `(async () => {
    const button = document.querySelector('[data-test-card] .catalog-card__actions [data-cart-add]');
    for (let attempt = 0; attempt < 100 && button.disabled; attempt++) {
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    return !button.disabled && !button.hasAttribute('aria-busy') && !button.classList.contains('is-loading');
  })()`);

  const notice = await evaluate(client, `(async () => {
    document.querySelector('[data-test-card] .catalog-card__actions [data-cart-add]').click();
    for (let attempt = 0; attempt < 100; attempt++) {
      const notice = document.querySelector('#site-notifications .cart-notice[role="status"]');
      if (notice) return notice.getBoundingClientRect().height > 0;
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    return false;
  })()`);
  await navigate(client, new URL('?route=checkout/cart', targetUrl).href);
  const cart = await readCart(client);
  await navigate(client, new URL('?route=product/search&search=' + encodeURIComponent(searchTerm), targetUrl).href);
  const detailsWithoutQuickView = await evaluate(client, `(() => {
    const card = document.querySelector('.catalog-card');
    return Boolean(card.querySelector('.price')?.textContent.trim()
      && card.querySelector('.catalog-card__more')?.href === card.querySelector('.catalog-card__title a')?.href
      && !card.querySelector('.js-quick-view-open, dialog'));
  })()`);
  return {
    ...cart, ...wishlist, wishlistTooltip, quickViewTooltip, keyboardDialog, pointerDialog, scrollableDialog,
    loading, restoredAfterError, detailsWithoutQuickView, minimum: product.minimum,
    expectedProductId: product.id, expectedProductName: product.name, noticeVisible: notice,
  };
}

let client;

try {
  client = await connect(await debuggingPort());
  installExternalRequestBlocker();
  await client.command('Network.enable');
  await client.command('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }] });
  await client.command('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
  await navigate(client, targetUrl);

  if (scenario === 'search') {
    const submitted = await evaluate(client, `(() => {
      const input = document.querySelector('header #search input[name="search"]');
      const button = document.querySelector('header #search button');
      const searchContainer = document.querySelector('header .site-search');
      const icon = button?.querySelector('img.atelier-icon--search[src$="search.svg"]');
      if (!input || !button || !searchContainer || !icon) throw new Error('Header search control or current search SVG was not found.');
      const initialUrl = location.href;
      button.click();
      const searchOpened = searchContainer.classList.contains('is-open')
        && button.getAttribute('aria-expanded') === 'true'
        && document.activeElement === input;
      if (!searchOpened || location.href !== initialUrl) throw new Error('Header search did not open before submitting.');
      input.value = ${JSON.stringify(searchTerm)};
      input.dispatchEvent(new Event('input', { bubbles: true }));
      button.click();
      return { value: input.value, icon: icon.getAttribute('src') || '', searchOpened };
    })()`);
    if (!submitted) throw new Error('Header search did not submit.');
    await delay(1500);
    const result = await evaluate(client, `(() => {
      const url = new URL(location.href);
      const matches = Array.from(document.querySelectorAll('.catalog-card__title a'))
        .filter(item => item.textContent.trim() === ${JSON.stringify(productName)});
      return {
        search: url.searchParams.get('search') || '',
        matchCount: matches.length,
        productName: matches[0]?.textContent.trim() || '',
        productHref: matches[0]?.href || '',
        productId: matches[0]?.closest('.catalog-card')?.querySelector('[data-wishlist-add]')?.getAttribute('data-wishlist-add') || '',
      };
    })()`);
    await writeResult({ ...result, searchIcon: submitted.icon, searchOpened: submitted.searchOpened });
  } else if (scenario === 'add-to-cart' || scenario === 'quantity-three') {
    const quantityValue = scenario === 'quantity-three' ? 3 : 1;
    const result = await addProductAndReadCart(client, quantityValue);
    await writeResult(result);
  } else if (scenario === 'wishlist') {
    const product = await openSyntheticProduct(client);
    const clicked = await evaluate(client, `(() => {
      const button = document.querySelector('#product-product button[data-wishlist-add]');
      const icon = button?.querySelector('img.atelier-icon--heart[src$="heart.svg"]');
      if (!button || !icon || button.getAttribute('data-wishlist-add') !== ${JSON.stringify(product.id)}) throw new Error('Product wishlist button or current heart SVG was not found.');
      button.click();
      return { icon: icon.getAttribute('src') || '', buttonProductId: button.getAttribute('data-wishlist-add') || '' };
    })()`);
    const result = await evaluate(client, `(async () => {
      for (let attempt = 0; attempt < 100; attempt++) {
        const button = document.querySelector('#product-product button[data-wishlist-add]');
        const notice = document.querySelector('#site-notifications [role="status"]');
        if (button?.getAttribute('aria-pressed') === 'true' && notice) {
          return {
            pressed: true,
            noticeVisible: notice.getBoundingClientRect().height > 0,
            noticeText: notice.textContent.trim(),
          };
        }
        await new Promise(resolve => setTimeout(resolve, 100));
      }
      throw new Error('Wishlist state or notification was not updated.');
    })()`);
    await writeResult({ ...result, productId: product.id, productName: product.name, buttonProductId: clicked.buttonProductId, heartIcon: clicked.icon });
  } else if (scenario === 'card-actions') {
    await writeResult(await exerciseCardActions());
  } else if (scenario === 'breadcrumbs') {
    const productUrl = new URL('?route=product/product', targetUrl);
    productUrl.searchParams.set('product_id', '1');
    productUrl.searchParams.set('path', '1');
    await navigate(client, productUrl.href);

    const productPage = await evaluate(client, `(() => {
      const root = document.querySelector('#product-product[data-product-id]');
      const links = Array.from(document.querySelectorAll('.product-breadcrumbs__item a'));
      return {
        productId: root?.getAttribute('data-product-id') || '',
        selectedPath: new URL(location.href).searchParams.get('path') || '',
        breadcrumbs: links.map(link => link.textContent.trim()),
        categoryHref: links[1]?.href || '',
        hasPhpError: /Warning:|Deprecated:|Fatal error|Array to string conversion/i.test(document.documentElement.outerHTML),
      };
    })()`);

    if (productPage.productId !== '1' || !productPage.categoryHref) {
      throw new Error('The product page for product_id=1&path=1 or its category breadcrumb was not rendered.');
    }

    const readPage = async url => evaluate(client, `(async () => {
      const response = await fetch(${JSON.stringify(url)}, { credentials: 'same-origin' });
      const html = await response.text();
      const page = new DOMParser().parseFromString(html, 'text/html');
      const root = page.querySelector('#product-product[data-product-id]');
      const links = Array.from(page.querySelectorAll('.product-breadcrumbs__item a'));
      return {
        status: response.status,
        productId: root?.getAttribute('data-product-id') || '',
        heading: page.querySelector('#content h1')?.textContent.trim() || '',
        breadcrumbs: links.map(link => link.textContent.trim()),
        hasPhpError: /Warning:|Deprecated:|Fatal error|Array to string conversion/i.test(html),
      };
    })()`);

    const categoryUrl = new URL(productPage.categoryHref);
    const withoutPathUrl = new URL('?route=product/product&product_id=1', targetUrl);
    const invalidScalarPathUrl = new URL('?route=product/product&product_id=1&path=1_not-a-category', targetUrl);
    const invalidArrayPathUrl = new URL('?route=product/product&product_id=1&path%5B%5D=1', targetUrl);
    const withoutPath = await readPage(withoutPathUrl.href);
    const invalidScalarPath = await readPage(invalidScalarPathUrl.href);
    const invalidArrayPath = await readPage(invalidArrayPathUrl.href);

    await writeResult({
      ...productPage,
      categoryName: productPage.breadcrumbs[1] || '',
      categorySelectionPreserved: (categoryUrl.searchParams.get('route') === 'product/category'
        && categoryUrl.searchParams.get('path') === '1') || categoryUrl.pathname.endsWith('/all-jewelry'),
      withoutPathStatus: withoutPath.status,
      withoutPathProductId: withoutPath.productId,
      withoutPathBreadcrumbs: withoutPath.breadcrumbs,
      withoutPathHasPhpError: withoutPath.hasPhpError,
      invalidScalarPathStatus: invalidScalarPath.status,
      invalidScalarPathBreadcrumbs: invalidScalarPath.breadcrumbs,
      invalidScalarPathHasPhpError: invalidScalarPath.hasPhpError,
      invalidArrayPathStatus: invalidArrayPath.status,
      invalidArrayPathHasPhpError: invalidArrayPath.hasPhpError,
    });
  } else {
    const product = await openSyntheticProduct(client);
    const action = await evaluate(client, `(() => {
      const add = window.compare?.add;
      if (typeof add !== 'function') throw new Error('Current compare client action is unavailable.');
      const controlCount = document.querySelectorAll('#product-product [data-compare-add]').length;
      add(${JSON.stringify(product.id)});
      return { productId: ${JSON.stringify(product.id)}, controlCount };
    })()`);
    if (!action.productId) throw new Error('Compare client action has no synthetic product ID.');
    const result = await evaluate(client, `(async () => {
      for (let attempt = 0; attempt < 100; attempt++) {
        const notice = document.querySelector('.alert.alert-success.alert-dismissible');
        if (notice && notice.textContent.includes(${JSON.stringify(productName)})) {
          return { notificationVisible: notice.getBoundingClientRect().height > 0, noticeText: notice.textContent.trim() };
        }
        await new Promise(resolve => setTimeout(resolve, 100));
      }
      throw new Error('Compare action did not show a success notification.');
    })()`);
    const compareUrl = new URL('?route=product/compare', targetUrl).href;
    await navigate(client, compareUrl);
    const compared = await evaluate(client, `(() => {
      const product = document.querySelector('#product-compare table tbody tr:first-child td:nth-child(2) a strong');
      const addButton = document.querySelector('#product-compare [data-cart-add]');
      return {
        comparedProductName: product?.textContent.trim() || '',
        comparedProductId: addButton?.getAttribute('data-cart-add') || '',
      };
    })()`);
    await writeResult({ ...action, ...result, ...compared, expectedProductName: product.name });
  }
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
