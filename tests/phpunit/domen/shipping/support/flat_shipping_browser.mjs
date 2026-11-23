import { spawn } from 'node:child_process';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve, sep } from 'node:path';

const [, , targetUrl, scenario, chromeBinary] = process.argv;

if (!targetUrl || scenario !== 'flat-shipping' || !chromeBinary) {
  throw new Error('Usage: flat_shipping_browser.mjs <loopback-url> flat-shipping <chrome-binary>');
}

const target = new URL(targetUrl);
const localOrigin = target.origin;
const SCREENSHOT_VIEWPORT_WIDTHS_PX = [320, 390, 1440];
const SCREENSHOT_VIEWPORT_HEIGHT_PX = 1000;
const SCREENSHOT_SETTLE_DELAY_MS = 150;
const screenshotDirectory = process.env.SYLORA_BROWSER_SCREENSHOT_DIR
  || await mkdtemp(join(tmpdir(), 'sylora-flat-shipping-capture-'));

if (target.protocol !== 'http:' || target.hostname !== '127.0.0.1' || !target.port) {
  throw new Error('Flat shipping browser tests may only use the isolated loopback storefront.');
}

if (screenshotDirectory && !resolve(screenshotDirectory).startsWith(resolve(tmpdir()) + sep)) {
  throw new Error('Shipping screenshots may only be written below the system temporary directory.');
}

const profile = await mkdtemp(join(tmpdir(), 'sylora-flat-shipping-profile-'));
const chrome = spawn(chromeBinary, [
  '--headless=new',
  '--no-sandbox',
  '--disable-gpu',
  '--disable-background-networking',
  '--disable-component-update',
  '--disable-sync',
  '--no-first-run',
  '--no-default-browser-check',
  '--no-proxy-server',
  '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1',
  '--remote-debugging-port=0',
  '--user-data-dir=' + profile,
  'about:blank',
], { stdio: 'ignore' });

const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
const requestRoutes = [];
const localResponses = [];
const externalRequests = [];
const externalResponses = [];
const blockedExternalRequests = [];
const unblockedExternalRequests = [];
const forbiddenRoutes = [];
const interceptionErrors = [];
const interceptionTasks = [];
const runtimeExceptions = [];
let client;
let report;
let runnerError;
let browserProcessExited = false;

const browserExit = new Promise(resolve => {
  chrome.once('exit', () => {
    browserProcessExited = true;
    resolve();
  });
});

chrome.once('error', error => {
  runnerError = error;
});

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

  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
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
      return new Promise((resolve, reject) => {
        const commandId = ++id;

        pending.set(commandId, message => {
          if (message.error) {
            reject(new Error(message.error.message));
            return;
          }

          resolve(message.result || {});
        });

        socket.send(JSON.stringify({ id: commandId, method, params }));
      });
    },
  };
}

function routeUrl(route) {
  const url = new URL(targetUrl);
  url.search = new URLSearchParams({ route }).toString();
  return url.href;
}

function forbiddenRoute(route) {
  return route === 'checkout/payment_method'
    || route === 'checkout/payment_method/save'
    || route === 'checkout/confirm'
    || route.startsWith('extension/payment/');
}

function installRequestGuards() {
  client.on('Network.requestWillBeSent', params => {
    const requestUrl = new URL(params.request.url);

    if (requestUrl.origin !== localOrigin) {
      externalRequests.push({ method: params.request.method, url: params.request.url });
      return;
    }

    const route = requestUrl.searchParams.get('route') || '';
    requestRoutes.push({ method: params.request.method, route });
  });

  client.on('Network.responseReceived', params => {
    const responseUrl = new URL(params.response.url);

    if (responseUrl.origin !== localOrigin) {
      externalResponses.push(params.response.url);
      return;
    }

    const route = responseUrl.searchParams.get('route') || '';

    if (route === 'checkout/cart/add') {
      localResponses.push({ route, status: params.response.status, mimeType: params.response.mimeType });
    }
  });

  client.on('Runtime.exceptionThrown', params => {
    const details = params.exceptionDetails || {};
    runtimeExceptions.push(details.exception?.description || details.text || 'Unknown browser runtime exception.');
  });

  client.on('Fetch.requestPaused', params => {
    const task = (async () => {
      const requestUrl = new URL(params.request.url);

      if (requestUrl.protocol !== 'http:' || requestUrl.origin !== localOrigin) {
        blockedExternalRequests.push({ method: params.request.method, url: params.request.url });
        await client.command('Fetch.failRequest', {
          requestId: params.requestId,
          errorReason: 'BlockedByClient',
        });
        return;
      }

      const route = requestUrl.searchParams.get('route') || '';

      if (forbiddenRoute(route)) {
        forbiddenRoutes.push({ method: params.request.method, route });
        await client.command('Fetch.failRequest', {
          requestId: params.requestId,
          errorReason: 'BlockedByClient',
        });
        return;
      }

      await client.command('Fetch.continueRequest', { requestId: params.requestId });
    })();

    interceptionTasks.push(task.catch(error => interceptionErrors.push(error.message)));
  });
}

async function settleInterceptionTasks() {
  let settledCount = 0;

  while (settledCount < interceptionTasks.length) {
    const currentCount = interceptionTasks.length;
    await Promise.all(interceptionTasks.slice(settledCount, currentCount));
    settledCount = currentCount;
  }
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
      // Poll again after document navigation replaces the execution context.
    }

    await delay(100);
  }

  throw new Error(message);
}

async function navigate(url, selector) {
  if (new URL(url).origin !== localOrigin) {
    throw new Error('The browser may only navigate to the isolated local storefront.');
  }

  await client.command('Page.navigate', { url });
  await waitFor(async () => (await evaluate('document.readyState')) === 'complete', 'The local route did not finish navigation.');
  await waitFor(async () => Boolean(await evaluate('document.querySelector(' + JSON.stringify(selector) + ')')), 'The local route did not render ' + selector + '.');
}

async function clickSelector(selector) {
  const rectangle = await evaluate(`(() => {
    const element = document.querySelector(${JSON.stringify(selector)});
    if (!element) return null;
    element.scrollIntoView({ block: 'center', inline: 'center' });
    const bounds = element.getBoundingClientRect();
    return { x: bounds.x, y: bounds.y, width: bounds.width, height: bounds.height, disabled: Boolean(element.disabled) };
  })()`);

  if (!rectangle || rectangle.disabled || rectangle.width < 1 || rectangle.height < 1) {
    throw new Error('Visible enabled control was not found: ' + selector);
  }

  const x = Math.round(rectangle.x + rectangle.width / 2);
  const y = Math.round(rectangle.y + rectangle.height / 2);

  await client.command('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y });
  await client.command('Input.dispatchMouseEvent', { type: 'mousePressed', button: 'left', clickCount: 1, x, y });
  await client.command('Input.dispatchMouseEvent', { type: 'mouseReleased', button: 'left', clickCount: 1, x, y });
}

async function submitForm(route, fields) {
  const url = routeUrl(route);
  const serializedFields = JSON.stringify(fields);

  return evaluate(`(async () => {
    const body = new URLSearchParams(${serializedFields});
    const response = await fetch(${JSON.stringify(url)}, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
    });
    const responseText = await response.text();
    let payload;
    try {
      payload = JSON.parse(responseText);
    } catch {
      payload = null;
    }
    return { status: response.status, responseText, payload };
  })()`);
}

async function capturePageScreenshot(widthPx) {
  const { cssContentSize } = await client.command('Page.getLayoutMetrics');
  const heightPx = Math.max(SCREENSHOT_VIEWPORT_HEIGHT_PX, Math.ceil(cssContentSize.height));

  return client.command('Page.captureScreenshot', {
    format: 'png',
    captureBeyondViewport: true,
    fromSurface: true,
    clip: { x: 0, y: 0, width: widthPx, height: heightPx, scale: 1 },
  });
}

async function captureSelectedShippingScreenshots() {
  await mkdir(screenshotDirectory, { recursive: true, mode: 0o700 });

  await client.command('Emulation.setDeviceMetricsOverride', {
    width: SCREENSHOT_VIEWPORT_WIDTHS_PX[0],
    height: SCREENSHOT_VIEWPORT_HEIGHT_PX,
    deviceScaleFactor: 1,
    mobile: false,
  });
  await navigate(routeUrl('checkout/checkout'), '#collapse-checkout-option.in #button-buyer-continue');
  await clickSelector('#collapse-checkout-option.in #button-buyer-continue');
  await waitFor(
    async () => Boolean(await evaluate('document.querySelector("#checkout-buyer-options:not([hidden])")')),
    'The full checkout page did not expand its buyer options for the synthetic guest.',
  );
  await clickSelector('#checkout-buyer-options input[name="account"][value="guest"]');
  await clickSelector('#checkout-buyer-options #button-account');
  await waitFor(
    async () => Boolean(await evaluate('document.querySelector("#collapse-payment-address.in #button-guest")')),
    'The full checkout page did not load its guest address form.',
  );
  await clickSelector('#collapse-payment-address.in #button-guest');
  await waitFor(
    async () => await evaluate(`
      Boolean(document.querySelector('#collapse-shipping-method.in [data-shipping-methods] input[name="shipping_method"][value="flat.flat"]'))
      && !document.querySelector('#collapse-checkout-option.in')
    `),
    'The full checkout page did not open its delivery step with the flat shipping method.',
  );

  if (!await evaluate(`Boolean(document.querySelector('#collapse-shipping-method input[name="shipping_method"][value="flat.flat"]:checked'))`)) {
    await clickSelector('#collapse-shipping-method input[name="shipping_method"][value="flat.flat"]');
    await waitFor(
      async () => await evaluate('document.querySelector("#collapse-shipping-method input[name=shipping_method]:checked")?.value === "flat.flat"'),
      'The flat shipping method was not selected in the full checkout page.',
    );
  }

  await client.command('Emulation.setScrollbarsHidden', { hidden: true });

  await evaluate('document.fonts?.ready');

  const screenshots = [];

  for (const widthPx of SCREENSHOT_VIEWPORT_WIDTHS_PX) {
    if (widthPx !== SCREENSHOT_VIEWPORT_WIDTHS_PX[0]) {
      await client.command('Emulation.setDeviceMetricsOverride', {
        width: widthPx,
        height: SCREENSHOT_VIEWPORT_HEIGHT_PX,
        deviceScaleFactor: 1,
        mobile: false,
      });
    }

    await delay(SCREENSHOT_SETTLE_DELAY_MS);
    await evaluate('window.scrollTo(0, 0)');

    const state = await evaluate(`(() => ({
      selectedCode: document.querySelector('input[name="shipping_method"]:checked')?.value || '',
      checkoutTitle: document.querySelector('#checkout-checkout h1')?.textContent.trim() || '',
      shippingMethodTitle: document.querySelector('#collapse-shipping-method .shipping-method-card__title')?.textContent.trim() || '',
      priceText: document.querySelector('#collapse-shipping-method .shipping-method-card__price')?.textContent.trim() || '',
      shippingPanelExpanded: Boolean(document.querySelector('#collapse-shipping-method')?.classList.contains('in')),
      buyerPanelExpanded: Boolean(document.querySelector('#collapse-checkout-option')?.classList.contains('in')),
      panelExpansionMode: 'application',
      viewportWidth: window.innerWidth,
      documentWidth: document.documentElement.scrollWidth,
      bodyWidth: document.body.scrollWidth,
      stylesheetHref: document.querySelector('link[rel="stylesheet"][href*="stylesheet.min.css"]')?.getAttribute('href') || '',
      compactFooter: Boolean(document.querySelector('.site-footer--checkout')),
      footerTitle: document.querySelector('.site-footer__checkout-brand h2')?.textContent.trim() || '',
      footerNavigationText: document.querySelector('.site-footer__details > summary')?.textContent.trim() || '',
      footerColumnCount: document.querySelectorAll('.site-footer--checkout .site-footer__grid .site-footer__column').length,
    }))()`);

    if (
      state.selectedCode !== 'flat.flat'
      || state.checkoutTitle.length === 0
      || state.shippingMethodTitle !== 'Фиксированная стоимость доставки'
      || state.priceText !== '350.00 ₽'
      || !state.shippingPanelExpanded
      || state.viewportWidth !== widthPx
      || state.documentWidth > widthPx
      || state.bodyWidth > widthPx
      || !state.stylesheetHref.includes('stylesheet.min.css')
    ) {
      throw new Error('The selected shipping state is not ready for a clean responsive screenshot: ' + JSON.stringify({ widthPx, state }));
    }

    const screenshot = await capturePageScreenshot(widthPx);
    const path = join(screenshotDirectory, 'flat-shipping-selected-' + widthPx + '.png');
    const image = Buffer.from(screenshot.data, 'base64');

    await writeFile(path, image);
    screenshots.push({
      widthPx,
      imageWidth: image.readUInt32BE(16),
      imageHeight: image.readUInt32BE(20),
      path,
      ...state,
    });
  }

  const cartScreenshots = [];

  for (const widthPx of SCREENSHOT_VIEWPORT_WIDTHS_PX) {
    await client.command('Emulation.setDeviceMetricsOverride', {
      width: widthPx,
      height: SCREENSHOT_VIEWPORT_HEIGHT_PX,
      deviceScaleFactor: 1,
      mobile: false,
    });
    await navigate(routeUrl('checkout/cart'), '#checkout-cart');
    await evaluate('document.fonts?.ready');
    await delay(SCREENSHOT_SETTLE_DELAY_MS);
    await evaluate('window.scrollTo(0, 0)');

    const state = await evaluate(`(() => ({
      cartTitle: document.querySelector('#checkout-cart h1')?.textContent.trim() || '',
      cartItemsCount: document.querySelectorAll('#checkout-cart .cart-item:not(.cart-item--voucher)').length,
      viewportWidth: window.innerWidth,
      documentWidth: document.documentElement.scrollWidth,
      bodyWidth: document.body.scrollWidth,
      stylesheetHref: document.querySelector('link[rel="stylesheet"][href*="stylesheet.min.css"]')?.getAttribute('href') || '',
      compactFooter: Boolean(document.querySelector('.site-footer--checkout')),
      footerTitle: document.querySelector('.site-footer__checkout-brand h2')?.textContent.trim() || '',
      footerNavigationText: document.querySelector('.site-footer__details > summary')?.textContent.trim() || '',
      footerColumnCount: document.querySelectorAll('.site-footer--checkout .site-footer__grid .site-footer__column').length,
    }))()`);

    if (
      state.cartTitle.length === 0
      || state.cartItemsCount < 1
      || state.viewportWidth !== widthPx
      || state.documentWidth > widthPx
      || state.bodyWidth > widthPx
      || !state.stylesheetHref.includes('stylesheet.min.css')
      || !state.compactFooter
    ) {
      throw new Error('The cart footer is not ready for a clean responsive screenshot: ' + JSON.stringify({ widthPx, state }));
    }

    const screenshot = await capturePageScreenshot(widthPx);
    const path = join(screenshotDirectory, 'cart-footer-' + widthPx + '.png');
    const image = Buffer.from(screenshot.data, 'base64');

    await writeFile(path, image);
    cartScreenshots.push({
      widthPx,
      imageWidth: image.readUInt32BE(16),
      imageHeight: image.readUInt32BE(20),
      path,
      ...state,
    });
  }

  return { shippingScreenshots: screenshots, cartScreenshots };
}

async function stopBrowser() {
  if (client) {
    client.close();
  }

  if (!browserProcessExited && chrome.exitCode === null && chrome.signalCode === null) {
    chrome.kill('SIGTERM');
    await Promise.race([browserExit, delay(2000)]);
  }

  if (!browserProcessExited && chrome.exitCode === null && chrome.signalCode === null) {
    chrome.kill('SIGKILL');
    await Promise.race([browserExit, delay(2000)]);
  }

  await rm(profile, { recursive: true, force: true, maxRetries: 20, retryDelay: 250 });

  return {
    browserProcessExited,
    profileRemoved: true,
  };
}

try {
  client = await connect(await debuggingPort());
  installRequestGuards();
  await client.command('Network.enable');
  await client.command('Network.setBypassServiceWorker', { bypass: true });
  await client.command('Page.enable');
  await client.command('Runtime.enable');
  await client.command('Emulation.setDeviceMetricsOverride', {
    width: 1440,
    height: 1000,
    deviceScaleFactor: 1,
    mobile: false,
  });
  const cookie = await client.command('Network.setCookie', {
    name: 'sylora_cookie_consent',
    value: 'v1%3Aessential',
    url: targetUrl,
    path: '/',
    sameSite: 'Lax',
  });

  if (!cookie.success) {
    throw new Error('Could not set the synthetic essential-only consent cookie.');
  }

  await client.command('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }] });

  // Request interception is active before the first storefront navigation.
  await navigate(targetUrl, 'body');

  const searchUrl = new URL(routeUrl('product/search'));
  searchUrl.searchParams.set('search', 'Розовый рассвет');
  await navigate(searchUrl.href, '.catalog-card');

  const product = await evaluate(`(() => {
    const card = Array.from(document.querySelectorAll('.catalog-card'))
      .find(item => item.querySelector('.catalog-card__title a')?.textContent.trim() === 'Серьги Розовый рассвет');
    const button = card?.querySelector('[data-cart-add]');
    return {
      id: button?.getAttribute('data-cart-add') || '',
      name: card?.querySelector('.catalog-card__title a')?.textContent.trim() || '',
      enabled: Boolean(button && !button.disabled),
      quantity: button?.getAttribute('data-quantity') || '',
    };
  })()`);

  if (!product.id || product.name !== 'Серьги Розовый рассвет' || !product.enabled || product.quantity !== '1') {
    throw new Error('The expected synthetic physical product is not available as a quantity-one catalog item.');
  }

  await clickSelector('.catalog-card [data-cart-add="' + product.id + '"]');
  try {
    await waitFor(
      async () => await evaluate('Boolean(document.querySelector("#site-notifications .cart-notice"))'),
      'The storefront did not show its cart-add notice.'
    );
  } catch (error) {
    const diagnostics = await evaluate(`(() => {
      const host = document.querySelector('#site-notifications');
      const button = document.querySelector('.catalog-card [data-cart-add="${product.id}"]');
      return {
        url: location.href,
        notificationHtml: host?.innerHTML || '',
        buttonClass: button?.className || '',
        buttonBusy: button?.getAttribute('aria-busy') || '',
        jqueryAvailable: typeof window.jQuery === 'function',
        activeAjaxRequests: window.jQuery?.active ?? null,
      };
    })()`);
    const screenshot = await client.command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    const screenshotPath = process.env.SYLORA_BROWSER_DEBUG_SCREENSHOT;

    if (screenshotPath) {
      await writeFile(screenshotPath, Buffer.from(screenshot.data, 'base64'));
    }

    throw new Error(error.message + ' ' + JSON.stringify({ diagnostics, localResponses, runtimeExceptions, screenshotSaved: Boolean(screenshotPath) }));
  }
  await navigate(routeUrl('checkout/cart'), '#checkout-cart .cart-item:not(.cart-item--voucher)');

  const cartProduct = await evaluate(`(() => {
    const row = document.querySelector('#checkout-cart .cart-item:not(.cart-item--voucher)');
    const visible = element => Boolean(element && element.getBoundingClientRect().width && element.getBoundingClientRect().height);
    return {
      id: row?.querySelector('[data-cart-remove][data-product-id]')?.getAttribute('data-product-id') || '',
      name: row?.querySelector('.cart-item__top h2 a')?.textContent.trim() || '',
      model: row?.querySelector('.cart-item__model')?.textContent.replace(/^.*?:\s*/, '').trim() || '',
      quantity: row?.querySelector('input[name^="quantity["]')?.value || '',
      visible: visible(row),
    };
  })()`);

  if (cartProduct.id !== product.id || cartProduct.name !== product.name || cartProduct.model !== 'SYLORA-TEST-001' || cartProduct.quantity !== '1' || !cartProduct.visible) {
    throw new Error('The same browser session cart does not contain the expected synthetic physical product.');
  }

  const guestFields = {
    firstname: 'Тест',
    lastname: 'Покупатель',
    email: 'flat-shipping@example.invalid',
    telephone: '+7 900 000-00-01',
    company: '',
    address_1: 'Тестовая улица, 1',
    address_2: '',
    postcode: '',
    city: 'Москва',
    country_id: '176',
    zone_id: '2769',
    shipping_address: '1',
  };
  const guestSaveResponse = await submitForm('checkout/guest/save', guestFields);
  const guestSave = {
    status: guestSaveResponse.status,
    saved: guestSaveResponse.status === 200
      && guestSaveResponse.payload !== null
      && typeof guestSaveResponse.payload === 'object'
      && Object.keys(guestSaveResponse.payload).length === 0,
    fields: guestFields,
    responseText: guestSaveResponse.responseText,
  };

  if (!guestSave.saved) {
    throw new Error('The штатный checkout/guest/save route rejected the synthetic shipping address: ' + guestSave.responseText);
  }

  await navigate(routeUrl('checkout/guest'), '#input-payment-firstname');

  const guestAddressState = await evaluate(`(() => {
    const value = selector => document.querySelector(selector)?.value || '';
    return {
      firstname: value('#input-payment-firstname'),
      lastname: value('#input-payment-lastname'),
      email: value('#input-payment-email'),
      telephone: value('#input-payment-telephone'),
      address1: value('#input-payment-address-1'),
      address2: value('#input-payment-address-2'),
      postcode: value('#input-payment-postcode'),
      city: value('#input-payment-city'),
      countryId: value('#input-payment-country'),
      shippingAddress: Boolean(document.querySelector('input[name="shipping_address"]')?.checked),
    };
  })()`);

  const expectedGuestAddressState = {
    firstname: 'Тест',
    lastname: 'Покупатель',
    email: 'flat-shipping@example.invalid',
    telephone: '+7 900 000-00-01',
    address1: 'Тестовая улица, 1',
    address2: '',
    postcode: '',
    city: 'Москва',
    countryId: '176',
    shippingAddress: true,
  };

  if (JSON.stringify(guestAddressState) !== JSON.stringify(expectedGuestAddressState)) {
    throw new Error('The штатный checkout/guest route did not render the saved guest address: ' + JSON.stringify(guestAddressState));
  }

  await navigate(routeUrl('checkout/shipping_method'), '[data-shipping-methods]');

  const shippingMethod = await evaluate(`(() => {
    const radio = document.querySelector('input[name="shipping_method"][value="flat.flat"]');
    const card = radio?.closest('.shipping-method-card');
    const group = radio?.closest('.shipping-methods__group');
    const visible = element => Boolean(element && element.getBoundingClientRect().width && element.getBoundingClientRect().height);
    return {
      code: radio?.value || '',
      groupTitle: group?.querySelector('.shipping-methods__title')?.textContent.trim() || '',
      quoteTitle: card?.querySelector('.shipping-method-card__title')?.textContent.trim() || '',
      priceText: card?.querySelector('.shipping-method-card__price')?.textContent.replace(/\\s+/g, ' ').trim() || '',
      radioVisible: visible(radio),
      selectedBeforeSave: Boolean(radio?.checked),
    };
  })()`);

  if (
    shippingMethod.code !== 'flat.flat'
    || shippingMethod.groupTitle !== 'Фиксированный тариф'
    || shippingMethod.quoteTitle !== 'Фиксированная стоимость доставки'
    || shippingMethod.priceText !== '350.00 ₽'
    || !shippingMethod.radioVisible
  ) {
    throw new Error('The real checkout/shipping_method route did not render the local flat.flat quote at 350.00 ₽: ' + JSON.stringify(shippingMethod));
  }

  await clickSelector('input[name="shipping_method"][value="flat.flat"]');

  const selectedBeforeSave = await evaluate(`Boolean(document.querySelector('input[name="shipping_method"][value="flat.flat"]')?.checked)`);

  if (!selectedBeforeSave) {
    throw new Error('The flat.flat radio was not selected in the shipping-method UI.');
  }

  const shippingSaveResponse = await submitForm('checkout/shipping_method/save', {
    shipping_method: 'flat.flat',
    comment: '',
  });
  const shippingMethodSave = {
    status: shippingSaveResponse.status,
    saved: shippingSaveResponse.status === 200
      && shippingSaveResponse.payload !== null
      && typeof shippingSaveResponse.payload === 'object'
      && Object.keys(shippingSaveResponse.payload).length === 0,
    responseText: shippingSaveResponse.responseText,
  };

  if (!shippingMethodSave.saved) {
    throw new Error('The штатный checkout/shipping_method/save route rejected flat.flat: ' + shippingSaveResponse.responseText);
  }

  await navigate(routeUrl('checkout/shipping_method'), '[data-shipping-methods]');

  const shippingMethodAfterSave = await evaluate(`(() => {
    const radio = document.querySelector('input[name="shipping_method"][value="flat.flat"]');
    return {
      selectedCode: radio?.checked ? radio.value : '',
      radioChecked: Boolean(radio?.checked),
    };
  })()`);

  const relevantRoutes = new Set([
    'checkout/cart/add',
    'checkout/cart',
    'checkout/guest/save',
    'checkout/guest',
    'checkout/shipping_method',
    'checkout/shipping_method/save',
  ]);
  const requiredRouteSequence = requestRoutes
    .map(request => request.route)
    .filter(route => relevantRoutes.has(route));
  const expectedRouteSequence = [
    'checkout/cart/add',
    'checkout/cart',
    'checkout/guest/save',
    'checkout/guest',
    'checkout/shipping_method',
    'checkout/shipping_method/save',
    'checkout/shipping_method',
  ];

  if (requiredRouteSequence.join(',') !== expectedRouteSequence.join(',')) {
    throw new Error('The browser did not follow the required product-to-shipping route sequence: ' + JSON.stringify({ requiredRouteSequence, requestRoutes }));
  }

  const screenshots = await captureSelectedShippingScreenshots();

  await settleInterceptionTasks();

  const blockedExternalUrls = new Set(blockedExternalRequests.map(request => request.url));
  unblockedExternalRequests.push(...externalRequests.filter(request => {
    const protocol = new URL(request.url).protocol;

    return (protocol === 'http:' || protocol === 'https:') && !blockedExternalUrls.has(request.url);
  }));

  if (externalResponses.length > 0 || unblockedExternalRequests.length > 0) {
    throw new Error('A request escaped the CDP default-deny guard: ' + JSON.stringify({ externalResponses, unblockedExternalRequests }));
  }

  if (forbiddenRoutes.length > 0 || interceptionErrors.length > 0) {
    throw new Error('A forbidden checkout route or request-interception error was observed: ' + JSON.stringify({ forbiddenRoutes, interceptionErrors }));
  }

  report = {
    scenario,
    product: cartProduct,
    guestAddressSave: guestSave,
    guestAddressState,
    shippingMethod: { ...shippingMethod, selectedBeforeSave },
    shippingMethodSave,
    shippingMethodAfterSave,
    ...screenshots,
    requestRoutes,
    localResponses,
    requiredRouteSequence,
    externalRequests,
    externalResponses,
    blockedExternalRequests,
    unblockedExternalRequests,
    forbiddenRoutes,
    interceptionErrors,
  };
} catch (error) {
  runnerError = error;
} finally {
  const cleanup = await stopBrowser();

  if (report) {
    report.cleanup = cleanup;
  }

  if (runnerError) {
    console.error(JSON.stringify({
      error: runnerError.message,
      requestRoutes,
      localResponses,
      externalRequests,
      externalResponses,
      blockedExternalRequests,
      unblockedExternalRequests,
      forbiddenRoutes,
      interceptionErrors,
      runtimeExceptions,
      cleanup,
    }));
    process.exitCode = 1;
  } else {
    console.log(JSON.stringify(report));
  }
}
